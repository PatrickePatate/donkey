<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a review run's live log.
 *
 * @property int $id
 * @property int $review_run_id
 * @property string $level
 * @property string $message
 * @property Carbon $created_at
 */
#[Fillable(['review_run_id', 'level', 'message'])]
class ReviewRunLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * A tool call line split as the review tools log it: "tool arguments → outcome".
     *
     * @return ?array{tool: string, arguments: string, outcome: string}
     */
    public function toolCall(): ?array
    {
        if ($this->level !== 'tool') {
            return null;
        }

        [$call, $outcome] = array_pad(explode(' → ', $this->message, 2), 2, '');
        [$tool, $arguments] = array_pad(explode(' ', $call, 2), 2, '');

        return ['tool' => $tool, 'arguments' => $arguments, 'outcome' => $outcome];
    }

    /**
     * @return BelongsTo<ReviewRun, $this>
     */
    public function reviewRun(): BelongsTo
    {
        return $this->belongsTo(ReviewRun::class);
    }
}
