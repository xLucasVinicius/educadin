@props(['transactions', 'monthLabel'])

{{-- Lista de lançamentos do mês para a conta ou o cartão em destaque. --}}
<section class="mt-6 flex min-h-0 flex-1 flex-col">
    <div class="mb-3 flex shrink-0 flex-wrap items-center justify-between gap-3">
        <flux:heading size="lg">{{ __('Transactions') }}</flux:heading>

        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center rounded-lg border border-zinc-800">
                <flux:button variant="ghost" size="sm" icon="chevron-left" wire:click="previousMonth" :aria-label="__('Previous month')" />
                <span class="min-w-36 px-1 text-center text-sm font-medium">{{ $monthLabel }}</span>
                <flux:button variant="ghost" size="sm" icon="chevron-right" wire:click="nextMonth" :aria-label="__('Next month')" />
            </div>

            <flux:button variant="primary" size="sm" icon="plus" wire:click="createTransaction" data-test="new-transaction-button">
                {{ __('New transaction') }}
            </flux:button>
        </div>
    </div>

    @if ($transactions->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-800 px-6 py-12 text-center">
            <flux:text>{{ __('No transactions in this month.') }}</flux:text>
        </div>
    @else
        <div class="min-h-0 flex-1 divide-y divide-zinc-800 overflow-y-auto rounded-xl border border-zinc-800 bg-zinc-900/40">
            @foreach ($transactions as $transaction)
                <div wire:key="transaction-{{ $transaction->id }}" class="flex items-center gap-4 px-4 py-3">
                    <div @class([
                        'flex size-10 shrink-0 items-center justify-center rounded-full',
                        'bg-emerald-500/15 text-emerald-400' => $transaction->isIncome(),
                        'bg-red-500/15 text-red-400' => ! $transaction->isIncome(),
                    ])>
                        @if ($transaction->isIncome())
                            <flux:icon.arrow-down-left class="size-5" />
                        @else
                            <flux:icon.arrow-up-right class="size-5" />
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium text-white">{{ $transaction->description }}</div>
                        <div class="text-sm text-zinc-400">
                            {{ $transaction->date->format('d/m/Y') }} · {{ $transaction->category->label() }}
                        </div>
                    </div>

                    <div @class([
                        'whitespace-nowrap font-semibold',
                        'text-emerald-400' => $transaction->isIncome(),
                        'text-red-400' => ! $transaction->isIncome(),
                    ])>
                        {{ $transaction->isIncome() ? '+' : '-' }} {{ \App\Support\Money::format($transaction->amount) }}
                    </div>

                    <flux:dropdown align="end">
                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />

                        <flux:menu>
                            <flux:menu.item icon="pencil-square" wire:click="editTransaction({{ $transaction->id }})">
                                {{ __('Edit') }}
                            </flux:menu.item>
                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteTransaction({{ $transaction->id }})">
                                {{ __('Delete') }}
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @endforeach
        </div>
    @endif
</section>
