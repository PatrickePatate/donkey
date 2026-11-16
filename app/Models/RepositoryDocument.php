<?php

namespace App\Models;

use Database\Factories\RepositoryDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The latest generated architecture guide for one repository.
 *
 * @property int $id
 * @property int $repository_id
 * @property ?string $commit_sha
 * @property ?string $content
 * @property ?Carbon $generated_at
 * @property ?string $error
 * @property-read Repository $repository
 */
#[Fillable(['repository_id', 'commit_sha', 'content', 'generated_at', 'error'])]
class RepositoryDocument extends Model
{
    /** @use HasFactory<RepositoryDocumentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
