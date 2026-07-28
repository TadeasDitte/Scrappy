<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OllamaModelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $domain_id
 * @property string $name
 * @property string|null $digest
 * @property int|null $size_bytes
 * @property string|null $family
 * @property string|null $parameter_size
 * @property string|null $quantization
 * @property bool $available
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'domain_id',
    'name',
    'digest',
    'size_bytes',
    'family',
    'parameter_size',
    'quantization',
    'available',
])]
class OllamaModel extends Model
{
    /** @use HasFactory<OllamaModelFactory> */
    use HasFactory;

    protected $table = 'models';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'available' => 'boolean',
        ];
    }

    /**
     * The domain that exposes this model.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
