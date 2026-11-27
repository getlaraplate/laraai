<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Laravel resolves routes in registration order and has no notion of specificity, so the
 * generic Core CRUD routes (`{module}/{entity}`) must be registered after every module.
 * The cases below share their segment count with a Core catch-all and would silently fall
 * through to `Modules\Core\Http\Controllers\CrudController` if that ordering regressed.
 */
test('module specific ai routes win over the core crud catch-all', function (string $method, string $uri, string $expected): void {
    $route = Route::getRoutes()->match(Request::create('/' . $uri, $method));

    expect($route->getName())->toBe($expected);
})->with([
    ['GET', 'app/crud/select/ai/conversations', 'ai.crud.conversations.list'],
    ['POST', 'app/crud/insert/ai/conversations', 'ai.crud.conversations.insert'],
    ['POST', 'app/crud/update/ai/conversations/1/memory', 'ai.crud.conversations.memory'],
    ['GET', 'app/crud/detail/ai/assistant-writes/1', 'ai.crud.assistant-writes.show'],
    ['POST', 'app/crud/update/ai/assistant-writes/1/confirm', 'ai.crud.assistant-writes.confirm'],
    ['GET', 'app/crud/select/ai/suggestions', 'ai.crud.suggestions.list'],
    ['POST', 'app/crud/insert/ai/suggestions', 'ai.crud.suggestions.generate'],
]);

test('the core crud catch-all still handles modules without dedicated routes', function (): void {
    $route = Route::getRoutes()->match(Request::create('/app/crud/select/cms/contents', 'GET'));

    expect($route->getName())->toBe('core.crud.list')
        ->and($route->parameters())->toBe(['module' => 'cms', 'entity' => 'contents']);
});
