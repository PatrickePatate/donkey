<?php

namespace App\Enums;

enum FindingStatus: string
{
    /** Posted as an inline comment on GitHub. */
    case Published = 'published';

    /** Kept in the dashboard only, below the risk threshold. */
    case Suppressed = 'suppressed';

    /** Same issue as a finding already posted on this pull request. */
    case Duplicate = 'duplicate';

    /** A previously posted finding that no longer appears after new commits. */
    case Outdated = 'outdated';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Published => 'default',
            self::Suppressed, self::Duplicate => 'secondary',
            self::Outdated => 'outline',
        };
    }
}
