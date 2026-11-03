<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchMaintenanceGuard;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\Core\Search\Contracts\ISearchMaintenanceGuard;
use Symfony\Component\Console\Command\Command as BaseCommand;

it('does not block index maintenance while no switch runs', function (EmbeddingSwitchState $state): void {
    $this->seed(AIDatabaseSeeder::class);
    $store = new EmbeddingSwitchStore;
    $store->put($state);

    expect((new EmbeddingSwitchMaintenanceGuard($store))->blockedReason())->toBeNull();
})->with([
    'idle' => [EmbeddingSwitchState::idle()],
    'refused at its preflight' => [new EmbeddingSwitchState('failed', 'preflight', 'a:b', 'c:d', error: 'x')],
]);

it('blocks index maintenance while a switch runs or failed after its preflight, naming the way out', function (EmbeddingSwitchState $state): void {
    $this->seed(AIDatabaseSeeder::class);
    $store = new EmbeddingSwitchStore;
    $store->put($state);

    expect((new EmbeddingSwitchMaintenanceGuard($store))->blockedReason())
        ->toContain('ai:embeddings:status')
        ->toContain('ai:embeddings:switch --resume')
        ->toContain('ai:embeddings:switch --abandon');
})->with([
    'running' => [new EmbeddingSwitchState('running', 'embeddings', 'a:b', 'c:d')],
    'failed after the preflight' => [new EmbeddingSwitchState('failed', 'indexes', 'a:b', 'c:d', error: 'x')],
]);

it('replaces the guard Core binds, and follows the state of the switch', function (): void {
    $this->seed(AIDatabaseSeeder::class);

    $guard = app(ISearchMaintenanceGuard::class);

    expect($guard)->toBeInstanceOf(EmbeddingSwitchMaintenanceGuard::class)
        ->and($guard->blockedReason())->toBeNull();

    (new EmbeddingSwitchStore)->put(new EmbeddingSwitchState('running', 'indexes', 'a:b', 'c:d'));

    expect(app(ISearchMaintenanceGuard::class)->blockedReason())->not->toBeNull();
});

it('makes scout:rebuild refuse to run during a switch, before it touches any index', function (): void {
    $this->seed(AIDatabaseSeeder::class);
    (new EmbeddingSwitchStore)->put(new EmbeddingSwitchState('running', 'indexes', 'a:b', 'c:d'));

    $this->artisan('scout:rebuild', ['--dry-run' => true])
        ->expectsOutputToContain('Search index maintenance is blocked: an embedding model switch is in progress')
        ->assertExitCode(BaseCommand::FAILURE);
});

it('prints the way out of a switch when scout:rebuild is refused', function (): void {
    $this->seed(AIDatabaseSeeder::class);
    (new EmbeddingSwitchStore)->put(new EmbeddingSwitchState('failed', 'verify', 'a:b', 'c:d', error: 'x'));

    $exit_code = Artisan::call('scout:rebuild', ['--dry-run' => true]);

    expect($exit_code)->toBe(BaseCommand::FAILURE)
        ->and(Artisan::output())->toContain('ai:embeddings:status')->toContain('ai:embeddings:switch --resume');
});
