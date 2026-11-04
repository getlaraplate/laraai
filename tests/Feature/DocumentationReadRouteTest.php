<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\DocumentationSearchController;
use Modules\AI\Services\Documentation\Search\DocumentationRead;
use Modules\AI\Services\Documentation\Search\DocumentationReader;
use Modules\AI\Services\Documentation\Search\DocumentationSection;
use Modules\Core\Http\Controllers\CrudController;
use Modules\Core\Models\User;
use Nwidart\Modules\Facades\Module;

const READ_ROUTE_KEY = 'd_0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    $this->user = User::factory()->create();
    config(['ai.features.faq.enabled' => true]);
});

/**
 * Binds a reader that answers with the given document (null: no such document), or throws the given error.
 */
function fakeDocumentationReader(DocumentationRead|Throwable|null $answer = null): Mockery\MockInterface
{
    $reader = Mockery::mock(DocumentationReader::class);
    $expectation = $reader->shouldReceive('read');
    $answer instanceof Throwable ? $expectation->andThrow($answer) : $expectation->andReturn($answer);
    app()->instance(DocumentationReader::class, $reader);

    return $reader;
}

function documentationReadUrl(string $module = 'cms', ?string $reference = READ_ROUTE_KEY, ?string $section = null): string
{
    $query = array_filter(['reference' => $reference, 'section' => $section], static fn (?string $value): bool => $value !== null);

    return '/app/crud/detail/' . $module . '/documentation' . ($query === [] ? '' : '?' . http_build_query($query));
}

function readableDocument(): DocumentationRead
{
    return new DocumentationRead(
        reference: READ_ROUTE_KEY,
        label: 'Approve a modification',
        module: 'cms',
        total: 9,
        from: 3,
        sections: [
            new DocumentationSection(3, 'Open the item.', 'Approvals > Approve'),
            new DocumentationSection(4, 'Confirm.', 'Approvals > Approve'),
        ],
    );
}

it('refuses an anonymous call and the guest', function (): void {
    $reader = fakeDocumentationReader(readableDocument());

    $this->getJson(documentationReadUrl())->assertUnauthorized();

    $guest = User::factory()->create(['name' => config('permission.users.guest'), 'username' => config('permission.users.guest')]);
    $this->actingAs($guest)->getJson(documentationReadUrl())->assertForbidden();

    $reader->shouldNotHaveReceived('read');
});

it('answers as a disabled feature when help is off', function (): void {
    config(['ai.features.faq.enabled' => false]);
    $reader = fakeDocumentationReader(readableDocument());

    $this->actingAs($this->user)
        ->getJson(documentationReadUrl())
        ->assertForbidden()
        ->assertJsonPath('code', 'FEATURE_DISABLED');

    $reader->shouldNotHaveReceived('read');
});

it('answers 404 for an unknown, disabled or malformed module', function (string $module): void {
    $reader = fakeDocumentationReader(readableDocument());

    $this->actingAs($this->user)->getJson(documentationReadUrl($module))->assertNotFound();

    $reader->shouldNotHaveReceived('read');
})->with([
    'unknown' => ['nosuch'],
    'upper case' => ['CMS'],
    'dotted' => ['cms.x'],
]);

it('answers 404 for a module that is installed but disabled', function (): void {
    $reader = fakeDocumentationReader(readableDocument());
    Module::shouldReceive('isEnabled')->with('Cms')->andReturnFalse();

    $this->actingAs($this->user)->getJson(documentationReadUrl('cms'))->assertNotFound();

    $reader->shouldNotHaveReceived('read');
});

it('answers 422 for a missing reference or a section that is not a position', function (?string $reference, ?string $section): void {
    $reader = fakeDocumentationReader(readableDocument());

    $this->actingAs($this->user)->getJson(documentationReadUrl('cms', $reference, $section))->assertUnprocessable();

    $reader->shouldNotHaveReceived('read');
})->with([
    'no reference' => [null, null],
    'overlong reference' => [str_repeat('a', 201), null],
    'negative section' => [READ_ROUTE_KEY, '-1'],
    'non integer section' => [READ_ROUTE_KEY, 'two'],
]);

it('returns the whole document with its section count when no section is asked', function (): void {
    $reader = fakeDocumentationReader(readableDocument());

    $this->actingAs($this->user)
        ->getJson(documentationReadUrl())
        ->assertOk()
        ->assertJsonPath('data.reference', READ_ROUTE_KEY)
        ->assertJsonPath('data.label', 'Approve a modification')
        ->assertJsonPath('data.module', 'cms')
        ->assertJsonPath('data.total', 9)
        ->assertJsonPath('data.from', 3)
        ->assertJsonPath('data.sections.0', ['section' => 3, 'heading_breadcrumb' => 'Approvals > Approve', 'content' => 'Open the item.'])
        ->assertJsonCount(2, 'data.sections');

    $reader->shouldHaveReceived('read')->once()->with(
        Mockery::on(fn (User $user): bool => $user->is($this->user)),
        'cms',
        READ_ROUTE_KEY,
        null,
    );
});

it('passes the section and the module of the path to the reader', function (): void {
    $reader = fakeDocumentationReader(readableDocument());

    $this->actingAs($this->user)->getJson(documentationReadUrl('erp', READ_ROUTE_KEY, '4'))->assertOk();

    $reader->shouldHaveReceived('read')->once()->with(Mockery::type(User::class), 'erp', READ_ROUTE_KEY, 4);
});

it('answers a document that cannot be read like one that does not exist', function (string $reference): void {
    fakeDocumentationReader(null);

    $unreadable = $this->actingAs($this->user)->getJson(documentationReadUrl('cms', $reference));

    $unreadable->assertNotFound();

    $missing = $this->actingAs($this->user)->getJson(documentationReadUrl('cms', 'd_' . str_repeat('f', 32)));

    expect($unreadable->json('error'))->toBe($missing->json('error'))
        ->and($unreadable->json('data'))->toBe($missing->json('data'))
        ->and($unreadable->getStatusCode())->toBe($missing->getStatusCode());
})->with([
    'a key the person may not read' => [READ_ROUTE_KEY],
    'a label from before the first reindex' => ['Approve a modification'],
    'a path' => ['/srv/app/guide.md'],
]);

it('answers 503 when retrieval is unavailable', function (): void {
    fakeDocumentationReader(new RuntimeException('Retrieval is down.'));

    $this->actingAs($this->user)->getJson(documentationReadUrl())->assertServiceUnavailable();
});

it('never puts a path in a response', function (): void {
    fakeDocumentationReader(readableDocument());

    $response = $this->actingAs($this->user)->getJson(documentationReadUrl())->assertOk();

    expect($response->getContent())->not->toContain('canonical_source')->not->toContain('.md');
});

it('is rate limited per user, on its own limiter', function (): void {
    config(['ai.features.faq.read.rate_per_minute' => 2, 'ai.features.faq.search.rate_per_minute' => 100]);
    fakeDocumentationReader(readableDocument());

    $this->actingAs($this->user)->getJson(documentationReadUrl())->assertOk();
    $this->actingAs($this->user)->getJson(documentationReadUrl())->assertOk();
    $this->actingAs($this->user)->getJson(documentationReadUrl())->assertTooManyRequests();

    $this->flushSession();
    $this->actingAs(User::factory()->create())->getJson(documentationReadUrl())->assertOk();
});

it('resolves detail/{module}/documentation to the AI controller and leaves the other entities to Core', function (): void {
    $routes = Route::getRoutes();

    expect($routes->match(Request::create('/app/crud/detail/cms/documentation'))->getActionName())
        ->toBe(DocumentationSearchController::class . '@read')
        ->and($routes->match(Request::create('/app/crud/detail/cms/contents'))->getActionName())
        ->toBe(CrudController::class . '@detail');
});

it('is not exposed under api/v1', function (): void {
    $exposed = collect(Route::getRoutes()->getRoutes())
        ->map(static fn (Illuminate\Routing\Route $route): string => $route->uri())
        ->filter(static fn (string $uri): bool => str_starts_with($uri, 'api/v1') && str_contains($uri, 'documentation'));

    expect($exposed->all())->toBe([]);
});
