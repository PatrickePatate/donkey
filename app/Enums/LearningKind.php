<?php

namespace App\Enums;

enum LearningKind: string
{
    /** A kind of finding the team does not want, e.g. an intentional pattern. */
    case FalsePositive = 'false_positive';

    /** How the team prefers things done. */
    case Preference = 'preference';

    /** A rule of the codebase reviewers should check. */
    case Convention = 'convention';

    public function label(): string
    {
        return match ($this) {
            self::FalsePositive => 'False positive',
            self::Preference => 'Preference',
            self::Convention => 'Convention',
        };
    }
}
