<?php

namespace App\Enums;

/**
 * Feedback on a published finding, collected from GitHub.
 */
enum SignalType: string
{
    /** Dismissed with "@bot ignore". */
    case Dismissed = 'dismissed';

    /** 👎 reaction on the finding's comment. */
    case ThumbsDown = 'thumbs_down';

    /** 👍 reaction on the finding's comment. */
    case ThumbsUp = 'thumbs_up';

    /** Its review thread was resolved. */
    case Resolved = 'resolved';

    /** The suggested change was committed. */
    case Applied = 'applied';

    /** The code it pointed at changed in a later commit. */
    case Fixed = 'fixed';

    /** The pull request merged with the finding untouched. */
    case IgnoredOnMerge = 'ignored_on_merge';

    public function isPositive(): bool
    {
        return in_array($this, [self::ThumbsUp, self::Resolved, self::Applied, self::Fixed], true);
    }

    public function isNegative(): bool
    {
        return ! $this->isPositive();
    }

    /**
     * Strong negative feedback is worth turning into a learning on its own.
     */
    public function isStrongNegative(): bool
    {
        return in_array($this, [self::Dismissed, self::ThumbsDown], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Dismissed => 'Dismissed',
            self::ThumbsDown => '👎',
            self::ThumbsUp => '👍',
            self::Resolved => 'Thread resolved',
            self::Applied => 'Suggestion applied',
            self::Fixed => 'Fixed',
            self::IgnoredOnMerge => 'Merged untouched',
        };
    }

    /**
     * @return list<string>
     */
    public static function positiveValues(): array
    {
        return array_values(array_map(fn (self $type): string => $type->value, array_filter(self::cases(), fn (self $type): bool => $type->isPositive())));
    }

    /**
     * @return list<string>
     */
    public static function negativeValues(): array
    {
        return array_values(array_map(fn (self $type): string => $type->value, array_filter(self::cases(), fn (self $type): bool => $type->isNegative())));
    }
}
