<?php

namespace App\Enums;

enum FindingSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /**
     * Base weight used to compute a finding's 0-100 risk score.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 100,
            self::High => 75,
            self::Medium => 45,
            self::Low => 20,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Critical => '🔴',
            self::High => '🟠',
            self::Medium => '🟡',
            self::Low => '🔵',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Critical, self::High => 'destructive',
            self::Medium => 'warning',
            self::Low => 'secondary',
        };
    }
}
