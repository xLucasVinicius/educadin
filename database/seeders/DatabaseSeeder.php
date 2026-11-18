<?php

namespace Database\Seeders;

use App\Enums\AccountColor;
use App\Enums\AccountType;
use App\Enums\CardType;
use App\Enums\TransactionCategory as Cat;
use App\Enums\TransactionType;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    /**
     * Contas de teste para o painel de admin (senha de todas: "password").
     * O dono do sistema vem de ADMIN_EMAILS no .env, não do seeder.
     */
    private function seedAdminDemoUsers(): void
    {
        User::factory()->create(['name' => 'Admin Teste', 'email' => 'admin@example.com'])
            ->forceFill(['is_admin' => true])->save();

        User::factory()->create(['name' => 'Maria Silva', 'email' => 'maria@example.com', 'created_at' => now()->subDays(2)])
            ->forceFill(['plan' => 'premium', 'premium_until' => now()->addDays(30)->toDateString()])->save();

        User::factory()->create(['name' => 'João Souza', 'email' => 'joao@example.com', 'created_at' => now()->subDays(12)]);

        User::factory()->create(['name' => 'Ana Costa', 'email' => 'ana@example.com', 'created_at' => now()->subDays(25)])
            ->forceFill(['suspended_at' => now()->subDay()])->save();

        User::factory()->unverified()->create(['name' => 'Pedro Lima', 'email' => 'pedro@example.com', 'created_at' => now()->subHours(5)]);

        // Premium vencido com mais itens que o limite do Gratuito: mostra o bloqueio.
        $expired = User::factory()->create(['name' => 'Rafael Prado', 'email' => 'rafael@example.com', 'created_at' => now()->subDays(90)]);
        $expired->forceFill(['plan' => 'premium', 'premium_until' => now()->subDays(3)->toDateString()])->save();
        $expiredAccounts = $expired->accounts()->createMany([
            ['name' => 'Conta principal', 'type' => AccountType::Checking, 'color' => AccountColor::Orange, 'initial_balance' => 150000],
            ['name' => 'Carteira', 'type' => AccountType::Salary, 'color' => AccountColor::Emerald, 'initial_balance' => 20000],
            ['name' => 'Reserva', 'type' => AccountType::Savings, 'color' => AccountColor::Violet, 'initial_balance' => 500000],
            ['name' => 'Conta extra', 'type' => AccountType::Digital, 'color' => AccountColor::Sky, 'initial_balance' => 80000],
        ]);
        $expired->cards()->createMany([
            ['account_id' => $expiredAccounts[0]->id, 'name' => 'Crédito principal', 'type' => CardType::Credit, 'credit_limit' => 300000, 'closing_day' => 20, 'due_day' => 28],
            ['account_id' => $expiredAccounts[0]->id, 'name' => 'Débito', 'type' => CardType::Debit],
            ['account_id' => $expiredAccounts[1]->id, 'name' => 'Crédito extra', 'type' => CardType::Credit, 'credit_limit' => 100000, 'closing_day' => 10, 'due_day' => 18],
        ]);

        User::factory()->create(['name' => 'Carla Dias', 'email' => 'carla@example.com', 'created_at' => now()->subDays(60)])
            ->forceFill(['plan' => 'premium'])->save();
    }

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
            ['account_id' => $accounts[0]->id, 'name' => 'Nubank Crédito', 'type' => CardType::Credit, 'credit_limit' => 450000, 'closing_day' => 25, 'due_day' => 5],
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

        Transfer::create([
            'user_id' => $user->id, 'from_account_id' => $accounts[0]->id, 'to_account_id' => $accounts[2]->id,
            'description' => 'Reserva do mês', 'amount' => 20000, 'date' => $day(4),
        ]);

        $cards[0]->transactions()->createMany([
            ['user_id' => $user->id, 'type' => $out, 'description' => 'Restaurante', 'amount' => 12890, 'date' => $day(1), 'category' => Cat::Food],
            ['user_id' => $user->id, 'type' => $out, 'description' => 'Assinatura de streaming', 'amount' => 3990, 'date' => $day(2), 'category' => Cat::Leisure],
            ['user_id' => $user->id, 'type' => $out, 'description' => 'Tênis', 'amount' => 29990, 'date' => $day(3), 'category' => Cat::Shopping],
        ]);

        // Compra parcelada: Fone de ouvido em 3x (uma parcela por fatura).
        $group = (string) Str::uuid();
        foreach ([1, 2, 3] as $number) {
            $cards[0]->transactions()->create([
                'user_id' => $user->id, 'type' => $out, 'description' => 'Fone de ouvido', 'amount' => 20000,
                'date' => $start->addDays(5)->addMonthsNoOverflow($number - 1)->toDateString(), 'category' => Cat::Shopping,
                'installment_group' => $group, 'installment_number' => $number, 'installment_total' => 3,
            ]);
        }

        // Assinatura recorrente no cartão: lançada todo mês, a partir do dia 2 do mês atual.
        $recurring = $cards[0]->recurringTransactions()->create([
            'user_id' => $user->id, 'type' => $out, 'description' => 'Plano de streaming', 'amount' => 3990,
            'category' => Cat::Leisure, 'day_of_month' => 2, 'next_due_on' => $day(1),
        ]);
        $recurring->generateDue();

        $this->seedAdminDemoUsers();

        $cards[1]->transactions()->createMany([
            ['user_id' => $user->id, 'type' => $out, 'description' => 'Combustível', 'amount' => 25000, 'date' => $day(2), 'category' => Cat::Transport],
        ]);
    }
}
