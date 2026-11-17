<?php

namespace Database\Factories;

use App\Enums\AccountColor;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => fake()->randomElement(AccountType::cases()),
            'color' => fake()->randomElement(AccountColor::cases()),
            'initial_balance' => fake()->numberBetween(0, 500000),
        ];
    }
}
