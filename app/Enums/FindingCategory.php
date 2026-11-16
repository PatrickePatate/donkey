<?php

namespace App\Enums;

enum FindingCategory: string
{
    case Bug = 'bug';
    case Security = 'security';
    case Performance = 'performance';
    case Logic = 'logic';
    case Regression = 'regression';
    case Maintainability = 'maintainability';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Categories about the code doing the wrong thing, eligible for the correctness gate.
     */
    public function isCorrectness(): bool
    {
        return in_array($this, [self::Bug, self::Logic, self::Regression], true);
    }
}
