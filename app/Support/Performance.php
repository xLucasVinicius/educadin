<?php

namespace App\Support;

use App\Enums\TransactionCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Consultas da tela de Desempenho. Valores sempre em centavos.
 *
 * - Desempenho anual: só o que passou pelas contas (inclui o pagamento da fatura).
 * - Categorias: contas e cartões juntos, sem o pagamento da fatura (já está nas compras).
 */
class Performance
{
    /** Cores das categorias (da maior para a menor). */
    public const PALETTE = ['#f2a900', '#38bdf8', '#34d399', '#a78bfa', '#fb7185', '#fb923c', '#facc15', '#2dd4bf', '#71717a'];

    /**
     * Anos com lançamentos até o atual, do mais novo para o mais antigo.
     *
     * @return array<int, int>
     */
    public static function years(int $userId): array
    {
        $first = DB::table('transactions')->where('user_id', $userId)->min('date');
        $current = (int) now()->year;
        $start = $first ? min((int) substr((string) $first, 0, 4), $current) : $current;

        return range($current, $start);
    }

    /**
     * Meses ("Y-m") do primeiro lançamento até o atual, do mais novo para o mais antigo.
     *
     * @return array<int, string>
     */
    public static function months(int $userId): array
    {
        $first = DB::table('transactions')->where('user_id', $userId)->min('date');
        $current = now()->toImmutable()->startOfMonth();
        $cursor = $first ? CarbonImmutable::parse(substr((string) $first, 0, 7).'-01') : $current;

        if ($cursor->greaterThan($current)) {
            $cursor = $current;
        }

        $months = [];
        for ($month = $current; $month->greaterThanOrEqualTo($cursor) && count($months) < 600; $month = $month->subMonthNoOverflow()) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }

    /**
     * Nomes curtos dos 12 meses (Jan, Fev...), no idioma do app.
     *
     * @return array<int, array{label: string, full: string}>
     */
    public static function monthNames(int $year): array
    {
        return array_map(function (int $m) use ($year) {
            $date = CarbonImmutable::create($year, $m, 1)->locale(app()->getLocale());

            return [
                'label' => Str::ucfirst($date->translatedFormat('M')),
                'full' => Str::ucfirst($date->translatedFormat('F \d\e Y')),
            ];
        }, range(1, 12));
    }

    /**
     * Receitas e despesas das contas em cada mês do ano.
     *
     * @return array<int, array{income: int, expense: int}> índice 0 = janeiro
     */
    public static function annual(int $userId, int $year): array
    {
        $rows = DB::table('transactions')
            ->where('user_id', $userId)
            ->whereNotNull('account_id')
            ->whereBetween('date', ["$year-01-01", "$year-12-31"])
            ->selectRaw('substr(date, 6, 2) as m, type, sum(amount) as total')
            ->groupBy('m', 'type')
            ->get();

        $months = array_fill(0, 12, ['income' => 0, 'expense' => 0]);

        foreach ($rows as $row) {
            $months[(int) $row->m - 1][$row->type === 'income' ? 'income' : 'expense'] = (int) $row->total;
        }

        return $months;
    }

    /**
     * Gasto de cada categoria em cada mês do ano (maiores primeiro).
     *
     * @return array<string, array{label: string, months: array<int, int>, total: int}> chave = valor da categoria
     */
    public static function categoriesByMonth(int $userId, int $year): array
    {
        $rows = DB::table('transactions')
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->where('category', '!=', TransactionCategory::CardBill->value)
            ->whereBetween('date', ["$year-01-01", "$year-12-31"])
            ->selectRaw('category, substr(date, 6, 2) as m, sum(amount) as total')
            ->groupBy('category', 'm')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[$row->category] ??= [
                'label' => TransactionCategory::tryFrom($row->category)?->label() ?? $row->category,
                'months' => array_fill(0, 12, 0),
                'total' => 0,
            ];
            $result[$row->category]['months'][(int) $row->m - 1] = (int) $row->total;
            $result[$row->category]['total'] += (int) $row->total;
        }

        uasort($result, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return $result;
    }

    /**
     * Gastos de um mês ("Y-m") por categoria, com a participação de cada uma.
     * Passando do limite, as menores viram "Outras".
     *
     * @return array<int, array{label: string, amount: int, percent: float, share: float, start: float, color: string}>
     */
    public static function spendingByCategory(int $userId, string $ym, int $limit = 8): array
    {
        $rows = DB::table('transactions')
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->where('category', '!=', TransactionCategory::CardBill->value)
            ->whereBetween('date', [$ym.'-01', $ym.'-31'])
            ->selectRaw('category, sum(amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $total = (int) $rows->sum('total');

        if ($total === 0) {
            return [];
        }

        $items = $rows->map(fn ($row) => [
            'label' => TransactionCategory::tryFrom($row->category)?->label() ?? $row->category,
            'amount' => (int) $row->total,
        ])->all();

        if (count($items) > $limit) {
            $others = array_slice($items, $limit);
            $items = array_slice($items, 0, $limit);
            $items[] = ['label' => __('Others'), 'amount' => array_sum(array_column($others, 'amount'))];
        }

        $start = 0.0;

        return array_map(function (array $item, int $index) use ($total, &$start) {
            $share = $item['amount'] / $total * 100;
            $entry = $item + [
                'percent' => round($share, 1),
                'share' => $share,
                'start' => $start,
                'color' => self::PALETTE[$index % count(self::PALETTE)],
            ];
            $start += $share;

            return $entry;
        }, $items, array_keys($items));
    }

    /**
     * Gastos essenciais x não essenciais de um mês ("Y-m").
     *
     * @return array{essential: int, other: int}
     */
    public static function essentialSplit(int $userId, string $ym): array
    {
        $rows = DB::table('transactions')
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->where('category', '!=', TransactionCategory::CardBill->value)
            ->whereBetween('date', [$ym.'-01', $ym.'-31'])
            ->selectRaw('category, sum(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        $split = ['essential' => 0, 'other' => 0];

        foreach ($rows as $category => $total) {
            $key = TransactionCategory::tryFrom($category)?->isEssential() ? 'essential' : 'other';
            $split[$key] += (int) $total;
        }

        return $split;
    }

    /**
     * Receitas e despesas das contas em um mês ("Y-m"), como no desempenho anual.
     *
     * @return array{income: int, expense: int}
     */
    public static function monthTotals(int $userId, string $ym): array
    {
        $rows = DB::table('transactions')
            ->where('user_id', $userId)
            ->whereNotNull('account_id')
            ->whereBetween('date', [$ym.'-01', $ym.'-31'])
            ->selectRaw('type, sum(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return ['income' => (int) ($rows['income'] ?? 0), 'expense' => (int) ($rows['expense'] ?? 0)];
    }

    /**
     * Variação percentual entre dois valores (null quando não há base de comparação).
     */
    public static function variation(int $current, int $previous): ?float
    {
        return $previous === 0 ? null : round(($current - $previous) / abs($previous) * 100, 1);
    }
}
