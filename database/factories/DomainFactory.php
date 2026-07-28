<?php

namespace Database\Factories;

use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'host' => 'ollama.'.$this->faker->unique()->domainName(),
            'scheme' => 'https',
            'is_active' => false,
            'response_time_ms' => null,
            'model_count' => 0,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'last_probed_at' => null,
            'last_active_at' => null,
            'last_error' => null,
            'consecutive_failures' => 0,
        ];
    }

    /**
     * A domain confirmed to be serving a live Ollama API.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => true,
            'response_time_ms' => $this->faker->numberBetween(40, 2000),
            'model_count' => $this->faker->numberBetween(1, 12),
            'last_probed_at' => now(),
            'last_active_at' => now(),
            'last_error' => null,
            'consecutive_failures' => 0,
        ]);
    }

    /**
     * A domain that failed its last probe.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
            'response_time_ms' => null,
            'model_count' => 0,
            'last_probed_at' => now(),
            'last_error' => 'Connection timed out',
            'consecutive_failures' => $this->faker->numberBetween(1, 5),
        ]);
    }
}
