<?php

namespace App\Enums;

enum GraphStatus: string
{
    case Building = 'building';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Failed => 'destructive',
            self::Building => 'secondary',
        };
    }
}
