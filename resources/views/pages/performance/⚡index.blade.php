<?php

use App\Support\Money;
use App\Support\Performance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Performance')] class extends Component {
    /** Seletores de cada card: anos ("2026") e meses ("2026-11"). */
    public string $annualYear = '';

    public string $categoryYear = '';

    public string $compareMonth = '';

    public string $donutMonth = '';

    public string $controlMonth = '';

    public function mount(): void
    {
        $this->annualYear = $this->categoryYear = (string) now()->year;
        $this->compareMonth = $this->donutMonth = $this->controlMonth = now()->format('Y-m');
    }

    /** No plano Gratuito só vale o mês e o ano atuais. */
    #[Computed]
    public function fullHistory(): bool
    {
        return \App\Support\PlanLimits::hasFullHistory(Auth::user());
    }

    protected function year(string $value): int
    {
        if (! $this->fullHistory) {
            return (int) now()->year;
        }

        $year = (int) $value;

        return $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;
    }

    protected function month(string $value): string
    {
        if (! $this->fullHistory) {
            return now()->format('Y-m');
        }

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : now()->format('Y-m');
    }

    /**
     * Anos que o usuário pode escolher (inclui o já selecionado).
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearOptions(): array
    {
        $years = array_unique([...Performance::years(Auth::id()), $this->year($this->annualYear), $this->year($this->categoryYear)]);
        rsort($years);

        return $years;
    }

    /**
     * Meses que o usuário pode escolher (inclui os já selecionados).
     *
     * @return array<string, string> "2026-11" => "11/2026"
     */
    #[Computed]
    public function monthOptions(): array
    {
        $months = array_unique([
            ...Performance::months(Auth::id()),
            $this->month($this->compareMonth),
            $this->month($this->donutMonth),
            $this->month($this->controlMonth),
        ]);
        rsort($months);

        return collect($months)->mapWithKeys(fn (string $m) => [$m => substr($m, 5, 2).'/'.substr($m, 0, 4)])->all();
    }

    /**
     * Gráfico de barras do ano: receitas x despesas por mês, já com a geometria SVG.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function annualChart(): array
    {
        $year = $this->year($this->annualYear);
        $data = Performance::annual(Auth::id(), $year);
        $names = Performance::monthNames($year);

        $width = 960;
        $left = 64;
        $right = 12;
        $top = 12;
        $bottom = 250;

        $max = max(10000, ...array_map(fn ($m) => max($m['income'], $m['expense']), $data));
        $ticks = Money::niceTicks(0, $max);
        $axisMax = end($ticks);
        $y = fn (int $v): float => $bottom - $v / $axisMax * ($bottom - $top);

        $column = ($width - $left - $right) / 12;
        $barWidth = 22;

        $months = [];
        foreach ($data as $i => $month) {
            $center = $left + $column * ($i + 0.5);
            $result = $month['income'] - $month['expense'];

            $months[] = [
                'i' => $i,
                'label' => $names[$i]['label'],
                'full' => $names[$i]['full'],
                'center' => round($center, 1),
                'column' => round($column, 1),
                'incomeX' => round($center - $barWidth - 1.5, 1),
                'expenseX' => round($center + 1.5, 1),
                'incomeY' => round($y($month['income']), 1),
                'expenseY' => round($y($month['expense']), 1),
                'income' => $month['income'],
                'expense' => $month['expense'],
                'result' => $result,
            ];
        }

        return [
            'year' => $year,
            'months' => $months,
            'ticks' => array_map(fn (int $t) => ['y' => round($y($t), 1), 'label' => Money::compact($t)], $ticks),
            'barWidth' => $barWidth,
            'width' => $width,
            'height' => 274,
            'left' => $left,
            'right' => $right,
            'top' => $top,
            'bottom' => $bottom,
            'income' => array_sum(array_column($data, 'income')),
            'expense' => array_sum(array_column($data, 'expense')),
        ];
    }

    /**
     * Linhas de gasto por categoria ao longo do ano, com a geometria SVG.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function categoryChart(): array
    {
        $year = $this->year($this->categoryYear);
        $data = Performance::categoriesByMonth(Auth::id(), $year);
        $names = Performance::monthNames($year);

        $width = 960;
        $left = 64;
        $right = 20;
        $top = 12;
        $bottom = 150;

        $max = 10000;
        foreach ($data as $category) {
            $max = max($max, ...$category['months']);
        }
        $ticks = Money::niceTicks(0, $max);
        $axisMax = end($ticks);
        $y = fn (int $v): float => $bottom - $v / $axisMax * ($bottom - $top);
        $x = fn (int $i): float => $left + $i * ($width - $left - $right) / 11;

        $series = [];
        $index = 0;
        foreach ($data as $key => $category) {
            $points = [];
            foreach ($category['months'] as $i => $value) {
                $points[] = ['x' => round($x($i), 1), 'y' => round($y($value), 1), 'value' => $value];
            }

            $series[] = [
                'key' => (string) $key,
                'label' => $category['label'],
                'color' => Performance::PALETTE[$index % count(Performance::PALETTE)],
                'points' => $points,
                'line' => implode(' ', array_map(fn ($p) => $p['x'].','.$p['y'], $points)),
            ];
            $index++;
        }

        // Dados para o balão: valores já formatados de cada categoria em cada mês.
        $rows = [];
        foreach ($names as $i => $name) {
            $rows[] = [
                'full' => $name['full'],
                'values' => collect($series)->mapWithKeys(fn ($s) => [$s['key'] => Money::format($s['points'][$i]['value'])])->all(),
            ];
        }

        return [
            'year' => $year,
            'series' => $series,
            'rows' => $rows,
            'months' => array_map(fn ($i) => ['i' => $i, 'x' => round($x($i), 1), 'label' => $names[$i]['label']], range(0, 11)),
            'ticks' => array_map(fn (int $t) => ['y' => round($y($t), 1), 'label' => Money::compact($t)], $ticks),
            'width' => $width,
            'height' => 174,
            'left' => $left,
            'right' => $right,
            'top' => $top,
            'bottom' => $bottom,
        ];
    }

    /**
     * Comparativo do mês escolhido com o mês anterior (contas).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function comparison(): array
    {
        $month = $this->month($this->compareMonth);
        $previous = CarbonImmutable::parse($month.'-01')->subMonthNoOverflow()->format('Y-m');

        $now = Performance::monthTotals(Auth::id(), $month);
        $before = Performance::monthTotals(Auth::id(), $previous);

        $rows = [];
        foreach ([
            'income' => [__('Income'), $now['income'], $before['income'], true],
            'expense' => [__('Expenses'), $now['expense'], $before['expense'], false],
            'result' => [__('Result'), $now['income'] - $now['expense'], $before['income'] - $before['expense'], true],
        ] as $key => [$label, $current, $prior, $higherIsBetter]) {
            $variation = Performance::variation($current, $prior);

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'current' => $current,
                'previous' => $prior,
                'variation' => $variation,
                // Verde quando melhora (mais receita, menos despesa), vermelho quando piora.
                'good' => $variation === null || $variation == 0 ? null : (($variation > 0) === $higherIsBetter),
            ];
        }

        return ['month' => substr($month, 5, 2).'/'.substr($month, 0, 4), 'previous' => substr($previous, 5, 2).'/'.substr($previous, 0, 4), 'rows' => $rows];
    }

    /**
     * @return array<int, array{label: string, amount: int, percent: float, share: float, start: float, color: string}>
     */
    #[Computed]
    public function spending(): array
    {
        return Performance::spendingByCategory(Auth::id(), $this->month($this->donutMonth));
    }

    /**
     * @return array{essential: int, other: int, total: int, essentialPercent: float, otherPercent: float}
     */
    #[Computed]
    public function control(): array
    {
        $split = Performance::essentialSplit(Auth::id(), $this->month($this->controlMonth));
        $total = $split['essential'] + $split['other'];

        return $split + [
            'total' => $total,
            'essentialPercent' => $total > 0 ? round($split['essential'] / $total * 100, 1) : 0.0,
            'otherPercent' => $total > 0 ? round($split['other'] / $total * 100, 1) : 0.0,
        ];
    }
}; ?>

<section class="flex w-full flex-col gap-3">
    <div>
        <flux:heading size="xl" level="1">{{ __('Performance') }}</flux:heading>
        <flux:subheading>{{ __('Understand how your money moved over time.') }}</flux:subheading>
        @unless ($this->fullHistory)
            <div class="mt-2 flex items-center gap-2 text-xs text-zinc-400" data-test="history-locked">
                <flux:icon.lock-closed class="size-3.5 text-red-400" />
                {{ __('The Free plan shows the current month and year. Premium unlocks the full history.') }}
                <a href="{{ route('plans.index') }}" wire:navigate class="text-accent underline">{{ __('See plans') }}</a>
            </div>
        @endunless
    </div>

    <div class="grid gap-3 md:grid-cols-3">
        {{-- Desempenho anual --}}
        @php
            $annual = $this->annualChart;
            $annualResult = $annual['income'] - $annual['expense'];
        @endphp
        <div class="flex flex-col rounded-xl border border-zinc-800 bg-zinc-900/60 p-3 md:col-span-2" data-test="annual-card">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading>{{ __('Annual performance') }}</flux:heading>
                    <div @class(['text-xl font-semibold', 'text-emerald-400' => $annualResult > 0, 'text-red-400' => $annualResult < 0, 'text-white' => $annualResult === 0])>
                        {{ $annualResult > 0 ? '+' : '' }}{{ Money::format($annualResult) }}
                    </div>
                    <div class="text-xs text-zinc-500">
                        {{ __('Income') }} <span class="text-emerald-400">{{ Money::format($annual['income']) }}</span>
                        · {{ __('Expenses') }} <span class="text-red-400">{{ Money::format($annual['expense']) }}</span>
                    </div>
                </div>

                <flux:select wire:model.live="annualYear" size="sm" class="!w-28" :disabled="! $this->fullHistory" :aria-label="__('Year')">
                    @foreach ($this->yearOptions as $year)
                        <flux:select.option value="{{ $year }}">{{ $year }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <svg
                viewBox="0 0 {{ $annual['width'] }} {{ $annual['height'] }}"
                class="mt-2 w-full"
                role="img"
                aria-label="{{ __('Annual performance') }}"
                x-data="{ active: null }"
                x-on:mouseleave="active = null"
                wire:key="annual-chart-{{ $annual['year'] }}"
            >
                @foreach ($annual['ticks'] as $tick)
                    <line x1="{{ $annual['left'] }}" x2="{{ $annual['width'] - $annual['right'] }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" stroke="#27272a" />
                    <text x="{{ $annual['left'] - 8 }}" y="{{ $tick['y'] + 4 }}" text-anchor="end" fill="#a1a1aa" font-size="11">{{ $tick['label'] }}</text>
                @endforeach

                @foreach ($annual['months'] as $m)
                    <rect x="{{ $m['incomeX'] }}" y="{{ $m['incomeY'] }}" width="{{ $annual['barWidth'] }}" height="{{ $annual['bottom'] - $m['incomeY'] }}" rx="2" fill="#34d399" data-test="bar-income-{{ $m['i'] }}" />
                    <rect x="{{ $m['expenseX'] }}" y="{{ $m['expenseY'] }}" width="{{ $annual['barWidth'] }}" height="{{ $annual['bottom'] - $m['expenseY'] }}" rx="2" fill="#f87171" data-test="bar-expense-{{ $m['i'] }}" />
                    <text x="{{ $m['center'] }}" y="{{ $annual['bottom'] + 20 }}" text-anchor="middle" fill="#a1a1aa" font-size="12">{{ $m['label'] }}</text>
                @endforeach

                @foreach ($annual['months'] as $m)
                    <rect
                        x="{{ $m['center'] - $m['column'] / 2 }}" y="{{ $annual['top'] }}" width="{{ $m['column'] }}" height="{{ $annual['bottom'] - $annual['top'] + 24 }}"
                        fill="transparent" x-on:mouseenter="active = {{ $m['i'] }}" x-on:touchstart.passive="active = {{ $m['i'] }}"
                        x-bind:fill="active === {{ $m['i'] }} ? 'rgba(255,255,255,0.05)' : 'transparent'"
                    />
                @endforeach

                @foreach ($annual['months'] as $m)
                    @php
                        $tipW = 150;
                        $tipH = 66;
                        $tipX = max(4, min($m['center'] - $tipW / 2, $annual['width'] - $tipW - 4));
                    @endphp
                    <g style="display: none" x-show="active === {{ $m['i'] }}" pointer-events="none" data-test="annual-tooltip-{{ $m['i'] }}">
                        <rect x="{{ $tipX }}" y="2" width="{{ $tipW }}" height="{{ $tipH }}" rx="6" fill="#27272a" stroke="#3f3f46" />
                        <text x="{{ $tipX + 10 }}" y="19" fill="#a1a1aa" font-size="11">{{ $m['full'] }}</text>
                        <text x="{{ $tipX + 10 }}" y="36" fill="#34d399" font-size="12">{{ __('Income') }}: {{ Money::format($m['income']) }}</text>
                        <text x="{{ $tipX + 10 }}" y="50" fill="#f87171" font-size="12">{{ __('Expenses') }}: {{ Money::format($m['expense']) }}</text>
                        <text x="{{ $tipX + 10 }}" y="63" fill="#ffffff" font-size="12" font-weight="600">{{ __('Result') }}: {{ Money::format($m['result']) }}</text>
                    </g>
                @endforeach
            </svg>

            <div class="flex shrink-0 justify-center gap-4 text-xs text-zinc-400">
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-emerald-400"></span>{{ __('Income') }}</span>
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-red-400"></span>{{ __('Expenses') }}</span>
            </div>
        </div>

        <div class="flex flex-col gap-3">
            {{-- Comparativo com o mês anterior --}}
            @php
                $comparison = $this->comparison;
            @endphp
            <div class="flex flex-col rounded-xl border border-zinc-800 bg-zinc-900/60 p-3" data-test="comparison-card">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <flux:heading>{{ __('Compared to the previous month') }}</flux:heading>
                        <div class="text-xs text-zinc-500">{{ $comparison['month'] }} {{ __('vs') }} {{ $comparison['previous'] }}</div>
                    </div>

                    <flux:select wire:model.live="compareMonth" size="sm" class="!w-32" :disabled="! $this->fullHistory" :aria-label="__('Month')">
                        @foreach ($this->monthOptions as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <ul class="mt-2 space-y-2">
                    @foreach ($comparison['rows'] as $row)
                        <li wire:key="compare-{{ $row['key'] }}" data-test="compare-{{ $row['key'] }}">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="text-sm text-zinc-400">{{ $row['label'] }}</span>
                                @if ($row['variation'] === null)
                                    <span class="text-xs text-zinc-500">{{ __('No base to compare') }}</span>
                                @else
                                    <span @class(['text-xs font-medium', 'text-emerald-400' => $row['good'] === true, 'text-red-400' => $row['good'] === false, 'text-zinc-400' => $row['good'] === null])>
                                        {{ $row['variation'] > 0 ? '▲ +' : ($row['variation'] < 0 ? '▼ ' : '') }}{{ number_format($row['variation'], 1, ',', '.') }}%
                                    </span>
                                @endif
                            </div>
                            <div class="text-xl font-semibold text-white">{{ Money::format($row['current']) }}</div>
                            <div class="text-xs text-zinc-500">{{ __('Previous month') }}: {{ Money::format($row['previous']) }}</div>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Controle de gastos --}}
            @php
                $control = $this->control;
            @endphp
            <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-3" data-test="control-card">
                <div class="flex items-start justify-between gap-3">
                    <flux:heading>{{ __('Spending control') }}</flux:heading>

                    <flux:select wire:model.live="controlMonth" size="sm" class="!w-32" :disabled="! $this->fullHistory" :aria-label="__('Month')">
                        @foreach ($this->monthOptions as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                @if ($control['total'] === 0)
                    <div class="py-3 text-center text-sm text-zinc-500">{{ __('No expenses in this month.') }}</div>
                @else
                    <div class="mt-2 space-y-2.5">
                        <div data-test="control-essential">
                            <div class="flex items-baseline justify-between text-sm">
                                <span class="font-medium text-emerald-400">{{ __('Essential expenses') }}</span>
                                <span class="font-semibold text-emerald-400">{{ number_format($control['essentialPercent'], 1, ',', '.') }}%</span>
                            </div>
                            <div class="mt-1 h-2.5 overflow-hidden rounded-full bg-zinc-800">
                                <div class="h-full rounded-full bg-emerald-400" style="width: {{ $control['essentialPercent'] }}%"></div>
                            </div>
                            <div class="text-xs text-zinc-400">{{ Money::format($control['essential']) }}</div>
                        </div>

                        <div data-test="control-other">
                            <div class="flex items-baseline justify-between text-sm">
                                <span class="font-medium text-amber-400">{{ __('Non-essential expenses') }}</span>
                                <span class="font-semibold text-amber-400">{{ number_format($control['otherPercent'], 1, ',', '.') }}%</span>
                            </div>
                            <div class="mt-1 h-2.5 overflow-hidden rounded-full bg-zinc-800">
                                <div class="h-full rounded-full bg-amber-400" style="width: {{ $control['otherPercent'] }}%"></div>
                            </div>
                            <div class="text-xs text-zinc-400">{{ Money::format($control['other']) }}</div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="grid gap-3 md:grid-cols-3">
        {{-- Categorias por mês --}}
        @php
            $cat = $this->categoryChart;
        @endphp
        <div class="flex flex-col rounded-xl border border-zinc-800 bg-zinc-900/60 p-3 md:col-span-2" data-test="categories-card">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading>{{ __('Categories by month') }}</flux:heading>
                    <div class="text-xs text-zinc-500">{{ __('Accounts and cards together') }}</div>
                </div>

                <flux:select wire:model.live="categoryYear" size="sm" class="!w-28" :disabled="! $this->fullHistory" :aria-label="__('Year')">
                    @foreach ($this->yearOptions as $year)
                        <flux:select.option value="{{ $year }}">{{ $year }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($cat['series'] === [])
                <div class="flex flex-1 items-center justify-center text-sm text-zinc-500">{{ __('No expenses in this year.') }}</div>
            @else
                <div
                    wire:key="category-chart-{{ $cat['year'] }}"
                    x-data="{
                        visible: @js(array_column($cat['series'], 'key')),
                        series: @js(array_map(fn ($s) => ['key' => $s['key'], 'label' => $s['label'], 'color' => $s['color']], $cat['series'])),
                        rows: @js($cat['rows']),
                        active: null,
                        toggle(key) {
                            this.visible = this.visible.includes(key) ? this.visible.filter(k => k !== key) : [...this.visible, key];
                        },
                    }"
                >
                    <div class="mt-2 flex shrink-0 flex-wrap gap-1.5">
                        @foreach ($cat['series'] as $s)
                            <button
                                type="button"
                                x-on:click="toggle('{{ $s['key'] }}')"
                                x-bind:class="visible.includes('{{ $s['key'] }}') ? 'border-zinc-600 text-white' : 'border-zinc-800 text-zinc-500 opacity-60'"
                                x-bind:aria-pressed="visible.includes('{{ $s['key'] }}')"
                                class="flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                                data-test="category-toggle-{{ $s['key'] }}"
                            >
                                <span class="size-2.5 rounded-full" style="background-color: {{ $s['color'] }}"></span>
                                {{ $s['label'] }}
                            </button>
                        @endforeach
                    </div>

                    <div class="mt-1 min-h-[1.5rem] shrink-0 text-xs" data-test="category-info">
                        <template x-if="active === null">
                            <span class="text-zinc-500">{{ __('Hover over a month to see the values.') }}</span>
                        </template>
                        <template x-if="active !== null">
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                <span class="font-medium text-white" x-text="rows[active].full"></span>
                                <template x-for="s in series.filter(s => visible.includes(s.key))" x-bind:key="s.key">
                                    <span class="flex items-center gap-1.5 text-zinc-300">
                                        <span class="size-2 rounded-full" x-bind:style="'background-color:' + s.color"></span>
                                        <span x-text="s.label + ': ' + rows[active].values[s.key]"></span>
                                    </span>
                                </template>
                            </div>
                        </template>
                    </div>

                    <svg
                        viewBox="0 0 {{ $cat['width'] }} {{ $cat['height'] }}"
                        class="w-full"
                        role="img"
                        aria-label="{{ __('Categories by month') }}"
                        x-on:mouseleave="active = null"
                    >
                        @foreach ($cat['ticks'] as $tick)
                            <line x1="{{ $cat['left'] }}" x2="{{ $cat['width'] - $cat['right'] }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" stroke="#27272a" />
                            <text x="{{ $cat['left'] - 8 }}" y="{{ $tick['y'] + 4 }}" text-anchor="end" fill="#a1a1aa" font-size="11">{{ $tick['label'] }}</text>
                        @endforeach

                        @foreach ($cat['months'] as $m)
                            <text x="{{ $m['x'] }}" y="{{ $cat['bottom'] + 20 }}" text-anchor="middle" fill="#a1a1aa" font-size="12">{{ $m['label'] }}</text>
                            <line x1="{{ $m['x'] }}" x2="{{ $m['x'] }}" y1="{{ $cat['top'] }}" y2="{{ $cat['bottom'] }}" stroke="#f2a900" stroke-opacity="0.35" stroke-dasharray="3 3" style="display: none" x-show="active === {{ $m['i'] }}" />
                        @endforeach

                        @foreach ($cat['series'] as $s)
                            <g x-show="visible.includes('{{ $s['key'] }}')" data-test="category-line-{{ $s['key'] }}">
                                <polyline points="{{ $s['line'] }}" fill="none" stroke="{{ $s['color'] }}" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
                                @foreach ($s['points'] as $i => $p)
                                    <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="3" fill="{{ $s['color'] }}" stroke="#1f1f1f" stroke-width="1.5" x-bind:r="active === {{ $i }} ? 5 : 3" />
                                @endforeach
                            </g>
                        @endforeach

                        @php
                            $band = ($cat['width'] - $cat['left'] - $cat['right']) / 11;
                        @endphp
                        @foreach ($cat['months'] as $m)
                            <rect
                                x="{{ $m['x'] - $band / 2 }}" y="{{ $cat['top'] }}" width="{{ $band }}" height="{{ $cat['bottom'] - $cat['top'] + 24 }}"
                                fill="transparent" x-on:mouseenter="active = {{ $m['i'] }}" x-on:touchstart.passive="active = {{ $m['i'] }}"
                            />
                        @endforeach
                    </svg>
                </div>
            @endif
        </div>

        {{-- Despesas por categoria --}}
        <div class="flex flex-col rounded-xl border border-zinc-800 bg-zinc-900/60 p-3" data-test="spending-card">
            <div class="flex items-start justify-between gap-3">
                <flux:heading>{{ __('Spending by category') }}</flux:heading>

                <flux:select wire:model.live="donutMonth" size="sm" class="!w-32" :disabled="! $this->fullHistory" :aria-label="__('Month')">
                    @foreach ($this->monthOptions as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($this->spending === [])
                <div class="flex flex-1 items-center justify-center text-sm text-zinc-500">{{ __('No expenses in this month.') }}</div>
            @else
                <div class="mt-2 flex flex-1 flex-col items-center gap-3">
                    <svg viewBox="-1 -1 38 38" class="size-24 shrink-0 -rotate-90 overflow-visible" role="img" aria-label="{{ __('Spending by category') }}">
                        <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#27272a" stroke-width="5" />
                        @foreach ($this->spending as $slice)
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

                    <ul class="w-full space-y-1 text-xs">
                        @foreach ($this->spending as $slice)
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
    </div>

</section>
