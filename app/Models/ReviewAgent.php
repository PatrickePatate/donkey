<?php

namespace App\Models;

use App\Enums\FindingCategory;
use App\Enums\FindingSeverity;
use Database\Factories\ReviewAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A specialist reviewer configured by an organization, either org-wide or for a single repository.
 *
 * @property int $id
 * @property int $organization_id
 * @property ?int $repository_id
 * @property string $slug
 * @property string $name
 * @property ?string $description
 * @property string $instructions
 * @property ?string $model
 * @property ?list<string> $file_globs
 * @property ?list<string> $categories
 * @property ?FindingSeverity $severity_floor
 * @property bool $run_on_auto_review
 * @property bool $enabled
 * @property-read Organization $organization
 * @property-read ?Repository $repository
 */
#[Fillable(['organization_id', 'repository_id', 'slug', 'name', 'description', 'instructions', 'model', 'file_globs', 'categories', 'severity_floor', 'run_on_auto_review', 'enabled'])]
class ReviewAgent extends Model
{
    /** @use HasFactory<ReviewAgentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'file_globs' => 'array',
            'categories' => 'array',
            'severity_floor' => FindingSeverity::class,
            'run_on_auto_review' => 'boolean',
            'enabled' => 'boolean',
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
     * Enabled agents that apply to a repository. A repository agent replaces an org-wide agent with the same slug.
     *
     * @return Collection<int, ReviewAgent>
     */
    public static function availableFor(Repository $repository): Collection
    {
        return self::query()
            ->where('organization_id', $repository->organization_id)
            ->where('enabled', true)
            ->where(fn (Builder $query) => $query->whereNull('repository_id')->orWhere('repository_id', $repository->id))
            ->orderByRaw('repository_id is null')
            ->orderBy('name')
            ->get()
            ->unique('slug')
            ->sortBy('name')
            ->values();
    }

    public static function findForRepository(Repository $repository, string $slug): ?self
    {
        return self::availableFor($repository)->firstWhere('slug', Str::lower($slug));
    }

    /**
     * Whether the agent looks at this file. No globs means every file.
     */
    public function matchesPath(string $path): bool
    {
        if (blank($this->file_globs)) {
            return true;
        }

        foreach ($this->file_globs as $pattern) {
            if (Str::is($pattern, $path) || (! str_contains($pattern, '/') && Str::is($pattern, basename($path)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<FindingCategory>
     */
    public function allowedCategories(): array
    {
        if (blank($this->categories)) {
            return FindingCategory::cases();
        }

        $allowed = array_values(array_filter(array_map(FindingCategory::tryFrom(...), $this->categories)));

        return $allowed === [] ? FindingCategory::cases() : $allowed;
    }

    public function allowsSeverity(FindingSeverity $severity): bool
    {
        return $this->severity_floor === null || $severity->weight() >= $this->severity_floor->weight();
    }

    public function scopeLabel(): string
    {
        return $this->repository_id === null ? 'Organization' : (string) $this->repository?->full_name;
    }
}
