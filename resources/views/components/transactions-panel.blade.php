@props(['transactions', 'monthLabel', 'period' => null, 'payments' => null, 'transfers' => null, 'accountId' => null, 'canTransfer' => false])

@php
    // Pagamentos de fatura aparecem junto dos lançamentos do cartão, em ordem de data.
    $rows = collect($transactions)
        ->map(fn ($t) => ['kind' => 'transaction', 'model' => $t, 'date' => $t->date->toDateString(), 'id' => $t->id])
        ->concat(collect($payments ?? [])->map(fn ($p) => ['kind' => 'payment', 'model' => $p, 'date' => $p->paid_at->toDateString(), 'id' => $p->id]))
        ->concat(collect($transfers ?? [])->map(fn ($t) => ['kind' => 'transfer', 'model' => $t, 'date' => $t->date->toDateString(), 'id' => $t->id]))
        ->sort(fn ($a, $b) => [$b['date'], $b['kind'] === 'payment' ? 1 : 0, $b['id']] <=> [$a['date'], $a['kind'] === 'payment' ? 1 : 0, $a['id']])
        ->values();
@endphp

{{-- Lista de lançamentos do mês para a conta ou o cartão em destaque. --}}
<section class="mt-6 flex min-h-0 flex-1 flex-col">
    <div class="mb-3 flex shrink-0 flex-wrap items-center justify-between gap-3">
        <div class="flex items-baseline gap-3">
            <flux:heading size="lg">{{ $period ? __('Invoice transactions') : __('Transactions') }}</flux:heading>
            @if ($period)
                <span class="text-xs text-zinc-400">{{ $period }}</span>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center rounded-lg border border-zinc-800">
                <flux:button variant="ghost" size="sm" icon="chevron-left" wire:click="previousMonth" :aria-label="__('Previous month')" />
                <span class="min-w-36 px-1 text-center text-sm font-medium">{{ $monthLabel }}</span>
                <flux:button variant="ghost" size="sm" icon="chevron-right" wire:click="nextMonth" :aria-label="__('Next month')" />
            </div>

            @if ($canTransfer)
                <flux:button variant="filled" size="sm" icon="arrows-right-left" wire:click="createTransfer" data-test="new-transfer-button">
                    {{ __('Transfer') }}
                </flux:button>
            @endif

            <flux:button variant="primary" size="sm" icon="plus" wire:click="createTransaction" data-test="new-transaction-button">
                {{ __('New transaction') }}
            </flux:button>
        </div>
    </div>

    @if ($rows->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-800 px-6 py-12 text-center">
            <flux:text>{{ __('No transactions in this month.') }}</flux:text>
        </div>
    @else
        <div class="min-h-0 flex-1 divide-y divide-zinc-800 overflow-y-auto rounded-xl border border-zinc-800 bg-zinc-900/40">
            @foreach ($rows as $row)
                @if ($row['kind'] === 'payment')
                    <div wire:key="payment-{{ $row['id'] }}" class="flex items-center gap-4 px-4 py-3">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-400">
                            <flux:icon.banknotes class="size-5" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium text-white">{{ __('Invoice payment') }}</div>
                            <div class="text-sm text-zinc-400">
                                {{ $row['model']->paid_at->format('d/m/Y') }} · {{ __('Credit card bill') }}
                            </div>
                        </div>

                        <div class="whitespace-nowrap font-semibold text-emerald-400">
                            + {{ \App\Support\Money::format($row['model']->amount) }}
                        </div>

                        <flux:dropdown align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />

                            <flux:menu>
                                <flux:menu.item icon="arrow-uturn-left" variant="danger" wire:click="undoInvoicePayment({{ $row['id'] }})" data-test="undo-invoice-button">
                                    {{ __('Undo payment') }}
                                </flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                    @continue
                @endif

                @if ($row['kind'] === 'transfer')
                    @php
                        $transfer = $row['model'];
                        $outgoing = $transfer->from_account_id === $accountId;
                    @endphp
                    <div wire:key="transfer-{{ $row['id'] }}" class="flex items-center gap-4 px-4 py-3">
                        <div @class([
                            'flex size-10 shrink-0 items-center justify-center rounded-full',
                            'bg-red-500/15 text-red-400' => $outgoing,
                            'bg-emerald-500/15 text-emerald-400' => ! $outgoing,
                        ])>
                            <flux:icon.arrows-right-left class="size-5" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium text-white">
                                {{ $outgoing
                                    ? __('Transfer to :account', ['account' => $transfer->toAccount->name])
                                    : __('Transfer from :account', ['account' => $transfer->fromAccount->name]) }}
                            </div>
                            <div class="truncate text-sm text-zinc-400">
                                {{ $transfer->date->format('d/m/Y') }} · {{ __('Transfer') }}@if ($transfer->description) · {{ $transfer->description }}@endif
                            </div>
                        </div>

                        <div @class(['whitespace-nowrap font-semibold', 'text-red-400' => $outgoing, 'text-emerald-400' => ! $outgoing])>
                            {{ $outgoing ? '-' : '+' }} {{ \App\Support\Money::format($transfer->amount) }}
                        </div>

                        <flux:dropdown align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />

                            <flux:menu>
                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteTransfer({{ $row['id'] }})">
                                    {{ __('Delete') }}
                                </flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                    @continue
                @endif

                @php
                    $transaction = $row['model'];
                @endphp

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
