<?php

namespace App\Models;

use App\Enums\GraphStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A kodus-graph structural graph of a repository at one commit.
 *
 * @property int $id
 * @property int $repository_id
 * @property string $commit_sha
 * @property GraphStatus $status
 * @property ?string $path
 * @property int $nodes_count
 * @property int $edges_count
 * @property int $files_count
 * @property int $size_bytes
 * @property int $duration_ms
 * @property bool $is_incremental
 * @property ?string $error
 * @property ?Carbon $built_at
 * @property-read Repository $repository
 */
#[Fillable(['repository_id', 'commit_sha', 'status', 'path', 'nodes_count', 'edges_count', 'files_count', 'size_bytes', 'duration_ms', 'is_incremental', 'error', 'built_at'])]
class RepositoryGraph extends Model
{
    protected function casts(): array
    {
        return [
            'status' => GraphStatus::class,
            'nodes_count' => 'integer',
            'edges_count' => 'integer',
            'files_count' => 'integer',
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'is_incremental' => 'boolean',
            'built_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function isUsable(): bool
    {
        return $this->status === GraphStatus::Ready && $this->path !== null && is_file($this->path);
    }
}
