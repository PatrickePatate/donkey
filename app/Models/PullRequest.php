<?php

namespace App\Models;

use Database\Factories\PullRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $repository_id
 * @property int $github_id
 * @property int $number
 * @property string $title
 * @property ?string $body
 * @property string $author_login
 * @property string $state
 * @property bool $is_draft
 * @property string $head_sha
 * @property string $base_sha
 * @property string $head_ref
 * @property string $base_ref
 * @property string $html_url
 * @property ?string $last_reviewed_sha
 * @property bool $auto_review_paused
 * @property-read Repository $repository
 */
#[Fillable(['repository_id', 'github_id', 'number', 'title', 'body', 'author_login', 'state', 'is_draft', 'head_sha', 'base_sha', 'head_ref', 'base_ref', 'html_url', 'last_reviewed_sha', 'auto_review_paused'])]
class PullRequest extends Model
{
    /** @use HasFactory<PullRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'github_id' => 'integer',
            'number' => 'integer',
            'is_draft' => 'boolean',
            'auto_review_paused' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * @return HasMany<ReviewRun, $this>
     */
    public function reviewRuns(): HasMany
    {
        return $this->hasMany(ReviewRun::class);
    }

    /**
     * @return HasOne<ReviewRun, $this>
     */
    public function latestReviewRun(): HasOne
    {
        return $this->hasOne(ReviewRun::class)->latestOfMany();
    }

    /**
     * @return HasMany<ReviewFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(ReviewFinding::class);
    }

    /**
     * @return HasMany<ConversationMessage, $this>
     */
    public function conversationMessages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function isOpen(): bool
    {
        return $this->state === 'open';
    }

    public function owner(): string
    {
        return explode('/', $this->repository->full_name)[0];
    }

    public function repositoryName(): string
    {
        return explode('/', $this->repository->full_name)[1];
    }
}
