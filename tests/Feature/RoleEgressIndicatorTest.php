<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Modules\AI\Ai\Providers\AiRole;
use Modules\AI\Ai\Providers\RoleEgress;
use Modules\Core\Data\SettingChangeWarning;
use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingChangeConfirmations;

const EGRESS_PROFILE = 'openai_compatible:some-embedding-model';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('ai.providers', [
        'openai' => ['api_key' => null, 'base_url' => null, 'model' => null],
        'ollama' => ['api_url' => null, 'model' => 'some-model'],
        'voyageai' => ['api_key' => null, 'model' => null],
        'mistral' => ['api_key' => null, 'model' => null],
        'anthropic' => ['api_key' => null],
    ]);
    config()->set('ai.servers', [
        'embeddings' => ['url' => '', 'api_key' => null, 'timeout' => 30, 'batch_size' => 32],
        'rerank' => ['url' => '', 'api_key' => null, 'dialect' => 'tei', 'model' => null],
        'transcription' => ['url' => '', 'api_key' => null, 'timeout' => 120],
    ]);
    config()->set('ai.features.embeddings.models', [EGRESS_PROFILE => ['dimensions' => 384]]);
    config()->set('ai.features.embeddings.serving_model', EGRESS_PROFILE);
});

function egress(string $role): ?bool
{
    return app(RoleEgress::class)->leavesInstallation($role);
}

function egress_model_setting(string $value = 'ollama:some-model'): Setting
{
    return new Setting(['name' => 'features.chat.model', 'value' => $value]);
}

function egress_warning(string $newValue): ?SettingChangeWarning
{
    return app(SettingChangeConfirmations::class)->for('features.chat.model')?->warn(egress_model_setting(), $newValue);
}

it('a role served by a configured self-hosted server does not leave the installation', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://embeddings.internal:8080');
    config()->set('ai.servers.transcription.url', 'http://transcription.internal:8000');
    config()->set('ai.providers.ollama.api_url', 'http://models.internal:11434');
    config()->set('ai.features.chat.model', 'ollama:some-model');
    config()->set('ai.features.rag.judge.model', 'ollama:some-model');
    config()->set('ai.features.media_analysis.transcription.model', 'openai_compatible:some-transcription-model');

    expect(egress(AiRole::EMBEDDING))->toBeFalse()
        ->and(egress(AiRole::ANSWER))->toBeFalse()
        ->and(egress(AiRole::JUDGE))->toBeFalse()
        ->and(egress('transcription'))->toBeFalse()
        ->and(egress_warning('ollama:some-model'))->toBeNull();
});

it('an external provider does', function (): void {
    config()->set('ai.providers.anthropic.api_key', 'secret-provider-key');
    config()->set('ai.providers.mistral.api_key', 'another-secret-key');
    config()->set('ai.features.chat.model', 'anthropic:some-model');
    config()->set('ai.features.search_orchestration.model', 'mistral:some-model');

    expect(egress(AiRole::ANSWER))->toBeTrue()
        ->and(egress(AiRole::INTENT))->toBeTrue();

    $warning = egress_warning('mistral:some-model');

    expect($warning)->toBeInstanceOf(SettingChangeWarning::class)
        ->and(implode("\n", $warning->lines))->toContain('leaves the installation');

    app()->setLocale('it');

    expect(implode("\n", egress_warning('mistral:some-model')->lines))->toContain('esce dall\'installazione');
});

it('an unknown provider shows no claim', function (): void {
    config()->set('ai.features.chat.model', 'unheard-of:model');
    config()->set('ai.features.rag.judge.model', 'anthropic:some-model');

    expect(egress(AiRole::ANSWER))->toBeNull()
        ->and(egress(AiRole::JUDGE))->toBeNull()
        ->and(egress(AiRole::EMBEDDING))->toBeNull()
        ->and(egress('no-such-role'))->toBeNull()
        ->and(egress_warning('unheard-of:model'))->toBeNull()
        ->and(egress_warning('anthropic:some-model'))->toBeNull();
});

it('lists per role whether text leaves the installation in the servers check, without a url or a key', function (): void {
    config()->set('ai.providers.anthropic.api_key', 'secret-provider-key');
    config()->set('ai.providers.ollama.api_url', 'http://models.internal:11434');
    config()->set('ai.features.chat.model', 'anthropic:some-model');
    config()->set('ai.features.rag.judge.model', 'ollama:some-model');

    $exit = Artisan::call('ai:servers:check');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain(AiRole::ANSWER)->toContain(AiRole::JUDGE)->toContain(AiRole::EMBEDDING)
        ->toContain('leaves the installation')->toContain('stays on a server')->toContain('cannot be told')
        ->not->toContain('secret-provider-key')->not->toContain('models.internal');
});
