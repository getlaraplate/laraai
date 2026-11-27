<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Enums\AITables;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\MigrateUtils;

/**
 * The durable side of an assistant turn: the turn itself, the conversation Neuron keeps for it
 * (`EloquentMessageStore`) and the records of its run (`EloquentPersistence`), so that a turn paused on a write
 * the person has to confirm resumes in a later request.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL and MariaDB collations ignore case and accents: Neuron's identifiers compare byte by byte there.
        $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        $turns = AITables::AssistantTurns->value;

        Schema::create($turns, static function (Blueprint $table) use ($turns): void {
            $table->uuid('id')->primary()->comment('The Neuron thread of the turn');
            $table->foreignId('user_id')->constrained(CoreTables::Users->value, 'id', "{$turns}_user_id_FK")->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained(AITables::Conversations->value, 'id', "{$turns}_conversation_id_FK")->cascadeOnDelete();
            $table->text('input')->comment('The question of the turn, as the guardrails let it through');
            $table->json('application_context')->nullable()->comment('The module, entity and record the server resolved for the turn');
            $table->string('status', 24)->default('running')->comment('running|awaiting_decisions|completed|failed');
            $table->json('call_answers')->nullable()->comment('What a write call answers when it produced no proposal, by call id');
            $table->unsignedBigInteger('reply_message_id')->nullable()->comment('The assistant message that answered the turn');
            $table->unsignedBigInteger('follow_up_message_id')->nullable()->comment('The last assistant message written after the decisions');
            MigrateUtils::timestamps($table, hasCreateUpdate: true, hasSoftDelete: true);

            $table->index(['conversation_id', 'status'], "{$turns}_conversation_status_IDX");
            $table->index(['updated_at'], "{$turns}_updated_at_IDX");
        });

        $messages = AITables::AgentMessages->value;

        Schema::create($messages, static function (Blueprint $table) use ($messages, $mysql): void {
            $table->id();

            if ($mysql) {
                $table->binary('thread_id', 255);
                $table->binary('message_id', 64);
            } else {
                $table->string('thread_id', 255);
                $table->string('message_id', 64);
            }

            $table->string('role', 32);
            $table->longText('content')->nullable();
            $table->longText('meta')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['thread_id', 'message_id'], "{$messages}_thread_message_UQ");
        });

        $records = AITables::AgentWorkflowRecords->value;

        Schema::create($records, static function (Blueprint $table) use ($records, $mysql): void {
            $table->id();

            if ($mysql) {
                $table->string('partition', 510)->charset('ascii')->collation('ascii_bin');
                $table->string('key', 510)->charset('ascii')->collation('ascii_bin');
                $table->longText('value')->charset('ascii');
            } else {
                $table->string('partition', 510);
                $table->string('key', 510);
                $table->longText('value');
            }

            $table->timestamps();
            $table->unique(['partition', 'key'], "{$records}_partition_key_UQ");
        });

        $proposals = AITables::WriteProposals->value;

        Schema::table($proposals, static function (Blueprint $table) use ($proposals, $turns): void {
            $table->uuid('turn_id')->nullable()->after('conversation_id')->comment('The turn whose run waits for this decision');
            $table->string('tool_call_id', 255)->nullable()->after('tool')->comment('The call of the model the decision answers');
            $table->foreign('turn_id', "{$proposals}_turn_id_FK")->references('id')->on($turns)->nullOnDelete();
            $table->index(['turn_id', 'status'], "{$proposals}_turn_status_IDX");
        });
    }

    public function down(): void
    {
        $proposals = AITables::WriteProposals->value;

        Schema::table($proposals, static function (Blueprint $table) use ($proposals): void {
            $table->dropForeign("{$proposals}_turn_id_FK");
            $table->dropIndex("{$proposals}_turn_status_IDX");
            $table->dropColumn(['turn_id', 'tool_call_id']);
        });

        Schema::dropIfExists(AITables::AgentWorkflowRecords->value);
        Schema::dropIfExists(AITables::AgentMessages->value);
        Schema::dropIfExists(AITables::AssistantTurns->value);
    }
};
