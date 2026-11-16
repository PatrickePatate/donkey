<?php

namespace App\Enums;

enum ReviewTrigger: string
{
    case Auto = 'auto';
    case Comment = 'comment';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Automatic',
            self::Comment => 'PR comment',
            self::Manual => 'Dashboard',
        };
    }
}
