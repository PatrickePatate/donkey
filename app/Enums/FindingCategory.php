<?php

namespace App\Enums;

enum FindingCategory: string
{
    case Bug = 'bug';
    case Security = 'security';
    case Performance = 'performance';
    case Logic = 'logic';
    case Maintainability = 'maintainability';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
