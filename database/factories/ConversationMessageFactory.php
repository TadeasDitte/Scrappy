<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationMessage>
 */
class ConversationMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => 'user',
            'content' => $this->faker->sentence(),
            'latency_ms' => null,
        ];
    }

    /**
     * A reply from the model.
     */
    public function assistant(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => 'assistant',
            'latency_ms' => $this->faker->numberBetween(200, 8000),
        ]);
    }
}
