<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\OllamaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;

class ProbeDomain implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job is allowed to run before timing out.
     */
    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(public Domain $domain) {}

    /**
     * Probe the domain's Ollama API and update its liveness + model list.
     */
    public function handle(OllamaClient $ollama): void
    {
        $startedAt = microtime(true);
        $models = $ollama->tags($this->domain);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($models === null) {
            $this->recordFailure();

            return;
        }

        $this->recordSuccess($models, $elapsedMs);
    }

    /**
     * Mark the domain active and sync its models table.
     *
     * @param  array<int, array<string, mixed>>  $models
     */
    protected function recordSuccess(array $models, int $elapsedMs): void
    {
        $now = now();
        $seenNames = [];

        foreach ($models as $model) {
            $name = Arr::get($model, 'name');

            if (! is_string($name) || $name === '') {
                continue;
            }

            $seenNames[] = $name;

            $this->domain->models()->updateOrCreate(
                ['name' => $name],
                [
                    'digest' => Arr::get($model, 'digest'),
                    'size_bytes' => Arr::get($model, 'size'),
                    'family' => Arr::get($model, 'details.family'),
                    'parameter_size' => Arr::get($model, 'details.parameter_size'),
                    'quantization' => Arr::get($model, 'details.quantization_level'),
                    'available' => true,
                ],
            );
        }

        // Models no longer advertised are kept for history but flagged unavailable.
        $this->domain->models()
            ->whereNotIn('name', $seenNames)
            ->update(['available' => false]);

        $this->domain->forceFill([
            'is_active' => true,
            'response_time_ms' => $elapsedMs,
            'model_count' => count($seenNames),
            'last_probed_at' => $now,
            'last_active_at' => $now,
            'last_error' => null,
            'consecutive_failures' => 0,
        ])->save();
    }

    /**
     * Mark the domain inactive and note the failure.
     */
    protected function recordFailure(): void
    {
        $this->domain->forceFill([
            'is_active' => false,
            'response_time_ms' => null,
            'model_count' => 0,
            'last_probed_at' => now(),
            'last_error' => 'No valid Ollama /api/tags response',
            'consecutive_failures' => $this->domain->consecutive_failures + 1,
        ])->save();

        $this->domain->models()->update(['available' => false]);
    }
}
