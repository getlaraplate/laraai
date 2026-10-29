<?php

declare(strict_types=1);

namespace Modules\AI\Tests;

use Illuminate\Support\Facades\Queue;
use Modules\AI\Jobs\GenerateConversationTitleJob;

/**
 * AI tests use the full Laraplate application bootstrap (same as production).
 */
abstract class TestCase extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs the queue in-process, and this job calls a model: it never runs behind a
        // test that answers a message. A test about the title runs the job itself.
        Queue::fake([GenerateConversationTitleJob::class]);

        // The assistant is off until an operator turns it on; the suite runs with it on, and a test about
        // the switch sets it itself.
        config(['ai.features.chat.enabled' => true]);

        // The module ships one embedding profile. The suite needs a second one, served by its own process,
        // to switch to, prune and probe: it is a fixture of the tests, not a profile an installation has.
        config([
            'ai.servers.embeddings_minilm' => ['url' => null, 'api_key' => null],
            'ai.features.embeddings.models.openai_compatible:all-MiniLM-L6-v2' => [
                'server' => 'embeddings_minilm',
                'dimensions' => 384,
                'query_prefix' => '',
                'passage_prefix' => '',
                'normalize' => true,
            ],
        ]);
    }
}
