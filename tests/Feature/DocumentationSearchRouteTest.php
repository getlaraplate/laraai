<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Http\Controllers\DocumentationSearchController;
use Modules\AI\Services\Documentation\Search\DocumentationPassage;
use Modules\AI\Services\Documentation\Search\DocumentationSearch;
use Modules\Core\Http\Controllers\CrudController;
use Modules\Core\Models\DynamicEntity;
use Modules\Core\Models\User;
use Nwidart\Modules\Facades\Module;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    config(['ai.features.faq.enabled' => true]);
});

/**
 * Binds a documentation search that answers with the given passages, or throws the given error.
 *
 * @param  list<DocumentationPassage>|Throwable  $answer
 */
function fakeDocumentationSearch(array|Throwable $answer = []): Mockery\MockInterface
{
    $search = Mockery::mock(DocumentationSearch::class);
    $expectation = $search->shouldReceive('search');
    $answer instanceof Throwable ? $expectation->andThrow($answer) : $expectation->andReturn($answer);
    app()->instance(DocumentationSearch::class, $search);

    return $search;
}

function documentationSearchUrl(string $module = 'cms', ?string $query = 'approve'): string
{
    $url = '/app/crud/search/' . $module . '/documentation';

    return $query === null ? $url : $url . '?' . http_build_query(['q' => $query]);
}

it('refuses an anonymous call and the guest', function (): void {
    $search = fakeDocumentationSearch();

    $this->getJson(documentationSearchUrl())->assertUnauthorized();

    $guest = User::factory()->create(['name' => config('permission.users.guest'), 'username' => config('permission.users.guest')]);
    $this->actingAs($guest)->getJson(documentationSearchUrl())->assertForbidden();

    $search->shouldNotHaveReceived('search');
});

it('answers as a disabled feature when help is off', function (): void {
    config(['ai.features.faq.enabled' => false]);
    $search = fakeDocumentationSearch();

    $this->actingAs($this->user)
        ->getJson(documentationSearchUrl())
        ->assertForbidden()
        ->assertJsonPath('code', 'FEATURE_DISABLED');

    $search->shouldNotHaveReceived('search');
});

it('answers 404 for an unknown or disabled module', function (): void {
    $search = fakeDocumentationSearch();

    $this->actingAs($this->user)->getJson(documentationSearchUrl('nosuch'))->assertNotFound();

    Module::shouldReceive('isEnabled')->with('Cms')->andReturnFalse();
    $this->actingAs($this->user)->getJson(documentationSearchUrl('cms'))->assertNotFound();

    $search->shouldNotHaveReceived('search');
});

it('answers 404 for a malformed module', function (string $url): void {
    $search = fakeDocumentationSearch();

    $this->actingAs($this->user)->getJson($url)->assertNotFound();

    $search->shouldNotHaveReceived('search');
})->with([
    'upper case' => [documentationSearchUrl('CMS')],
    'dotted' => [documentationSearchUrl('cms.x')],
    'extra segment' => ['/app/crud/search/cms/documentation/extra?q=approve'],
]);

it('answers 422 for a short, blank or overlong q and computes no embedding', function (?string $query): void {
    $embeddings = Mockery::spy(IEmbeddingService::class);
    app()->instance(IEmbeddingService::class, $embeddings);

    $this->actingAs($this->user)
        ->getJson(documentationSearchUrl(query: $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('q');

    $embeddings->shouldNotHaveReceived('embedText');
})->with([
    'missing' => [null],
    'short' => ['ab'],
    'short once trimmed' => ['  ab  '],
    'blank' => ['     '],
    'overlong' => [str_repeat('a', 201)],
]);

it('returns the passages in the response envelope', function (): void {
    $passages = [
        new DocumentationPassage('Approve a modification', 'Approve a modification', 'How to approve.', 'Approvals > Approve', 'cms'),
        new DocumentationPassage('Search', 'Search', 'How to search.'),
    ];
    fakeDocumentationSearch($passages);

    $this->actingAs($this->user)
        ->getJson(documentationSearchUrl())
        ->assertOk()
        ->assertJsonPath('data', array_map(static fn (DocumentationPassage $passage): array => $passage->toArray(), $passages));
});

it('passes the path module as the scope', function (): void {
    $search = fakeDocumentationSearch();

    $this->actingAs($this->user)->getJson(documentationSearchUrl('erp', '  approve an order  '))->assertOk();

    $search->shouldHaveReceived('search')->once()->with(
        Mockery::on(fn (User $user): bool => $user->is($this->user)),
        'erp',
        'approve an order',
    );
});

it('answers 503 when retrieval is unavailable', function (): void {
    fakeDocumentationSearch(new RuntimeException('Retrieval is down.'));

    $this->actingAs($this->user)->getJson(documentationSearchUrl())->assertServiceUnavailable();
});

it('is rate limited per user', function (): void {
    config(['ai.features.faq.search.rate_per_minute' => 2]);
    fakeDocumentationSearch();

    $this->actingAs($this->user)->getJson(documentationSearchUrl())->assertOk();
    $this->actingAs($this->user)->getJson(documentationSearchUrl())->assertOk();
    $this->actingAs($this->user)->getJson(documentationSearchUrl())->assertTooManyRequests();

    // A new session for the other user: the one above holds the first user's password hash.
    $this->flushSession();
    $this->actingAs(User::factory()->create())->getJson(documentationSearchUrl())->assertOk();
});

it('resolves search/{module}/documentation to the AI controller and leaves the others to Core', function (): void {
    $routes = Route::getRoutes();

    expect($routes->match(Request::create('/app/crud/search/cms/documentation'))->getActionName())
        ->toBe(DocumentationSearchController::class . '@search')
        ->and($routes->match(Request::create('/app/crud/search/cms/contents'))->getActionName())
        ->toBe(CrudController::class . '@search');
});

it('reserves the name documentation', function (): void {
    foreach (array_keys(Module::allEnabled()) as $module) {
        expect(DynamicEntity::tryResolveModel('documentation', null, $module))->toBeNull();
    }
});

it('is not exposed under api/v1', function (): void {
    $exposed = collect(Route::getRoutes()->getRoutes())
        ->map(static fn (Illuminate\Routing\Route $route): string => $route->uri())
        ->filter(static fn (string $uri): bool => str_starts_with($uri, 'api/v1') && str_ends_with($uri, 'documentation'));

    expect($exposed->all())->toBe([]);
});
