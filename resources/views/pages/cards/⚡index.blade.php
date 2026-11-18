<?php

use App\Concerns\ManagesTransactions;
use App\Enums\CardType;
use App\Enums\TransactionCategory;
use App\Models\Account;
use App\Models\Card;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Cards')] class extends Component {
    use ManagesTransactions;

    #[Url(as: 'cartao')]
    public ?int $activeId = null;

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $name = '';

    public string $type = 'credit';

    public string $accountId = '';

    public string $creditLimit = '0,00';

    public string $closingDay = '';

    public string $dueDay = '';

    /**
     * @return Collection<int, Card>
     */
    #[Computed]
    public function cards(): Collection
    {
        return Auth::user()->cards()->with('account')->orderBy('name')->get();
    }

    #[Computed]
    public function activeCard(): ?Card
    {
        return $this->cards->firstWhere('id', $this->activeId) ?? $this->cards->first();
    }

    #[Computed]
    public function deletingTransactionsCount(): int
    {
        return $this->deletingId
            ? Auth::user()->cards()->findOrFail($this->deletingId)->transactions()->count()
            : 0;
    }

    protected function defaultMonthStart(): CarbonImmutable
    {
        $today = now()->toImmutable()->startOfDay();

        return $this->activeCard?->isCredit()
            ? CarbonImmutable::parse($this->activeCard->invoiceMonthFor($today->toDateString()).'-01')
            : $today->startOfMonth();
    }

    protected function periodFor(CarbonImmutable $monthStart): array
    {
        if ($this->activeCard?->isCredit()) {
            [$start, $end] = $this->activeCard->invoicePeriod($monthStart);

            return [$start->toDateString(), $end->toDateString()];
        }

        return [$monthStart->toDateString(), $monthStart->endOfMonth()->toDateString()];
    }

    protected function monthForDate(string $date): string
    {
        return $this->activeCard?->isCredit() ? $this->activeCard->invoiceMonthFor($date) : substr($date, 0, 7);
    }

    /**
     * Fatura do mês exibido (somente cartão de crédito).
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, due: CarbonImmutable, total: int, paid: int, remaining: int, payments: \Illuminate\Support\Collection, status: string}|null
     */
    #[Computed]
    public function invoice(): ?array
    {
        $card = $this->activeCard;

        if (! $card?->isCredit()) {
            return null;
        }

        $month = $this->monthStart();
        [$start, $end] = $card->invoicePeriod($month);
        $due = $card->dueDateFor($month);
        $payments = $card->payments()->where('month', $month->format('Y-m'))->orderBy('id')->get();
        $total = (int) $this->transactions->sum('amount');
        $paid = (int) $payments->sum('amount');
        $remaining = max(0, $total - $paid);
        $today = now()->toImmutable()->startOfDay();

        return [
            'start' => $start,
            'end' => $end,
            'due' => $due,
            'total' => $total,
            'paid' => $paid,
            'remaining' => $remaining,
            'payments' => $payments,
            // Só é "paga" quando nada falta pagar; compras novas reabrem a fatura.
            'status' => match (true) {
                $paid > 0 && $remaining === 0 => 'paid',
                $today->greaterThan($due) => 'overdue',
                $today->greaterThan($end) => 'closed',
                default => 'open',
            },
        ];
    }

    /**
     * Limite já comprometido: compras do cartão que ainda não foram pagas.
     */
    #[Computed]
    public function usedCredit(): int
    {
        $card = $this->activeCard;

        if (! $card?->isCredit()) {
            return 0;
        }

        return max(0, (int) $card->transactions()->sum('amount') - (int) $card->payments()->sum('amount'));
    }

    public function confirmPayInvoice(): void
    {
        $invoice = $this->invoice;

        if (! $invoice || $invoice['remaining'] <= 0) {
            Flux::toast(variant: 'danger', text: __('This invoice cannot be paid.'));

            return;
        }

        Flux::modal('confirm-invoice-payment')->show();
    }

    public function payInvoice(): void
    {
        $card = $this->activeCard;
        $invoice = $this->invoice;

        if (! $card || ! $invoice || $invoice['remaining'] <= 0) {
            Flux::modal('confirm-invoice-payment')->close();
            Flux::toast(variant: 'danger', text: __('This invoice cannot be paid.'));

            return;
        }

        $month = $this->monthStart()->format('Y-m');

        DB::transaction(function () use ($card, $invoice, $month): void {
            $transaction = $card->account->transactions()->create([
                'user_id' => Auth::id(),
                'type' => 'expense',
                'category' => TransactionCategory::CardBill->value,
                'description' => __('Invoice :card - :month', ['card' => $card->name, 'month' => $this->monthLabel]),
                'amount' => $invoice['remaining'],
                'date' => now()->toDateString(),
            ]);

            $card->payments()->create([
                'user_id' => Auth::id(),
                'account_id' => $card->account_id,
                'transaction_id' => $transaction->id,
                'month' => $month,
                'amount' => $invoice['remaining'],
                'paid_at' => now()->toDateString(),
            ]);
        });

        $this->forgetTransactionCaches();
        Flux::modal('confirm-invoice-payment')->close();
        Flux::toast(variant: 'success', text: __('Invoice paid.'));
    }

    public function undoInvoicePayment(int $id): void
    {
        $card = $this->activeCard;
        abort_unless($card, 404);

        $payment = $card->payments()->findOrFail($id);

        DB::transaction(function () use ($payment): void {
            $transaction = $payment->transaction;
            $payment->delete();
            $transaction?->delete();
        });

        $this->forgetTransactionCaches();
        Flux::toast(variant: 'success', text: __('Payment undone.'));
    }

    protected function transactionOwner(): ?Model
    {
        return $this->activeCard;
    }

    protected function allowsIncome(): bool
    {
        return false;
    }

    protected function allowsInstallments(): bool
    {
        return (bool) $this->activeCard?->isCredit();
    }

    protected function forgetOwnerCaches(): void
    {
        unset($this->cards, $this->activeCard, $this->invoice, $this->usedCredit);
    }

    public function select(int $id): void
    {
        $this->activeId = Auth::user()->cards()->findOrFail($id)->id;
        $this->forgetTransactionCaches();
    }

    public function selectPrevious(): void
    {
        $this->moveSelection(-1);
    }

    public function selectNext(): void
    {
        $this->moveSelection(1);
    }

    private function moveSelection(int $step): void
    {
        $ids = $this->cards->pluck('id')->values();
        $position = $ids->search($this->activeCard?->id);

        if ($position === false || ! $ids->has($position + $step)) {
            return;
        }

        $this->select($ids[$position + $step]);
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Auth::user()->accounts()->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'name', 'accountId', 'closingDay', 'dueDay');
        $this->type = 'credit';
        $this->creditLimit = '0,00';

        Flux::modal('card-form')->show();
    }

    public function edit(int $id): void
    {
        $card = Auth::user()->cards()->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $card->id;
        $this->name = $card->name;
        $this->type = $card->type->value;
        $this->accountId = (string) $card->account_id;
        $this->creditLimit = number_format(($card->credit_limit ?? 0) / 100, 2, ',', '.');
        $this->closingDay = (string) ($card->closing_day ?? '');
        $this->dueDay = (string) ($card->due_day ?? '');

        Flux::modal('card-form')->show();
    }

    public function save(): void
    {
        $this->name = trim($this->name);
        $this->creditLimit = trim($this->creditLimit);
        $isCredit = $this->type === CardType::Credit->value;

        $rules = [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('cards', 'name')
                    ->where('user_id', Auth::id())
                    ->ignore($this->editingId),
            ],
            'type' => ['required', Rule::enum(CardType::class)],
            'accountId' => [
                'required',
                Rule::exists('accounts', 'id')->where('user_id', Auth::id()),
            ],
        ];

        if ($isCredit) {
            $rules['creditLimit'] = ['required', 'regex:'.$this->moneyPattern(allowNegative: false)];
            $rules['closingDay'] = ['required', 'integer', 'between:1,31'];
            $rules['dueDay'] = ['required', 'integer', 'between:1,31'];
        }

        $validated = $this->validate($rules, [
            'creditLimit.regex' => __('The :attribute must be a valid amount, e.g. 1.234,56.'),
        ]);

        $data = [
            'name' => $validated['name'],
            'type' => $validated['type'],
            'account_id' => (int) $validated['accountId'],
            'credit_limit' => $isCredit ? $this->parseAmountToCents($validated['creditLimit']) : null,
            'closing_day' => $isCredit ? (int) $validated['closingDay'] : null,
            'due_day' => $isCredit ? (int) $validated['dueDay'] : null,
        ];

        if ($this->editingId) {
            $card = Auth::user()->cards()->findOrFail($this->editingId);
            $card->update($data);
            $message = __('Card updated.');
        } else {
            $card = Auth::user()->cards()->create($data);
            $this->activeId = $card->id;
            $message = __('Card created.');
        }

        $this->forgetTransactionCaches();
        Flux::modal('card-form')->close();
        Flux::toast(variant: 'success', text: $message);
    }

    public function confirmDelete(int $id): void
    {
        $card = Auth::user()->cards()->findOrFail($id);

        $this->deletingId = $card->id;
        unset($this->deletingTransactionsCount);

        Flux::modal('confirm-card-deletion')->show();
    }

    public function delete(): void
    {
        $card = Auth::user()->cards()->findOrFail($this->deletingId);

        $card->delete();

        $this->deletingId = null;
        $this->activeId = null;
        $this->forgetTransactionCaches();
        Flux::modal('confirm-card-deletion')->close();
        Flux::toast(variant: 'success', text: __('Card deleted.'));
    }
}; ?>

<section class="flex w-full flex-col lg:h-[calc(100dvh-4.5rem)]">
    <div class="mb-4 flex shrink-0 items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Cards') }}</flux:heading>
            <flux:subheading>{{ __('Manage your credit and debit cards.') }}</flux:subheading>
        </div>

        @if ($this->accounts->isNotEmpty())
            <flux:button variant="primary" icon="plus" wire:click="create" data-test="new-card-button">
                {{ __('New card') }}
            </flux:button>
        @endif
    </div>

    @if ($this->accounts->isEmpty())
        <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-700 px-6 py-16 text-center">
            <flux:icon.building-library class="size-10 text-zinc-500" />
            <flux:text>{{ __('Create an account first to be able to add cards.') }}</flux:text>
            <flux:button variant="primary" :href="route('accounts.index')" wire:navigate>{{ __('Go to accounts') }}</flux:button>
        </div>
    @elseif ($this->cards->isEmpty())
        <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-700 px-6 py-16 text-center">
            <flux:icon.credit-card class="size-10 text-zinc-500" />
            <flux:heading>{{ __('You have no cards yet.') }}</flux:heading>
            <flux:text>{{ __('Add your credit and debit cards to track your spending.') }}</flux:text>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New card') }}</flux:button>
        </div>
    @else
        @php
            $card = $this->activeCard;
            $color = $card->account->color;
            $ids = $this->cards->pluck('id')->values();
            $position = $ids->search($card->id);
            $spent = $this->monthTotals['expense'];
            $used = $this->usedCredit;
            $usage = ($card->isCredit() && $card->credit_limit > 0)
                ? min(100, (int) round($used / $card->credit_limit * 100))
                : null;
            $invoice = $this->invoice;
            $statusColor = match ($invoice['status'] ?? null) {
                'paid' => 'green',
                'overdue' => 'red',
                'closed' => 'amber',
                default => 'zinc',
            };
            $statusLabel = match ($invoice['status'] ?? null) {
                'paid' => __('Paid'),
                'overdue' => __('Overdue'),
                'closed' => __('Closed'),
                default => __('Open'),
            };
        @endphp

        <x-switcher
            :items="$this->cards->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'dot' => $c->account->color->solid()])->all()"
            :active-id="$card->id"
        />

        <div class="mt-4 flex shrink-0 items-center gap-2 sm:gap-4">
            <flux:button
                variant="ghost"
                icon="chevron-left"
                wire:click="selectPrevious"
                :disabled="$position === 0"
                :aria-label="__('Previous')"
            />

            <div
                wire:key="card-hero-{{ $card->id }}"
                wire:transition
                class="grid min-w-0 flex-1 items-center gap-8 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-5 lg:grid-cols-[19rem_1fr]"
            >
                {{-- Cartão desenhado --}}
                <div class="relative aspect-[1.586] w-full max-w-[19rem] overflow-hidden rounded-2xl bg-gradient-to-br p-5 text-white shadow-xl {{ $color->gradient() }}">
                    <div class="pointer-events-none absolute -right-10 -top-10 size-44 rounded-full bg-white/10"></div>
                    <div class="pointer-events-none absolute -bottom-16 -left-8 size-48 rounded-full bg-black/10"></div>

                    <div class="relative flex h-full flex-col justify-between">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium uppercase tracking-widest opacity-90">{{ $card->type->label() }}</span>
                            <flux:icon.credit-card class="size-7 opacity-90" />
                        </div>

                        <div>
                            <div class="text-xs opacity-80">{{ $card->account->name }}</div>
                            <div class="truncate text-xl font-semibold">{{ $card->name }}</div>
                        </div>
                    </div>
                </div>

                {{-- Informações --}}
                <div class="min-w-0">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="truncate text-3xl font-semibold text-accent">{{ $card->name }}</h2>
                            <div class="mt-1 text-sm text-zinc-400">{{ __('Linked account') }}: {{ $card->account->name }}</div>
                        </div>

                        <flux:dropdown align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />

                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="edit({{ $card->id }})">
                                    {{ __('Edit') }}
                                </flux:menu.item>
                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $card->id }})">
                                    {{ __('Delete') }}
                                </flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>

                    @if ($card->isCredit())
                        <div class="mt-4 grid grid-cols-2 gap-4">
                            <div>
                                <div class="flex items-center gap-2 text-sm text-zinc-400">
                                    {{ __('Invoice') }}
                                    <flux:badge size="sm" :color="$statusColor">{{ $statusLabel }}</flux:badge>
                                </div>
                                <div @class(['mt-1 text-2xl font-semibold', 'text-red-400' => $invoice['remaining'] > 0, 'text-emerald-400' => $invoice['remaining'] === 0])>{{ \App\Support\Money::format($invoice['remaining']) }}</div>
                            </div>

                            <div>
                                <div class="text-sm text-zinc-400">{{ __('Available limit') }}</div>
                                <div class="mt-1 text-2xl font-semibold text-white">{{ \App\Support\Money::format(max(0, $card->credit_limit - $used)) }}</div>
                            </div>
                        </div>

                        @if ($usage !== null)
                            <div class="mt-3">
                                <div class="mb-1 flex justify-between text-xs text-zinc-400">
                                    <span>{{ __('Used :used of :limit', ['used' => \App\Support\Money::format($used), 'limit' => \App\Support\Money::format($card->credit_limit)]) }}</span>
                                    <span>{{ $usage }}%</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-zinc-800">
                                    <div @class(['h-full rounded-full', 'bg-accent' => $usage < 80, 'bg-red-500' => $usage >= 80]) style="width: {{ $usage }}%"></div>
                                </div>
                            </div>
                        @endif

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
                            <div class="flex flex-wrap gap-x-6 text-sm text-zinc-400">
                                <span>{{ __('Closes') }}: <span class="font-medium text-white">{{ $invoice['end']->format('d/m') }}</span></span>
                                <span>{{ __('Due') }}: <span class="font-medium text-white">{{ $invoice['due']->format('d/m/Y') }}</span></span>
                            </div>

                            <div class="flex gap-2">
                                @if ($invoice['remaining'] > 0)
                                    <flux:button size="sm" variant="primary" icon="banknotes" wire:click="confirmPayInvoice" data-test="pay-invoice-button">
                                        {{ __('Pay invoice') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="mt-4">
                            <div class="text-sm text-zinc-400">{{ __('Spent in the month') }}</div>
                            <div class="mt-1 text-2xl font-semibold text-red-400">{{ \App\Support\Money::format($spent) }}</div>
                        </div>
                    @endif
                </div>
            </div>

            <flux:button
                variant="ghost"
                icon="chevron-right"
                wire:click="selectNext"
                :disabled="$position === $ids->count() - 1"
                :aria-label="__('Next')"
            />
        </div>

        <x-transactions-panel
            :transactions="$this->transactions"
            :month-label="$this->monthLabel"
            :period="$invoice ? $invoice['start']->format('d/m').' – '.$invoice['end']->format('d/m') : null"
            :payments="$invoice ? $invoice['payments'] : collect()"
            :recurring-count="$this->recurringTransactions->count()"
        />
    @endif

    <x-transaction-modals
        :allow-income="false"
        :allow-installments="(bool) $this->activeCard?->isCredit()"
        :deleting-installment="$this->deletingTransaction?->isInstallment() ? $this->deletingTransaction : null"
        :categories="$this->categoryOptions"
        :editing="(bool) $editingTransactionId"
        :recurring="$this->recurringTransactions"
        :cancelling-recurring="$this->cancellingRecurring"
        :editing-recurring="$this->editingRecurring"
    />

    <flux:modal name="confirm-invoice-payment" class="min-w-[22rem]">
        @if ($this->invoice && $this->activeCard)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Pay invoice') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('The invoice amount of :amount will be deducted today from the account :account.', [
                            'amount' => \App\Support\Money::format($this->invoice['remaining']),
                            'account' => $this->activeCard->account->name,
                        ]) }}
                    </flux:text>
                </div>

                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" wire:click="payInvoice" data-test="confirm-pay-invoice-button">
                        {{ __('Pay invoice') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal name="card-form" class="md:w-96">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit card') : __('New card') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Card name')" type="text" required autofocus />

            <flux:select wire:model.live="type" :label="__('Card type')">
                @foreach (App\Enums\CardType::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select
                wire:model="accountId"
                :label="__('Linked account')"
                :placeholder="__('Select an account')"
                :description="__('Debit: the account the money comes from. Credit: the account that pays the bill.')"
            >
                @foreach ($this->accounts as $account)
                    <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($type === 'credit')
                <x-money-input model="creditLimit" :label="__('Credit limit')" />

                <div class="grid grid-cols-2 gap-4">
                    <flux:input
                        wire:model="closingDay"
                        :label="__('Closing day')"
                        type="text"
                        inputmode="numeric"
                        maxlength="2"
                        x-on:beforeinput="if ($event.data && !/^[0-9]+$/.test($event.data)) $event.preventDefault()"
                    />
                    <flux:input
                        wire:model="dueDay"
                        :label="__('Due day')"
                        type="text"
                        inputmode="numeric"
                        maxlength="2"
                        x-on:beforeinput="if ($event.data && !/^[0-9]+$/.test($event.data)) $event.preventDefault()"
                    />
                </div>
            @endif

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-card-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="confirm-card-deletion" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete card') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete this card?') }}
                    @if ($this->deletingTransactionsCount > 0)
                        {{ trans_choice('{1} :count transaction of this card will also be deleted.|[2,*] :count transactions of this card will also be deleted.', $this->deletingTransactionsCount) }}
                    @endif
                    {{ __('This action cannot be undone.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-card-button">
                    {{ __('Delete') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
