<?php

namespace App\Enums;

enum LearningStatus: string
{
    /** Proposed from feedback, waiting for an admin. */
    case Proposed = 'proposed';

    /** Used in reviews. */
    case Active = 'active';

    /** Retired by decay or by hand; no longer used. */
    case Archived = 'archived';

    /** Turned down by an admin; kept so it is not proposed again. */
    case Rejected = 'rejected';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Proposed => 'warning',
            self::Active => 'success',
            self::Archived, self::Rejected => 'outline',
        };
    }
}
