<?php

namespace Database\Factories;

use App\Enums\CardType;
use App\Models\Account;
use App\Models\Card;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => fn (array $attributes) => Account::factory()->create(['user_id' => $attributes['user_id']])->id,
            'name' => fake()->unique()->words(2, true),
            'type' => CardType::Debit,
            'credit_limit' => null,
            'closing_day' => null,
            'due_day' => null,
        ];
    }

    public function credit(): static
    {
        return $this->state(fn () => [
            'type' => CardType::Credit,
            'credit_limit' => fake()->numberBetween(100000, 1000000),
            'closing_day' => fake()->numberBetween(1, 28),
            'due_day' => fake()->numberBetween(1, 28),
        ]);
    }
}
