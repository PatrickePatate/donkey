<?php

namespace App\Enums;

/**
 * Whether a review run had structural graph context.
 */
enum RunGraphStatus: string
{
    case Ready = 'ready';
    case Disabled = 'disabled';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Graph context used',
            self::Disabled => 'Graph disabled',
            self::Unavailable => 'Graph unavailable',
        };
    }
}
