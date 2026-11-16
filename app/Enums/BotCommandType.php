<?php

namespace App\Enums;

/**
 * What a comment addressed to the bot asks for.
 */
enum BotCommandType: string
{
    case Review = 'review';
    case FullReview = 'full review';
    case RunAgent = 'run';
    case ListAgents = 'agents';
    case Ignore = 'ignore';
    case Pause = 'pause';
    case Resume = 'resume';
    case Help = 'help';

    /** Anything else: a question answered by the assistant. */
    case Ask = 'ask';

    /**
     * Commands that change what the bot does on the pull request, rather than only reading it.
     */
    public function requiresWriteAccess(): bool
    {
        return match ($this) {
            self::Review, self::FullReview, self::RunAgent, self::Ignore, self::Pause, self::Resume, self::Ask => true,
            self::ListAgents, self::Help => false,
        };
    }
}
