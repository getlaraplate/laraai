<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Services\EmbeddingsGate;
use Modules\AI\Tests\Unit\SearchableModelStub;
use Modules\Core\Models\Setting;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Support\VectorProfileContext;

beforeEach(function (): void {
    config()->set('ai.features.embeddings.enabled', true);
    config()->set('ai.features.embeddings.modules', []);
});

it('allows an embeddable model while the switch is on', function (): void {
    expect(new EmbeddingsGate()->allows(new SearchableModelStub))->toBeTrue();
});

it('is off while the setting is off, and while it is not there at all', function (): void {
    config()->set('ai.features.embeddings.enabled', false);
    expect(new EmbeddingsGate()->allows(new SearchableModelStub))->toBeFalse();

    config()->set('ai.features.embeddings', []);
    expect(new EmbeddingsGate()->enabled())->toBeFalse();
});

it('keeps a model out of a module that the allowlist leaves out', function (): void {
    config()->set('ai.features.embeddings.modules', ['cms']);

    expect(new EmbeddingsGate()->allows(new SearchableModelStub))->toBeFalse()
        ->and(new EmbeddingsGate()->allows(new SearchableModelStub, requireEmbeddable: false))->toBeFalse();
});

it('wants a searchable and embeddable model unless the caller says it does not need one', function (): void {
    $gate = new EmbeddingsGate;

    expect($gate->allows(new Setting))->toBeFalse()
        ->and($gate->allows(new Setting, requireEmbeddable: false))->toBeTrue()
        ->and($gate->allows(Mockery::mock(Model::class)->makePartial()))->toBeFalse();

    config()->set('ai.features.embeddings.serving_model', 'nope:missing');

    expect($gate->allows(new SearchableModelStub))->toBeFalse();
});

it('handles an embeddable model of an admitted module while a vector profile is in force, whatever the switch says', function (): void {
    config()->set('ai.features.embeddings.enabled', false);

    $gate = new EmbeddingsGate;
    $profile = new VectorProfile('openai_compatible:intfloat/multilingual-e5-small', 384, 'cosine');

    expect($gate->handles(new SearchableModelStub))->toBeFalse();

    VectorProfileContext::using($profile, function () use ($gate): void {
        expect($gate->handles(new SearchableModelStub))->toBeTrue()
            ->and($gate->handles(new Setting))->toBeFalse();

        config()->set('ai.features.embeddings.modules', ['cms']);

        expect($gate->handles(new SearchableModelStub))->toBeFalse();
    });
});
