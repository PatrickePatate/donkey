<?php

namespace App\Models;

use App\Enums\FindingCategory;
use App\Enums\LearningKind;
use App\Enums\LearningStatus;
use App\Jobs\EmbedKnowledge;
use Database\Factories\LearningFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A generalized lesson from feedback on findings, for one repository or the whole organization.
 *
 * @property int $id
 * @property int $organization_id
 * @property ?int $repository_id
 * @property LearningKind $kind
 * @property LearningStatus $status
 * @property string $body
 * @property ?string $rationale
 * @property ?FindingCategory $category
 * @property ?string $path_glob
 * @property ?list<int> $source_finding_ids
 * @property float $weight
 * @property int $times_applied
 * @property ?Carbon $last_applied_at
 * @property ?int $reviewed_by
 * @property ?Carbon $reviewed_at
 * @property ?list<float> $embedding
 * @property Carbon $created_at
 * @property-read Organization $organization
 * @property-read ?Repository $repository
 * @property-read ?User $reviewer
 */
#[Fillable(['organization_id', 'repository_id', 'kind', 'status', 'body', 'rationale', 'category', 'path_glob', 'source_finding_ids', 'weight', 'times_applied', 'last_applied_at', 'reviewed_by', 'reviewed_at', 'embedding'])]
#[Hidden(['embedding'])]
class Learning extends Model
{
    /** @use HasFactory<LearningFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn (self $model) => EmbedKnowledge::whenNeeded($model));
    }

    protected function casts(): array
    {
        return [
            'kind' => LearningKind::class,
            'status' => LearningStatus::class,
            'category' => FindingCategory::class,
            'source_finding_ids' => 'array',
            'weight' => 'float',
            'times_applied' => 'integer',
            'last_applied_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'embedding' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<ReviewFinding, $this>
     */
    public function matchedFindings(): HasMany
    {
        return $this->hasMany(ReviewFinding::class);
    }

    /**
     * Learnings that apply to a repository: its own and the organization-wide ones.
     *
     * @param  Builder<Learning>  $query
     */
    public function scopeForRepository(Builder $query, Repository $repository): void
    {
        $query->where('organization_id', $repository->organization_id)
            ->where(fn (Builder $query) => $query->whereNull('repository_id')->orWhere('repository_id', $repository->id));
    }

    /**
     * @param  list<string>  $paths
     */
    public function appliesToAny(array $paths): bool
    {
        return $this->path_glob === null || collect($paths)->contains(fn (string $path): bool => Str::is($this->path_glob, $path));
    }

    /**
     * A match by the verifier: the learning is still useful.
     */
    public function reinforce(): void
    {
        $this->forceFill([
            'times_applied' => $this->times_applied + 1,
            'last_applied_at' => now(),
            'weight' => min((float) config('learning.decay.max_weight'), $this->weight + (float) config('learning.decay.reinforcement')),
        ])->save();
    }

    public function scopeLabel(): string
    {
        return $this->repository_id === null ? 'Organization' : (string) $this->repository?->full_name;
    }
}
