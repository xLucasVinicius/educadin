<?php

use App\Concerns\ManagesTransactions;
use App\Enums\AccountColor;
use App\Enums\AccountType;
use App\Models\Account;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Accounts')] class extends Component {
    use ManagesTransactions;

    #[Url(as: 'conta')]
    public ?int $activeId = null;

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $name = '';

    public string $type = '';

    public string $color = 'amber';

    public string $initialBalance = '0,00';

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Auth::user()->accounts()->withBalance()->orderBy('name')->get();
    }

    #[Computed]
    public function activeAccount(): ?Account
    {
        return $this->accounts->firstWhere('id', $this->activeId) ?? $this->accounts->first();
    }

    /**
     * Saldo da conta em destaque no início do mês exibido.
     */
    #[Computed]
    public function openingBalance(): int
    {
        $account = $this->activeAccount;

        if (! $account) {
            return 0;
        }

        $before = $this->monthStart()->toDateString();
        $income = $account->transactions()->where('type', 'income')->where('date', '<', $before)->sum('amount');
        $expense = $account->transactions()->where('type', 'expense')->where('date', '<', $before)->sum('amount');

        return $account->initial_balance + (int) $income - (int) $expense;
    }

    #[Computed]
    public function deletingTransactionsCount(): int
    {
        return $this->deletingId
            ? Auth::user()->accounts()->findOrFail($this->deletingId)->transactions()->count()
            : 0;
    }

    protected function transactionOwner(): ?Model
    {
        return $this->activeAccount;
    }

    protected function allowsIncome(): bool
    {
        return true;
    }

    protected function forgetOwnerCaches(): void
    {
        unset($this->accounts, $this->activeAccount, $this->openingBalance);
    }

    public function select(int $id): void
    {
        $this->activeId = Auth::user()->accounts()->findOrFail($id)->id;
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
        $ids = $this->accounts->pluck('id')->values();
        $position = $ids->search($this->activeAccount?->id);

        if ($position === false || ! $ids->has($position + $step)) {
            return;
        }

        $this->select($ids[$position + $step]);
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'name', 'type');
        $this->color = 'amber';
        $this->initialBalance = '0,00';

        Flux::modal('account-form')->show();
    }

    public function edit(int $id): void
    {
        $account = Auth::user()->accounts()->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $account->id;
        $this->name = $account->name;
        $this->type = $account->type->value;
        $this->color = $account->color->value;
        $this->initialBalance = number_format($account->initial_balance / 100, 2, ',', '.');

        Flux::modal('account-form')->show();
    }

    public function save(): void
    {
        $this->name = trim($this->name);
        $this->initialBalance = trim($this->initialBalance);

        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('accounts', 'name')
                    ->where('user_id', Auth::id())
                    ->ignore($this->editingId),
            ],
            'type' => ['required', Rule::enum(AccountType::class)],
            'color' => ['required', Rule::enum(AccountColor::class)],
            'initialBalance' => [
                'required',
                'regex:'.$this->moneyPattern(),
            ],
        ], [
            'initialBalance.regex' => __('The :attribute must be a valid amount, e.g. 1.234,56.'),
        ]);

        $data = [
            'name' => $validated['name'],
            'type' => $validated['type'],
            'color' => $validated['color'],
            'initial_balance' => $this->parseAmountToCents($validated['initialBalance']),
        ];

        if ($this->editingId) {
            $account = Auth::user()->accounts()->findOrFail($this->editingId);
            Gate::authorize('update', $account);
            $account->update($data);
            $message = __('Account updated.');
        } else {
            $account = Auth::user()->accounts()->create($data);
            $this->activeId = $account->id;
            $message = __('Account created.');
        }

        $this->forgetTransactionCaches();
        Flux::modal('account-form')->close();
        Flux::toast(variant: 'success', text: $message);
    }

    public function confirmDelete(int $id): void
    {
        $account = Auth::user()->accounts()->findOrFail($id);

        $this->deletingId = $account->id;
        unset($this->deletingTransactionsCount);

        Flux::modal('confirm-account-deletion')->show();
    }

    public function delete(): void
    {
        $account = Auth::user()->accounts()->findOrFail($this->deletingId);
        Gate::authorize('delete', $account);

        if ($account->cards()->exists()) {
            $this->deletingId = null;
            Flux::modal('confirm-account-deletion')->close();
            Flux::toast(variant: 'danger', text: __('This account has linked cards and cannot be deleted.'));

            return;
        }

        $account->delete();

        $this->deletingId = null;
        $this->activeId = null;
        $this->forgetTransactionCaches();
        Flux::modal('confirm-account-deletion')->close();
        Flux::toast(variant: 'success', text: __('Account deleted.'));
    }
}; ?>

<section class="flex w-full flex-col lg:h-[calc(100dvh-4.5rem)]">
    <div class="mb-4 flex shrink-0 items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Accounts') }}</flux:heading>
            <flux:subheading>{{ __('Manage the places where your money lives.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="create" data-test="new-account-button">
            {{ __('New account') }}
        </flux:button>
    </div>

    @if ($this->accounts->isEmpty())
        <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-700 px-6 py-16 text-center">
            <flux:icon.building-library class="size-10 text-zinc-500" />
            <flux:heading>{{ __('You have no accounts yet.') }}</flux:heading>
            <flux:text>{{ __('Create your first account to start organizing your money.') }}</flux:text>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New account') }}</flux:button>
        </div>
    @else
        @php
            $account = $this->activeAccount;
            $ids = $this->accounts->pluck('id')->values();
            $position = $ids->search($account->id);
            $balance = $account->currentBalance();
            $totals = $this->monthTotals;
            $net = $totals['income'] - $totals['expense'];
            $opening = $this->openingBalance;
            $percent = $opening !== 0 ? round($net / abs($opening) * 100, 1) : null;
        @endphp

        <x-switcher
            :items="$this->accounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'dot' => $a->color->solid()])->all()"
            :active-id="$account->id"
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
                wire:key="account-hero-{{ $account->id }}"
                wire:transition
                class="relative min-w-0 flex-1 overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-900/60 p-5"
            >
                <div class="pointer-events-none absolute -right-16 -top-16 size-64 rounded-full blur-3xl {{ $account->color->glow() }}"></div>

                <div class="relative flex flex-wrap items-center gap-6">
                    <div class="flex size-20 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-3xl font-semibold text-white shadow-lg {{ $account->color->gradient() }}">
                        {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($account->name, 0, 1)) }}
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="truncate text-3xl font-semibold text-accent">{{ $account->name }}</h2>
                                <flux:badge size="sm" color="zinc" class="mt-2">{{ $account->type->label() }}</flux:badge>
                            </div>

                            <flux:dropdown align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />

                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $account->id }})">
                                        {{ __('Edit') }}
                                    </flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $account->id }})">
                                        {{ __('Delete') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>

                        <div class="mt-3 text-sm text-zinc-400">{{ __('Current balance') }}</div>
                        <div @class(['text-3xl font-semibold', 'text-white' => $balance >= 0, 'text-red-400' => $balance < 0])>
                            {{ Money::format($balance) }}
                        </div>
                    </div>
                </div>

                <div class="relative mt-4 grid grid-cols-1 gap-4 border-t border-zinc-800 pt-4 sm:grid-cols-3">
                    <div>
                        <div class="text-sm text-zinc-400">{{ __('Income in the month') }}</div>
                        <div class="mt-1 text-xl font-semibold text-emerald-400">{{ Money::format($totals['income']) }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-zinc-400">{{ __('Expenses in the month') }}</div>
                        <div class="mt-1 text-xl font-semibold text-red-400">{{ Money::format($totals['expense']) }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-zinc-400">{{ __('Result of the month') }}</div>
                        <div @class(['mt-1 text-xl font-semibold', 'text-emerald-400' => $net > 0, 'text-red-400' => $net < 0, 'text-white' => $net === 0])>
                            {{ $net > 0 ? '+' : '' }}{{ Money::format($net) }}
                        </div>
                        @if ($percent !== null)
                            <div class="text-xs text-zinc-500">
                                {{ $percent > 0 ? '+' : '' }}{{ number_format($percent, 1, ',', '.') }}% {{ __('over the opening balance') }}
                            </div>
                        @endif
                    </div>
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

        <x-transactions-panel :transactions="$this->transactions" :month-label="$this->monthLabel" />
    @endif

    <x-transaction-modals
        :allow-income="true"
        :categories="$this->categoryOptions"
        :editing="(bool) $editingTransactionId"
    />

    <flux:modal name="account-form" class="md:w-96">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit account') : __('New account') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Account name')" type="text" required autofocus />

            <flux:select wire:model="type" :label="__('Account type')" :placeholder="__('Select a type')">
                @foreach (App\Enums\AccountType::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div>
                <flux:label>{{ __('Color') }}</flux:label>
                <div class="mt-2 flex flex-wrap gap-3">
                    @foreach (App\Enums\AccountColor::cases() as $option)
                        <button
                            type="button"
                            wire:click="$set('color', '{{ $option->value }}')"
                            title="{{ $option->label() }}"
                            aria-label="{{ $option->label() }}"
                            @class([
                                'size-8 rounded-full ring-offset-2 ring-offset-zinc-800 transition',
                                $option->solid(),
                                'ring-2 ring-white' => $color === $option->value,
                                'opacity-70 hover:opacity-100' => $color !== $option->value,
                            ])
                        ></button>
                    @endforeach
                </div>
                <flux:error name="color" />
            </div>

            <x-money-input
                model="initialBalance"
                :label="__('Initial balance')"
                :description="__('Negative values are allowed, e.g. -50,00.')"
            />

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-account-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="confirm-account-deletion" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete account') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete this account?') }}
                    @if ($this->deletingTransactionsCount > 0)
                        {{ trans_choice('{1} :count transaction of this account will also be deleted.|[2,*] :count transactions of this account will also be deleted.', $this->deletingTransactionsCount) }}
                    @endif
                    {{ __('This action cannot be undone.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-account-button">
                    {{ __('Delete') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
