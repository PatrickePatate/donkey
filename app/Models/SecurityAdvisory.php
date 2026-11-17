<?php

namespace App\Models;

use App\Enums\FindingSeverity;
use Database\Factories\SecurityAdvisoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Packagist security advisory that affects a package version locked in a repository's composer.lock.
 *
 * @property ?FindingSeverity $severity
 */
#[Fillable(['repository_id', 'package', 'installed_version', 'is_dev', 'advisory_id', 'title', 'cve', 'link', 'severity', 'affected_versions', 'reported_at'])]
class SecurityAdvisory extends Model
{
    /** @use HasFactory<SecurityAdvisoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_dev' => 'boolean',
            'severity' => FindingSeverity::class,
            'reported_at' => 'datetime',
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
