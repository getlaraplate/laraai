<?php

declare(strict_types=1);

use Elastic\Elasticsearch\Client;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Ai\Rag\Retrieval\DocumentationLocalePreference;
use Modules\AI\Ai\Rag\Retrieval\DocumentationRetrievalContext;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Tests\Stubs\Documentation\FakeDocumentationSearch;
use NeuronAI\RAG\Document;

beforeEach(function (): void {
    config()->set('ai.features.faq.max_documents', 5);
    config()->set('ai.features.faq.policy_classification_version', 'in-app-docs-v1');
});

/**
 * @param  list<string>  $permissions
 */
function fallback_access(string $locale, array $permissions = []): AssistantAccessContext
{
    return new AssistantAccessContext(
        profile: AssistantProfile::InAppAssistance,
        userId: 'u1',
        tenantScope: AssistantTenantScope::Global,
        tenantId: null,
        locale: $locale,
        effectivePermissions: $permissions,
        conversationId: 'c1',
    );
}

/**
 * @param  list<string>  $requirements
 */
function fallback_guide(string $locale, string $source = 'core/record-locking', string $text = 'Body.', array $requirements = []): Document
{
    return FakeDocumentationSearch::document(
        label: 'Core · Guide ' . $source,
        locale: $locale,
        content: $text . ' (' . $locale . ')',
        breadcrumb: ['Core', 'Guide'],
        requiredPermissions: $requirements,
        canonicalSource: $source,
    );
}

/**
 * @param  list<Document>  $ranked
 * @return list<string>
 */
function fallback_locales(array $ranked, string $locale): array
{
    $retrieval = FakeDocumentationSearch::forInAppRetrieval(['q' => $ranked]);

    return array_map(
        static fn (Document $document): string => $document->getMetadata()['locale'],
        $retrieval->retrieve('q', fallback_access($locale)),
    );
}

it('puts the caller locale and English in the locale filter', function (): void {
    $context = DocumentationRetrievalContext::fromAccessContext(fallback_access('it'));
    $clause = collect($context->elasticsearchFilter('in-app-docs-v1')['bool']['filter'])
        ->first(static fn (array $c): bool => isset($c['terms']['metadata.locale']));

    expect($context->acceptedLocales())->toBe(['it', 'en'])
        ->and($clause['terms']['metadata.locale'])->toBe(['it', 'en']);
});

it('keeps a single locale for an English caller', function (): void {
    $context = DocumentationRetrievalContext::fromAccessContext(fallback_access('en'));

    expect($context->acceptedLocales())->toBe(['en']);
});

it('returns an English-only guide to a caller whose locale has no translation', function (): void {
    expect(fallback_locales([fallback_guide('en')], 'it'))->toBe(['en']);
});

it('never admits a third locale', function (): void {
    expect(fallback_locales([fallback_guide('de')], 'it'))->toBe([])
        ->and(fallback_locales([fallback_guide('it')], 'de'))->toBe([]);
});

it('keeps the permission rule on a guide shown through the fallback', function (): void {
    $guide = fn (): array => [fallback_guide('en', requirements: ['core_settings.update'])];
    $retrieve = static fn (array $permissions): array => FakeDocumentationSearch::forInAppRetrieval(['q' => $guide()])
        ->retrieve('q', fallback_access('it', $permissions));

    expect($retrieve([]))->toBe([])
        ->and($retrieve(['default.core_settings.update']))->toHaveCount(1);
});

it('returns only the translation when a guide is retrieved in both locales', function (): void {
    $ranked = [fallback_guide('en'), fallback_guide('it')];

    expect(fallback_locales($ranked, 'it'))->toBe(['it']);
});

it('drops every English chunk of a document that has a translated chunk', function (): void {
    $ranked = [fallback_guide('en', text: 'First'), fallback_guide('it', text: 'Primo'), fallback_guide('en', text: 'Second')];

    expect(fallback_locales($ranked, 'it'))->toBe(['it']);
});

it('keeps an English guide beside a translated one when they are different documents', function (): void {
    $ranked = [fallback_guide('it', 'core/record-locking'), fallback_guide('en', 'core/search-matching')];

    expect(fallback_locales($ranked, 'it'))->toBe(['it', 'en']);
});

it('does not merge two documents that share a source in different modules', function (): void {
    $other_module = FakeDocumentationSearch::document(
        label: 'AI · Guide', locale: 'en', content: 'Body.', breadcrumb: ['AI'], module: 'ai', canonicalSource: 'core/record-locking',
    );
    $ranked = [fallback_guide('it'), $other_module];

    expect(fallback_locales($ranked, 'it'))->toBe(['it', 'en']);
});

it('keeps the search order of what it returns', function (): void {
    $ranked = [fallback_guide('en', 'core/a'), fallback_guide('it', 'core/b'), fallback_guide('en', 'core/c')];

    $retrieval = FakeDocumentationSearch::forInAppRetrieval(['q' => $ranked]);
    $sources = array_map(
        static fn (Document $document): string => $document->getSourceName(),
        $retrieval->retrieve('q', fallback_access('it')),
    );

    expect($sources)->toBe(['Core · Guide core/a', 'Core · Guide core/b', 'Core · Guide core/c']);
});

it('drops nothing for an English caller', function (): void {
    expect(fallback_locales([fallback_guide('en', text: 'First'), fallback_guide('en', text: 'Second')], 'en'))->toBe(['en', 'en']);
});

it('never drops a document that has no canonical source', function (): void {
    $without = fallback_guide('en');
    $metadata = $without->getMetadata();
    unset($metadata['canonical_source']);
    $without->setMetadata($metadata);

    $kept = DocumentationLocalePreference::apply([fallback_guide('it'), $without], 'it');

    expect($kept)->toHaveCount(2);
});

it('reads a document by key under the same two-locale filter, so an English page opens for an Italian caller', function (): void {
    config()->set('ai.features.faq.elasticsearch.user_index', 'user_docs');

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('search')
        ->once()
        ->with(Mockery::on(static function (array $params): bool {
            $encoded = json_encode($params['body']['query']['bool']['filter']);

            return str_contains($encoded, '"metadata.locale":["it","en"]')
                && str_contains($encoded, '"metadata.document_key":"d_' . str_repeat('a', 32) . '"');
        }))
        ->andReturn(make_elasticsearch_response(['hits' => ['hits' => []], 'aggregations' => ['last_section' => ['value' => null]]]));

    $store = new ElasticsearchRagVectorStore(client: $client, indexProfile: DocumentationIndexProfile::User, topK: 5, embedding_dims: 3);
    $context = DocumentationRetrievalContext::fromAccessContext(fallback_access('it'));

    expect($store->chunksOfDocument('d_' . str_repeat('a', 32), $context)['documents'])->toBe([]);
});
