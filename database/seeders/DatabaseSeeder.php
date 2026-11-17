<?php

namespace Database\Seeders;

use App\Enums\AccountColor;
use App\Enums\AccountType;
use App\Enums\CardType;
use App\Enums\TransactionCategory as Cat;
use App\Enums\TransactionType;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $accounts = $user->accounts()->createMany([
            ['name' => 'Nubank', 'type' => AccountType::Digital, 'color' => AccountColor::Violet, 'initial_balance' => 125050],
            ['name' => 'Conta Corrente Itaú', 'type' => AccountType::Checking, 'color' => AccountColor::Orange, 'initial_balance' => 320000],
            ['name' => 'Poupança', 'type' => AccountType::Savings, 'color' => AccountColor::Emerald, 'initial_balance' => 800000],
        ]);

        $cards = $user->cards()->createMany([
            ['account_id' => $accounts[0]->id, 'name' => 'Nubank Crédito', 'type' => CardType::Credit, 'credit_limit' => 450000, 'closing_day' => 5, 'due_day' => 12],
            ['account_id' => $accounts[1]->id, 'name' => 'Itaú Débito', 'type' => CardType::Debit],
        ]);

        $start = now()->startOfMonth();
        $day = fn (int $n) => $start->addDays($n)->toDateString();
        $in = TransactionType::Income;
        $out = TransactionType::Expense;

        $accounts[0]->transactions()->createMany([
            ['user_id' => $user->id, 'type' => $in, 'description' => 'Salário', 'amount' => 450000, 'date' => $day(0), 'category' => Cat::Salary],
            ['user_id' => $user->id, 'type' => $out, 'description' => 'Aluguel', 'amount' => 150000, 'date' => $day(1), 'category' => Cat::Housing],
            ['user_id' => $user->id, 'type' => $out, 'description' => 'Supermercado', 'amount' => 38790, 'date' => $day(2), 'category' => Cat::Food],
            ['user_id' => $user->id, 'type' => $out, 'description' => 'Conta de luz', 'amount' => 21050, 'date' => $day(3), 'category' => Cat::Bills],
        ]);

        $accounts[1]->transactions()->createMany([
            ['user_id' => $user->id, 'type' => $in, 'description' => 'Freelance', 'amount' => 80000, 'date' => $day(1), 'category' => Cat::OtherIncome],
            ['user_id' => $user->id, 'type' => $out, 'description' => 'Plano de saúde', 'amount' => 45000, 'date' => $day(2), 'category' => Cat::Health],
        ]);

        $cards[0]->transactions()->createMany([
            ['user_id' => $user->id, 'type' => $out, 'description' => 'Restaurante', 'amount' => 12890, 'date' => $day(1), 'category' => Cat::Food],
            ['user_id' => $user->id, 'type' => $out, 'description' => 'Assinatura de streaming', 'amount' => 3990, 'date' => $day(2), 'category' => Cat::Leisure],
            ['user_id' => $user->id, 'type' => $out, 'description' => 'Tênis', 'amount' => 29990, 'date' => $day(3), 'category' => Cat::Shopping],
        ]);

        $cards[1]->transactions()->createMany([
            ['user_id' => $user->id, 'type' => $out, 'description' => 'Combustível', 'amount' => 25000, 'date' => $day(2), 'category' => Cat::Transport],
        ]);
    }
}
