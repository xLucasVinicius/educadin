<?php

namespace Database\Factories;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Card;
use App\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringTransaction>
 */
class RecurringTransactionFactory extends Factory
{
    public function definition(): array
    {
        $date = now()->startOfMonth()->addDays(9);

        return [
            'user_id' => User::factory(),
            'account_id' => fn (array $attributes) => Account::factory()->create(['user_id' => $attributes['user_id']])->id,
            'card_id' => null,
            'type' => TransactionType::Expense,
            'description' => fake()->words(2, true),
            'amount' => fake()->numberBetween(500, 50000),
            'category' => TransactionCategory::Bills,
            'day_of_month' => $date->day,
            'next_due_on' => $date->toDateString(),
        ];
    }

    public function forAccount(Account $account): static
    {
        return $this->state(fn () => ['user_id' => $account->user_id, 'account_id' => $account->id, 'card_id' => null]);
    }

    public function forCard(Card $card): static
    {
        return $this->state(fn () => ['user_id' => $card->user_id, 'account_id' => null, 'card_id' => $card->id]);
    }

    /** Define o primeiro dia da recorrência (e o dia do mês das próximas). */
    public function startingOn(string $date): static
    {
        return $this->state(fn () => [
            'day_of_month' => (int) substr($date, 8, 2),
            'next_due_on' => $date,
        ]);
    }
}
