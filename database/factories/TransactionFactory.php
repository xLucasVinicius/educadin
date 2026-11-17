<?php

namespace Database\Factories;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Card;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => fn (array $attributes) => Account::factory()->create(['user_id' => $attributes['user_id']])->id,
            'card_id' => null,
            'type' => TransactionType::Expense,
            'description' => fake()->sentence(3),
            'amount' => fake()->numberBetween(500, 50000),
            'date' => now()->toDateString(),
            'category' => TransactionCategory::Food,
        ];
    }

    public function income(): static
    {
        return $this->state(fn () => [
            'type' => TransactionType::Income,
            'category' => TransactionCategory::Salary,
        ]);
    }

    public function forAccount(Account $account): static
    {
        return $this->state(fn () => [
            'user_id' => $account->user_id,
            'account_id' => $account->id,
            'card_id' => null,
        ]);
    }

    public function forCard(Card $card): static
    {
        return $this->state(fn () => [
            'user_id' => $card->user_id,
            'account_id' => null,
            'card_id' => $card->id,
        ]);
    }
}
