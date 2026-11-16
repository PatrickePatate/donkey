<?php

namespace App\Models;

use App\Enums\SignalType;
use Database\Factories\FindingSignalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Feedback from GitHub on a published finding; the raw material for learnings and quality metrics.
 *
 * @property int $id
 * @property int $review_finding_id
 * @property int $repository_id
 * @property SignalType $type
 * @property ?string $actor_login
 * @property ?Carbon $processed_at
 * @property Carbon $created_at
 * @property-read ReviewFinding $finding
 * @property-read Repository $repository
 */
#[Fillable(['review_finding_id', 'repository_id', 'type', 'actor_login', 'processed_at'])]
class FindingSignal extends Model
{
    /** @use HasFactory<FindingSignalFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => SignalType::class,
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReviewFinding, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(ReviewFinding::class, 'review_finding_id');
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
