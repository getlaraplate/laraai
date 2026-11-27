<?php

declare(strict_types=1);

namespace Modules\AI\Enums;

use Modules\Core\Enums\Concerns\HasModuleTablesUtils;

enum AITables: string
{
    use HasModuleTablesUtils;

    case Conversations = 'ai_conversations';
    case Messages = 'ai_messages';
    case ConversationSummaries = 'ai_conversation_summaries';
    case ContextualSuggestions = 'ai_contextual_suggestions';
    case MediaAnalyses = 'ai_media_analyses';
    case WriteProposals = 'ai_write_proposals';
    case AssistantTurns = 'ai_assistant_turns';
    case AgentMessages = 'ai_agent_messages';
    case AgentWorkflowRecords = 'ai_agent_workflow_records';
}
