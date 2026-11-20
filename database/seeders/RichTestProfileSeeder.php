<?php

namespace Database\Seeders;

use App\Enums\AccountColor;
use App\Enums\AccountType;
use App\Enums\CardType;
use App\Enums\TransactionCategory as Cat;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Card;
use App\Models\Transfer;
use App\Models\User;
use App\Support\Avatar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Deixa o usuário de teste com dados de sobra para ver todas as telas:
 * 6 contas, 5 cartões, 12 meses de histórico, parcelados em andamento e
 * concluídos, recorrentes (inclusive cancelada), faturas pagas, parcial,
 * vencida e aberta, transferências e foto de perfil.
 */
class RichTestProfileSeeder
{
    private User $user;

    private CarbonImmutable $month;

    /**
     * @param  Collection<int, Account>  $accounts  Nubank, Itaú, Poupança
     * @param  Collection<int, Card>  $cards  Nubank Crédito, Itaú Débito
     */
    public function seed(User $user, Collection $accounts, Collection $cards): void
    {
        mt_srand(20261118);
        $this->user = $user;
        $this->month = now()->startOfMonth()->toImmutable();

        $user->forceFill(['plan' => 'premium', 'premium_until' => null])->save();
        self::photo($user, [242, 169, 0]);

        [$nubank, $itau, $savings] = [$accounts[0], $accounts[1], $accounts[2]];
        $wallet = $user->accounts()->create(['name' => 'Carteira', 'type' => AccountType::Salary, 'color' => AccountColor::Teal, 'initial_balance' => 35000]);
        $inter = $user->accounts()->create(['name' => 'Inter', 'type' => AccountType::Digital, 'color' => AccountColor::Amber, 'initial_balance' => 210000]);
        $reserve = $user->accounts()->create(['name' => 'Reserva de emergência', 'type' => AccountType::Savings, 'color' => AccountColor::Sky, 'initial_balance' => 1500000]);

        $nubankCredit = $cards[0];
        $itauCredit = $user->cards()->create(['account_id' => $itau->id, 'name' => 'Itaú Crédito', 'type' => CardType::Credit, 'credit_limit' => 600000, 'closing_day' => 10, 'due_day' => 18]);
        $interCredit = $user->cards()->create(['account_id' => $inter->id, 'name' => 'Inter Crédito', 'type' => CardType::Credit, 'credit_limit' => 250000, 'closing_day' => 15, 'due_day' => 22]);
        $interDebit = $user->cards()->create(['account_id' => $inter->id, 'name' => 'Inter Débito', 'type' => CardType::Debit]);
        $itauDebit = $cards[1];

        for ($m = 11; $m >= 1; $m--) {
            $this->history($m, $nubank, $itau, $savings, $reserve, $inter, $wallet, $nubankCredit, $itauCredit, $interCredit, $itauDebit, $interDebit);
        }

        $this->currentMonthExtras($inter, $wallet, $itauCredit, $interCredit, $interDebit);
        $this->installments($nubankCredit, $itauCredit, $interCredit);
        $this->recurring($inter, $itau, $itauCredit, $interCredit);
        $this->invoicePayments([$nubankCredit, $itauCredit, $interCredit]);
    }

    /** Foto de exemplo (silhueta sobre degradê), para ver o perfil e o admin com imagem. */
    public static function photo(User $user, array $rgb): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            return;
        }

        $size = Avatar::SIZE;
        $img = imagecreatetruecolor($size, $size);

        for ($y = 0; $y < $size; $y++) {
            $t = $y / $size;
            $color = imagecolorallocate($img, (int) ($rgb[0] * (1 - $t * .55)), (int) ($rgb[1] * (1 - $t * .55)), (int) ($rgb[2] * (1 - $t * .55)));
            imageline($img, 0, $y, $size, $y, $color);
        }

        $shape = imagecolorallocate($img, 255, 255, 255);
        imagefilledellipse($img, (int) ($size / 2), (int) ($size * .38), (int) ($size * .34), (int) ($size * .34), $shape);
        imagefilledellipse($img, (int) ($size / 2), (int) ($size * .95), (int) ($size * .78), (int) ($size * .62), $shape);

        ob_start();
        imagejpeg($img, null, 88);
        $jpeg = ob_get_clean();

        if ($jpeg) {
            Avatar::store($user, $jpeg);
        }
    }

    private function at(int $monthsAgo, int $day): CarbonImmutable
    {
        $base = $this->month->subMonthsNoOverflow($monthsAgo);

        return $base->day(min($day, $base->daysInMonth));
    }

    private function rand(int $min, int $max): int
    {
        return (int) (round(mt_rand($min, $max) / 10) * 10);
    }

    private function add(Account|Card $owner, string $type, string $description, int $amount, CarbonImmutable $date, Cat $category): void
    {
        $owner->transactions()->create([
            'user_id' => $this->user->id,
            'type' => $type === 'income' ? TransactionType::Income : TransactionType::Expense,
            'description' => $description,
            'amount' => $amount,
            'date' => $date->toDateString(),
            'category' => $category,
        ]);
    }

    private function history(int $m, Account $nubank, Account $itau, Account $savings, Account $reserve, Account $inter, Account $wallet, Card $nubankCredit, Card $itauCredit, Card $interCredit, Card $itauDebit, Card $interDebit): void
    {
        // Receitas
        $this->add($nubank, 'income', 'Salário', $m > 6 ? 450000 : 480000, $this->at($m, 5), Cat::Salary);
        if ($m % 2 === 0) {
            $this->add($itau, 'income', 'Freelance', $this->rand(60000, 150000), $this->at($m, 12), Cat::OtherIncome);
        }
        $this->add($savings, 'income', 'Rendimentos', $this->rand(4000, 6500), $this->at($m, 28), Cat::Returns);
        if ($m === 3) {
            $this->add($nubank, 'income', 'Presente de aniversário', 20000, $this->at($m, 14), Cat::Gift);
        }

        // Despesas fixas nas contas
        $this->add($nubank, 'expense', 'Aluguel', 150000, $this->at($m, 6), Cat::Housing);
        $this->add($itau, 'expense', 'Condomínio', 45000, $this->at($m, 8), Cat::Housing);
        $this->add($itau, 'expense', 'Plano de saúde', 45000, $this->at($m, 2), Cat::Health);
        $this->add($itau, 'expense', 'Conta de luz', $this->rand(15000, 26000), $this->at($m, 10), Cat::Bills);
        $this->add($itau, 'expense', 'Conta de água', $this->rand(6000, 9000), $this->at($m, 11), Cat::Bills);
        $this->add($itau, 'expense', 'Curso de programação', 29900, $this->at($m, 15), Cat::Education);
        $this->add($inter, 'expense', 'Academia', 12990, $this->at($m, 4), Cat::Health);
        $this->add($wallet, 'expense', 'Padaria e feira', $this->rand(4000, 9000), $this->at($m, 9), Cat::Food);
        if ($m % 3 === 0) {
            $this->add($nubank, 'expense', 'Farmácia', $this->rand(8000, 18000), $this->at($m, 17), Cat::Health);
        }

        foreach ([3, 12, 22] as $day) {
            $this->add($nubank, 'expense', 'Supermercado', $this->rand(25000, 42000), $this->at($m, $day), Cat::Food);
        }

        // Débito
        foreach ([9, 24] as $day) {
            $this->add($itauDebit, 'expense', 'Combustível', $this->rand(18000, 27000), $this->at($m, $day), Cat::Transport);
        }
        $this->add($interDebit, 'expense', 'Lanche', $this->rand(2500, 6000), $this->at($m, 18), Cat::Food);

        // Crédito
        $this->add($nubankCredit, 'expense', 'Restaurante', $this->rand(8000, 16000), $this->at($m, 7), Cat::Food);
        $this->add($nubankCredit, 'expense', 'Restaurante', $this->rand(8000, 16000), $this->at($m, 20), Cat::Food);
        $this->add($nubankCredit, 'expense', 'Assinatura de streaming', 3990, $this->at($m, 2), Cat::Leisure);
        $this->add($nubankCredit, 'expense', 'Compras da semana', $this->rand(15000, 45000), $this->at($m, 13), Cat::Shopping);
        $this->add($nubankCredit, 'expense', 'Cinema', $this->rand(6000, 12000), $this->at($m, 21), Cat::Leisure);

        foreach ([4, 14, 26] as $day) {
            $this->add($itauCredit, 'expense', 'Transporte por aplicativo', $this->rand(1800, 4200), $this->at($m, $day), Cat::Transport);
        }
        $this->add($itauCredit, 'expense', 'Roupas', $this->rand(12000, 38000), $this->at($m, 16), Cat::Shopping);
        if ($m % 4 === 0) {
            $this->add($itauCredit, 'expense', 'Livros', $this->rand(6000, 14000), $this->at($m, 19), Cat::Education);
        }

        foreach ([3, 8, 17, 27] as $day) {
            $this->add($interCredit, 'expense', 'Delivery', $this->rand(3000, 8000), $this->at($m, $day), Cat::Food);
        }
        $this->add($interCredit, 'expense', 'Jogos', $this->rand(4000, 15000), $this->at($m, 11), Cat::Leisure);
        if ($m % 5 === 0) {
            $this->add($interCredit, 'expense', 'Eletrônicos', $this->rand(20000, 40000), $this->at($m, 23), Cat::Shopping);
        }

        // Transferências
        Transfer::create(['user_id' => $this->user->id, 'from_account_id' => $nubank->id, 'to_account_id' => $savings->id, 'description' => 'Reserva do mês', 'amount' => 20000, 'date' => $this->at($m, 7)->toDateString()]);
        if ($m % 3 === 0) {
            Transfer::create(['user_id' => $this->user->id, 'from_account_id' => $itau->id, 'to_account_id' => $reserve->id, 'description' => 'Aporte na reserva', 'amount' => 100000, 'date' => $this->at($m, 20)->toDateString()]);
        }
        Transfer::create(['user_id' => $this->user->id, 'from_account_id' => $inter->id, 'to_account_id' => $wallet->id, 'description' => 'Saque para a carteira', 'amount' => 20000, 'date' => $this->at($m, 10)->toDateString()]);
    }

    private function currentMonthExtras(Account $inter, Account $wallet, Card $itauCredit, Card $interCredit, Card $interDebit): void
    {
        $this->add($inter, 'income', 'Cashback', 3200, $this->at(0, 1), Cat::Returns);
        $this->add($wallet, 'expense', 'Padaria e feira', 6350, $this->at(0, 2), Cat::Food);
        $this->add($itauCredit, 'expense', 'Transporte por aplicativo', 2890, $this->at(0, 1), Cat::Transport);
        $this->add($itauCredit, 'expense', 'Roupas', 21990, $this->at(0, 3), Cat::Shopping);
        $this->add($interCredit, 'expense', 'Delivery', 5490, $this->at(0, 2), Cat::Food);
        $this->add($interCredit, 'expense', 'Jogos', 8990, $this->at(0, 3), Cat::Leisure);
        $this->add($interDebit, 'expense', 'Lanche', 3150, $this->at(0, 3), Cat::Food);
    }

    /** Parcelados: em andamento, concluído e que só começa neste mês. */
    private function installments(Card $nubankCredit, Card $itauCredit, Card $interCredit): void
    {
        $this->installment($itauCredit, 'Notebook', 49990, 10, $this->at(4, 6), Cat::Shopping);
        $this->installment($nubankCredit, 'Geladeira', 32500, 12, $this->at(2, 9), Cat::Housing);
        $this->installment($interCredit, 'Viagem para o Rio', 28000, 6, $this->at(7, 12), Cat::Leisure);
        $this->installment($itauCredit, 'Curso de inglês', 15000, 4, $this->at(0, 3), Cat::Education);
    }

    private function installment(Card $card, string $description, int $amount, int $total, CarbonImmutable $start, Cat $category): void
    {
        $group = (string) Str::uuid();

        for ($number = 1; $number <= $total; $number++) {
            $card->transactions()->create([
                'user_id' => $this->user->id,
                'type' => TransactionType::Expense,
                'description' => $description,
                'amount' => $amount,
                'date' => $start->addMonthsNoOverflow($number - 1)->toDateString(),
                'category' => $category,
                'installment_group' => $group,
                'installment_number' => $number,
                'installment_total' => $total,
            ]);
        }
    }

    /** Recorrentes ativas em conta e cartão, mais uma já cancelada. */
    private function recurring(Account $inter, Account $itau, Card $itauCredit, Card $interCredit): void
    {
        $definitions = [
            [$inter, 'Academia mensal', 1299, 4, Cat::Health],
            [$itau, 'Internet fibra', 9990, 12, Cat::Bills],
            [$itauCredit, 'Seguro do carro', 18900, 20, Cat::Transport],
            [$interCredit, 'Música por assinatura', 2190, 8, Cat::Leisure],
        ];

        foreach ($definitions as [$owner, $description, $amount, $day, $category]) {
            $recurring = $owner->recurringTransactions()->create([
                'user_id' => $this->user->id,
                'type' => TransactionType::Expense,
                'description' => $description,
                'amount' => $description === 'Academia mensal' ? 12990 : $amount,
                'category' => $category,
                'day_of_month' => $day,
                'next_due_on' => $this->at(0, $day)->toDateString(),
            ]);
            $recurring->generateDue();
        }

        $cancelled = $inter->recurringTransactions()->create([
            'user_id' => $this->user->id,
            'type' => TransactionType::Expense,
            'description' => 'Doação mensal (cancelada)',
            'amount' => 5000,
            'category' => Cat::OtherExpense,
            'day_of_month' => 15,
            'next_due_on' => $this->at(0, 15)->toDateString(),
        ]);
        $cancelled->forceFill(['cancelled_at' => now()->subMonth()])->save();
    }

    /**
     * Faturas antigas pagas por inteiro, a do mês passado em aberto
     * (parcial no Inter Crédito) e a atual aberta.
     *
     * @param  array<int, Card>  $cards
     */
    private function invoicePayments(array $cards): void
    {
        $lastMonth = $this->month->subMonthNoOverflow()->format('Y-m');
        $thisMonth = $this->month->format('Y-m');

        foreach ($cards as $card) {
            $totals = $card->transactions()->get()
                ->groupBy(fn ($t) => $card->invoiceMonthFor($t->date->toDateString()))
                ->map(fn ($group) => (int) $group->sum('amount'))
                ->sortKeys();

            foreach ($totals as $ym => $total) {
                if ($ym >= $lastMonth && $ym !== $lastMonth) {
                    continue;
                }

                $amount = match (true) {
                    $ym < $lastMonth => $total,
                    $card->name === 'Inter Crédito' => (int) round($total * 0.4),
                    default => 0,
                };

                if ($amount <= 0 || $ym >= $thisMonth) {
                    continue;
                }

                $due = $card->dueDateFor(CarbonImmutable::parse($ym.'-01'));
                $paidAt = $due->greaterThan(now()) ? now()->toImmutable() : $due;

                $transaction = $card->account->transactions()->create([
                    'user_id' => $this->user->id,
                    'type' => TransactionType::Expense,
                    'category' => Cat::CardBill,
                    'description' => 'Fatura '.$card->name.' - '.substr($ym, 5, 2).'/'.substr($ym, 0, 4),
                    'amount' => $amount,
                    'date' => $paidAt->toDateString(),
                ]);

                $card->payments()->create([
                    'user_id' => $this->user->id,
                    'account_id' => $card->account_id,
                    'transaction_id' => $transaction->id,
                    'month' => $ym,
                    'amount' => $amount,
                    'paid_at' => $paidAt->toDateString(),
                ]);
            }
        }
    }
}
