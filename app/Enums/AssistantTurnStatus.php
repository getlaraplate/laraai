<?php

declare(strict_types=1);

namespace Modules\AI\Enums;

/**
 * Where an assistant turn is. A turn runs, and either completes or pauses on writes the person has to decide
 * (`AwaitingDecisions`); once every decision is in, its run resumes and completes. `Failed` is a turn whose
 * run could not go on: the decisions already taken stand.
 */
enum AssistantTurnStatus: string
{
    case Running = 'running';
    case AwaitingDecisions = 'awaiting_decisions';
    case Completed = 'completed';
    case Failed = 'failed';
}
