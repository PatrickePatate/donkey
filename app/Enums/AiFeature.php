<?php

namespace App\Enums;

/**
 * What an AI call was for, to break costs down.
 */
enum AiFeature: string
{
    case Review = 'review';
    case Agent = 'agent';
    case Verification = 'verification';
    case Conversation = 'conversation';
    case Learning = 'learning';
    case Evaluation = 'evaluation';

    public function label(): string
    {
        return match ($this) {
            self::Review => 'Reviews',
            self::Agent => 'Agents',
            self::Verification => 'Verifier',
            self::Conversation => 'Answers on GitHub',
            self::Learning => 'Learning proposals',
            self::Evaluation => 'Evaluations',
        };
    }
}
