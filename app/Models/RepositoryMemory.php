<?php

namespace App\Models;

use App\Jobs\EmbedKnowledge;
use Database\Factories\RepositoryMemoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Something the team told the reviewer about a repository, from "@bot remember" or the dashboard.
 *
 * @property int $id
 * @property int $repository_id
 * @property ?int $user_id
 * @property string $author_login
 * @property string $body
 * @property ?string $path_glob
 * @property bool $pinned
 * @property string $source
 * @property ?int $github_comment_id
 * @property ?list<float> $embedding
 * @property int $times_used
 * @property ?Carbon $last_used_at
 * @property Carbon $created_at
 * @property-read Repository $repository
 */
#[Fillable(['repository_id', 'user_id', 'author_login', 'body', 'path_glob', 'pinned', 'source', 'github_comment_id', 'embedding', 'times_used', 'last_used_at'])]
#[Hidden(['embedding'])]
class RepositoryMemory extends Model
{
    /** @use HasFactory<RepositoryMemoryFactory> */
    use HasFactory;

    public const SOURCE_GITHUB = 'github';

    public const SOURCE_DASHBOARD = 'dashboard';

    protected static function booted(): void
    {
        static::saved(fn (self $model) => EmbedKnowledge::whenNeeded($model));
    }

    protected function casts(): array
    {
        return [
            'pinned' => 'boolean',
            'github_comment_id' => 'integer',
            'embedding' => 'array',
            'times_used' => 'integer',
            'last_used_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the memory concerns one of these files. Memories without a path apply everywhere.
     *
     * @param  list<string>  $paths
     */
    public function appliesToAny(array $paths): bool
    {
        return $this->path_glob === null || collect($paths)->contains(fn (string $path): bool => Str::is($this->path_glob, $path));
    }
}
