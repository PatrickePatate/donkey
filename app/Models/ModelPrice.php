<?php

namespace App\Models;

use Database\Factories\ModelPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An organization's price for a model, in US dollars per million tokens. Overrides config/ai_pricing.php.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $model
 * @property numeric-string $input_per_million
 * @property numeric-string $output_per_million
 * @property numeric-string|null $cache_read_per_million
 * @property numeric-string|null $cache_write_per_million
 * @property Carbon|null $deleted_at
 */
#[Fillable(['organization_id', 'model', 'input_per_million', 'output_per_million', 'cache_read_per_million', 'cache_write_per_million'])]
class ModelPrice extends Model
{
    /** @use HasFactory<ModelPriceFactory> */
    use HasFactory;
    use SoftDeletes;

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
