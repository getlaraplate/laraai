<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Enums\AITables;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\MigrateUtils;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = AITables::WriteProposals->value;

        Schema::create($table_name, static function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('user_id')->constrained(CoreTables::Users->value, 'id', "{$table_name}_user_id_FK")->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained(AITables::Conversations->value, 'id', "{$table_name}_conversation_id_FK")->cascadeOnDelete();
            $table->uuid('turn_id')->nullable()->comment('The turn whose run waits for this decision');
            $table->foreign('turn_id', "{$table_name}_turn_id_FK")->references('id')->on(AITables::AssistantTurns->value)->nullOnDelete();
            $table->string('tool')->comment('Name of the tool the model called');
            $table->string('tool_call_id', 255)->nullable()->comment('The call of the model the decision answers');
            $table->string('module', 64)->comment('Module of the target entity');
            $table->string('entity', 128)->comment('Target entity');
            $table->string('operation', 20)->comment('create|update|delete|bulk_update|bulk_delete');
            $table->json('payload')->comment('Exactly what is applied on confirmation, including the ids a bulk call matched');
            $table->json('summary')->comment('What the person is shown: the change, or the count and a sample');
            $table->boolean('requires_approval')->default(false)->comment('Whether Core would capture the write for a vote');
            $table->string('status', 20)->default('proposed')->comment('proposed|applying|applied|pending_approval|rejected|expired|failed');
            $table->json('outcome')->nullable()->comment('Applied, captured and failed counts, modification ids, the refusal');
            $table->timestamp('expires_at')->comment('After this a proposal cannot be confirmed');
            $table->timestamp('resolved_at')->nullable()->comment('When the person confirmed or rejected it');

            // Core's soft-delete scope reads the column, so it exists; the model never soft deletes
            // (`$softDeletesEnabled = false`): a proposal is an audit record of what the assistant asked for.
            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
            );

            $table->index(['conversation_id', 'status'], "{$table_name}_conversation_status_IDX");
            $table->index(['user_id', 'status'], "{$table_name}_user_status_IDX");
            $table->index(['turn_id', 'status'], "{$table_name}_turn_status_IDX");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(AITables::WriteProposals->value);
    }
};
