<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $host
 * @property string $scheme
 * @property bool $is_active
 * @property int|null $response_time_ms
 * @property int $model_count
 * @property CarbonImmutable|null $first_seen_at
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $last_probed_at
 * @property CarbonImmutable|null $last_active_at
 * @property string|null $last_error
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'host',
    'scheme',
    'is_active',
    'response_time_ms',
    'model_count',
    'first_seen_at',
    'last_seen_at',
    'last_probed_at',
    'last_active_at',
    'last_error',
    'consecutive_failures',
])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'response_time_ms' => 'integer',
            'model_count' => 'integer',
            'consecutive_failures' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_probed_at' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    /**
     * The Ollama models exposed by this domain.
     *
     * @return HasMany<OllamaModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(OllamaModel::class);
    }

    /**
     * The fully qualified base URL for the domain.
     */
    public function baseUrl(): string
    {
        return "{$this->scheme}://{$this->host}";
    }

    /**
     * Only domains confirmed to be serving a live Ollama API.
     *
     * @param  Builder<Domain>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Fastest responding domains first (untested domains last).
     *
     * @param  Builder<Domain>  $query
     */
    public function scopeRankedBySpeed(Builder $query): void
    {
        $query->orderByRaw('response_time_ms is null, response_time_ms asc');
    }

    /**
     * Domains exposing the most models first.
     *
     * @param  Builder<Domain>  $query
     */
    public function scopeRankedByModelCount(Builder $query): void
    {
        $query->orderByDesc('model_count');
    }
}
