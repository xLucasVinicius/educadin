<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transfer>
 */
class TransferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'from_account_id' => fn (array $attributes) => Account::factory()->create(['user_id' => $attributes['user_id']])->id,
            'to_account_id' => fn (array $attributes) => Account::factory()->create(['user_id' => $attributes['user_id']])->id,
            'description' => null,
            'amount' => fake()->numberBetween(500, 50000),
            'date' => now()->toDateString(),
        ];
    }

    public function between(Account $from, Account $to): static
    {
        return $this->state(fn () => [
            'user_id' => $from->user_id,
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
        ]);
    }
}
