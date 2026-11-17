<?php

use App\Enums\TransactionCategory;
use App\Models\Card;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /** Cores do gráfico de categorias (da maior para a menor fatia). */
    private const PALETTE = ['#f2a900', '#38bdf8', '#34d399', '#a78bfa', '#fb7185', '#fb923c', '#71717a'];

    /** Mês exibido no formato "2026-11". Vazio = mês atual. */
    public string $month = '';

    protected function monthStart(): CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            return CarbonImmutable::parse($this->month.'-01')->startOfDay();
        }

        return now()->startOfMonth();
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function monthRange(): array
    {
        $start = $this->monthStart();

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonthNoOverflow()->format('Y-m');
    }

    #[Computed]
    public function monthLabel(): string
    {
        return Str::ucfirst(
            $this->monthStart()->locale(app()->getLocale())->translatedFormat('F \d\e Y')
        );
    }

    #[Computed]
    public function hasAccounts(): bool
    {
        return Auth::user()->accounts()->exists();
    }

    /**
     * Soma do saldo atual de todas as contas, em centavos.
     */
    #[Computed]
    public function totalBalance(): int
    {
        return (int) Auth::user()->accounts()->withBalance()->get()
            ->sum(fn ($account) => $account->currentBalance());
    }

    /**
     * Receitas e despesas das contas no mês exibido (cartões ficam de fora).
     *
     * @return array{income: int, expense: int}
     */
    #[Computed]
    public function monthTotals(): array
    {
        $rows = DB::table('transactions')
            ->where('user_id', Auth::id())
            ->whereNotNull('account_id')
            ->whereBetween('date', $this->monthRange())
            ->selectRaw('type, sum(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            'income' => (int) ($rows['income'] ?? 0),
            'expense' => (int) ($rows['expense'] ?? 0),
        ];
    }

    /**
     * Gastos do mês (contas e cartões) agrupados por categoria.
     *
     * @return array<int, array{label: string, amount: int, percent: float, share: float, start: float, color: string}>
     */
    #[Computed]
    public function expensesByCategory(): array
    {
        $rows = DB::table('transactions')
            ->where('user_id', Auth::id())
            ->where('type', 'expense')
            // O pagamento da fatura já está nas compras do cartão: não contar duas vezes.
            ->where('category', '!=', TransactionCategory::CardBill->value)
            ->whereBetween('date', $this->monthRange())
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

        if (count($items) > 6) {
            $others = array_slice($items, 6);
            $items = array_slice($items, 0, 6);
            $items[] = ['label' => __('Others'), 'amount' => array_sum(array_column($others, 'amount'))];
        }

        $start = 0.0;

        return array_map(function (array $item, int $index) use ($total, &$start) {
            $share = $item['amount'] / $total * 100;
            $entry = $item + [
                'percent' => round($share, 1),
                'share' => $share,
                'start' => $start,
                'color' => self::PALETTE[$index],
            ];
            $start += $share;

            return $entry;
        }, $items, array_keys($items));
    }

    /**
     * Saldo total das contas no fim de cada um dos últimos 6 meses.
     *
     * @return array<int, array{label: string, value: int}>
     */
    #[Computed]
    public function balanceSeries(): array
    {
        $first = $this->monthStart()->subMonthsNoOverflow(5);
        $end = $this->monthStart()->endOfMonth();

        $running = (int) DB::table('accounts')->where('user_id', Auth::id())->sum('initial_balance');

        $nets = DB::table('transactions')
            ->where('user_id', Auth::id())
            ->whereNotNull('account_id')
            ->where('date', '<=', $end->toDateString())
            ->selectRaw("substr(date, 1, 7) as ym, sum(case when type = 'income' then amount else -amount end) as net")
            ->groupBy('ym')
            ->pluck('net', 'ym');

        // Tudo o que aconteceu antes do primeiro mês exibido já conta no ponto de partida.
        foreach ($nets as $ym => $net) {
            if ($ym < $first->format('Y-m')) {
                $running += (int) $net;
            }
        }

        $series = [];

        for ($i = 0; $i < 6; $i++) {
            $month = $first->addMonthsNoOverflow($i);
            $running += (int) ($nets[$month->format('Y-m')] ?? 0);

            $series[] = [
                'label' => Str::ucfirst($month->locale(app()->getLocale())->translatedFormat('M')),
                'value' => $running,
            ];
        }

        return $series;
    }

    /**
     * Geometria (SVG) do gráfico de evolução do saldo.
     *
     * @return array{line: string, area: string, dots: array<int, array{x: float, y: float, label: string, value: int}>, zeroY: float|null, width: int, height: int}
     */
    #[Computed]
    public function chart(): array
    {
        $series = $this->balanceSeries;
        $width = 600;
        $height = 200;
        $padX = 24;
        $padY = 24;

        $values = array_column($series, 'value');
        $min = min($values);
        $max = max($values);
        $spread = ($max - $min) ?: 1000;
        $min -= $spread * 0.1;
        $max += $spread * 0.1;

        $count = count($series);
        $x = fn (int $i): float => $padX + $i * ($width - 2 * $padX) / ($count - 1);
        $y = fn (int|float $v): float => $padY + ($max - $v) / ($max - $min) * ($height - 2 * $padY);

        $dots = [];
        foreach ($series as $i => $point) {
            $dots[] = ['x' => round($x($i), 1), 'y' => round($y($point['value']), 1)] + $point;
        }

        $line = implode(' ', array_map(fn ($d) => $d['x'].','.$d['y'], $dots));
        $last = end($dots);
        $firstDot = $dots[0];
        $area = 'M '.$firstDot['x'].','.$firstDot['y'].' L '
            .implode(' L ', array_map(fn ($d) => $d['x'].','.$d['y'], $dots))
            .' L '.$last['x'].','.$height.' L '.$firstDot['x'].','.$height.' Z';

        return [
            'line' => $line,
            'area' => $area,
            'dots' => $dots,
            'zeroY' => ($min <= 0 && $max >= 0) ? round($y(0), 1) : null,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Cartões com o gasto do mês exibido.
     *
     * @return Collection<int, Card>
     */
    #[Computed]
    public function cards(): Collection
    {
        $range = $this->monthRange();

        return Auth::user()->cards()
            ->with('account')
            ->withSum(['transactions as spent' => fn ($q) => $q->where('type', 'expense')->whereBetween('date', $range)], 'amount')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Transaction>
     */
    #[Computed]
    public function recentTransactions(): Collection
    {
        return Auth::user()->transactions()
            ->with(['account', 'card'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(12)
            ->get();
    }
}; ?>

{{-- Em telas grandes o painel ocupa exatamente uma tela; as listas rolam por dentro. --}}
<section class="flex w-full flex-col gap-4 lg:h-[calc(100dvh-4.5rem)]">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
            <flux:subheading>{{ __('A summary of your finances.') }}</flux:subheading>
        </div>

        <div class="flex items-center rounded-lg border border-zinc-800">
            <flux:button variant="ghost" size="sm" icon="chevron-left" wire:click="previousMonth" :aria-label="__('Previous month')" />
            <span class="min-w-36 px-1 text-center text-sm font-medium">{{ $this->monthLabel }}</span>
            <flux:button variant="ghost" size="sm" icon="chevron-right" wire:click="nextMonth" :aria-label="__('Next month')" />
        </div>
    </div>

    @if (! $this->hasAccounts)
        <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-700 px-6 py-16 text-center">
            <flux:icon.building-library class="size-10 text-zinc-500" />
            <flux:heading>{{ __('Welcome to EducaDin!') }}</flux:heading>
            <flux:text>{{ __('Create your first account to see your numbers here.') }}</flux:text>
            <flux:button variant="primary" icon="plus" :href="route('accounts.index')" wire:navigate>{{ __('Go to accounts') }}</flux:button>
        </div>
    @else
        @php
            $totals = $this->monthTotals;
            $net = $totals['income'] - $totals['expense'];
            $balance = $this->totalBalance;
        @endphp

        {{-- Números do mês --}}
        <div class="grid shrink-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 px-4 py-3">
                <div class="text-xs text-zinc-400">{{ __('Total balance') }}</div>
                <div @class(['mt-1 text-xl font-semibold', 'text-white' => $balance >= 0, 'text-red-400' => $balance < 0])>
                    {{ Money::format($balance) }}
                </div>
                <div class="text-[11px] text-zinc-500">{{ __('Sum of all your accounts') }}</div>
            </div>

            <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 px-4 py-3">
                <div class="text-xs text-zinc-400">{{ __('Income in the month') }}</div>
                <div class="mt-1 text-xl font-semibold text-emerald-400">{{ Money::format($totals['income']) }}</div>
                <div class="text-[11px] text-zinc-500">{{ __('In your accounts') }}</div>
            </div>

            <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 px-4 py-3">
                <div class="text-xs text-zinc-400">{{ __('Expenses in the month') }}</div>
                <div class="mt-1 text-xl font-semibold text-red-400">{{ Money::format($totals['expense']) }}</div>
                <div class="text-[11px] text-zinc-500">{{ __('In your accounts') }}</div>
            </div>

            <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 px-4 py-3">
                <div class="text-xs text-zinc-400">{{ __('Result of the month') }}</div>
                <div @class(['mt-1 text-xl font-semibold', 'text-emerald-400' => $net > 0, 'text-red-400' => $net < 0, 'text-white' => $net === 0])>
                    {{ $net > 0 ? '+' : '' }}{{ Money::format($net) }}
                </div>
                <div class="text-[11px] text-zinc-500">{{ __('Income minus expenses') }}</div>
            </div>
        </div>

        <div class="grid min-h-0 flex-1 gap-4 lg:grid-cols-2 lg:grid-rows-2">
            {{-- Gastos por categoria --}}
            <div class="flex min-h-0 flex-col rounded-xl border border-zinc-800 bg-zinc-900/60 p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <flux:heading>{{ __('Spending by category') }}</flux:heading>
                    <span class="text-xs text-zinc-500">{{ __('Accounts and cards together') }}</span>
                </div>

                @if (empty($this->expensesByCategory))
                    <div class="flex flex-1 items-center justify-center py-6 text-sm text-zinc-500">{{ __('No expenses in this month.') }}</div>
                @else
                    <div class="mt-3 flex min-h-0 flex-1 flex-wrap items-center gap-x-6 gap-y-3 overflow-y-auto">
                        <svg viewBox="-1 -1 38 38" class="size-28 shrink-0 -rotate-90 overflow-visible" role="img" aria-label="{{ __('Spending by category') }}">
                            <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#27272a" stroke-width="5" />
                            @foreach ($this->expensesByCategory as $slice)
                                <circle
                                    cx="18" cy="18" r="15.9155" fill="none"
                                    stroke="{{ $slice['color'] }}" stroke-width="5"
                                    stroke-dasharray="{{ round($slice['share'], 3) }} {{ round(100 - $slice['share'], 3) }}"
                                    stroke-dashoffset="{{ round(-$slice['start'], 3) }}"
                                >
                                    <title>{{ $slice['label'] }}: {{ Money::format($slice['amount']) }}</title>
                                </circle>
                            @endforeach
                        </svg>

                        <ul class="min-w-[11rem] flex-1 space-y-1.5 text-xs">
                            @foreach ($this->expensesByCategory as $slice)
                                <li class="flex items-center gap-2">
                                    <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $slice['color'] }}"></span>
                                    <span class="flex-1 truncate text-zinc-300">{{ $slice['label'] }}</span>
                                    <span class="text-zinc-500">{{ number_format($slice['percent'], 1, ',', '.') }}%</span>
                                    <span class="w-20 text-right font-medium text-white">{{ Money::format($slice['amount']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            {{-- Evolução do saldo --}}
            <div class="flex min-h-0 flex-col rounded-xl border border-zinc-800 bg-zinc-900/60 p-4">
                @php
                    $chart = $this->chart;
                @endphp

                <div class="flex items-baseline justify-between gap-2">
                    <flux:heading>{{ __('Balance evolution') }}</flux:heading>
                    <span class="text-xs text-zinc-500">
                        {{ __('Now') }}: <span class="font-medium text-white">{{ Money::format(end($chart['dots'])['value']) }}</span>
                    </span>
                </div>
                <div class="text-xs text-zinc-500">{{ __('Total balance at the end of each of the last 6 months') }}</div>

                <svg viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] + 24 }}" class="mt-2 min-h-0 w-full flex-1" role="img" aria-label="{{ __('Balance evolution') }}">
                    <defs>
                        <linearGradient id="balance-fill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#f2a900" stop-opacity="0.35" />
                            <stop offset="100%" stop-color="#f2a900" stop-opacity="0" />
                        </linearGradient>
                    </defs>

                    @if ($chart['zeroY'] !== null)
                        <line x1="0" x2="{{ $chart['width'] }}" y1="{{ $chart['zeroY'] }}" y2="{{ $chart['zeroY'] }}" stroke="#52525b" stroke-dasharray="4 4" />
                    @endif

                    <path d="{{ $chart['area'] }}" fill="url(#balance-fill)" />
                    <polyline points="{{ $chart['line'] }}" fill="none" stroke="#f2a900" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />

                    @foreach ($chart['dots'] as $dot)
                        <circle cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}" r="{{ $loop->last ? 5 : 3.5 }}" fill="#f2a900" stroke="#1f1f1f" stroke-width="2">
                            <title>{{ $dot['label'] }}: {{ Money::format($dot['value']) }}</title>
                        </circle>
                        <text x="{{ $dot['x'] }}" y="{{ $chart['height'] + 16 }}" text-anchor="middle" fill="#a1a1aa" font-size="12">{{ $dot['label'] }}</text>
                    @endforeach
                </svg>
            </div>

            {{-- Cartões --}}
            <div class="flex min-h-0 flex-col rounded-xl border border-zinc-800 bg-zinc-900/60 p-4">
                <div class="flex items-center justify-between">
                    <flux:heading>{{ __('Cards in the month') }}</flux:heading>
                    <flux:button variant="ghost" size="xs" :href="route('cards.index')" wire:navigate>{{ __('See cards') }}</flux:button>
                </div>

                @if ($this->cards->isEmpty())
                    <div class="flex flex-1 items-center justify-center py-6 text-sm text-zinc-500">{{ __('You have no cards yet.') }}</div>
                @else
                    <ul class="mt-3 min-h-0 flex-1 space-y-3.5 overflow-y-auto pr-1">
                        @foreach ($this->cards as $card)
                            @php
                                $spent = (int) $card->spent;
                                $usage = ($card->isCredit() && $card->credit_limit > 0)
                                    ? min(100, (int) round($spent / $card->credit_limit * 100))
                                    : null;
                            @endphp
                            <li wire:key="dash-card-{{ $card->id }}">
                                <div class="flex items-center gap-3 text-sm">
                                    <span class="size-2.5 shrink-0 rounded-full {{ $card->account->color->solid() }}"></span>
                                    <span class="flex-1 truncate font-medium text-white">{{ $card->name }}</span>
                                    <flux:badge size="sm" :color="$card->isCredit() ? 'amber' : 'zinc'">{{ $card->type->label() }}</flux:badge>
                                    <span class="w-24 text-right font-medium text-red-400">{{ Money::format($spent) }}</span>
                                </div>

                                @if ($usage !== null)
                                    <div class="mt-1.5 flex items-center gap-3">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-zinc-800">
                                            <div @class(['h-full rounded-full', 'bg-accent' => $usage < 80, 'bg-red-500' => $usage >= 80]) style="width: {{ $usage }}%"></div>
                                        </div>
                                        <span class="w-24 text-right text-[11px] text-zinc-500">{{ $usage }}% {{ __('of limit') }}</span>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Últimos lançamentos --}}
            <div class="flex min-h-0 flex-col rounded-xl border border-zinc-800 bg-zinc-900/60 p-4">
                <div class="flex items-center justify-between">
                    <flux:heading>{{ __('Latest transactions') }}</flux:heading>
                    <flux:button variant="ghost" size="xs" :href="route('accounts.index')" wire:navigate>{{ __('See accounts') }}</flux:button>
                </div>

                @if ($this->recentTransactions->isEmpty())
                    <div class="flex flex-1 items-center justify-center py-6 text-sm text-zinc-500">{{ __('No transactions yet.') }}</div>
                @else
                    <ul class="mt-2 min-h-0 flex-1 divide-y divide-zinc-800 overflow-y-auto pr-1">
                        @foreach ($this->recentTransactions as $transaction)
                            <li wire:key="dash-tx-{{ $transaction->id }}" class="flex items-center gap-3 py-2">
                                <div @class([
                                    'flex size-8 shrink-0 items-center justify-center rounded-full',
                                    'bg-emerald-500/15 text-emerald-400' => $transaction->isIncome(),
                                    'bg-red-500/15 text-red-400' => ! $transaction->isIncome(),
                                ])>
                                    @if ($transaction->isIncome())
                                        <flux:icon.arrow-down-left class="size-4" />
                                    @else
                                        <flux:icon.arrow-up-right class="size-4" />
                                    @endif
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-white">{{ $transaction->description }}</div>
                                    <div class="truncate text-xs text-zinc-500">
                                        {{ $transaction->date->format('d/m/Y') }} · {{ $transaction->card?->name ?? $transaction->account?->name }}
                                    </div>
                                </div>

                                <div @class([
                                    'whitespace-nowrap text-sm font-semibold',
                                    'text-emerald-400' => $transaction->isIncome(),
                                    'text-red-400' => ! $transaction->isIncome(),
                                ])>
                                    {{ $transaction->isIncome() ? '+' : '-' }} {{ Money::format($transaction->amount) }}
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
</section>
