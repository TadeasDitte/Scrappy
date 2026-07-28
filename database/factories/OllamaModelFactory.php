<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\OllamaModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OllamaModel>
 */
class OllamaModelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $family = $this->faker->randomElement(['llama', 'qwen2', 'gemma', 'mistral', 'phi3']);

        return [
            'domain_id' => Domain::factory(),
            'name' => $family.':'.$this->faker->randomElement(['latest', '7b', '8b', '13b']),
            'digest' => $this->faker->sha256(),
            'size_bytes' => $this->faker->numberBetween(1_000_000_000, 9_000_000_000),
            'family' => $family,
            'parameter_size' => $this->faker->randomElement(['7B', '8B', '13B']),
            'quantization' => $this->faker->randomElement(['Q4_K_M', 'Q4_0', 'Q8_0']),
            'available' => true,
        ];
    }
}
