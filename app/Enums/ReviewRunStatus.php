<?php

namespace App\Enums;

enum ReviewRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Superseded = 'superseded';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Skipped, self::Superseded, self::Failed], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Failed => 'destructive',
            self::Running, self::Queued => 'default',
            self::Skipped, self::Superseded => 'secondary',
        };
    }
}
