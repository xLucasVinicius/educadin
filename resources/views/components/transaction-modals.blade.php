@props(['allowIncome' => true, 'categories' => [], 'editing' => false, 'allowInstallments' => false, 'deletingInstallment' => null, 'recurring' => [], 'cancellingRecurring' => null, 'editingRecurring' => null])

{{-- Modais de criar/editar e de excluir lançamento, usados por Contas e Cartões. --}}
<flux:modal name="transaction-form" class="md:w-[28rem]">
    <form wire:submit="saveTransaction" class="space-y-6">
        <flux:heading size="lg">
            {{ $editing ? __('Edit transaction') : __('New transaction') }}
        </flux:heading>

        @if ($allowIncome)
            <flux:radio.group wire:model.live="kind" variant="segmented">
                <flux:radio value="expense" :label="__('Expense')" />
                <flux:radio value="income" :label="__('Income')" />
            </flux:radio.group>
        @endif

        <flux:input wire:model="description" :label="__('Description')" type="text" required autofocus />

        <x-money-input
            model="amount"
            :label="$allowInstallments && ! $editing ? __('Total amount') : __('Amount')"
        />

        @if ($allowInstallments && ! $editing)
            <div x-show="! $wire.repeatMonthly">
            <flux:select wire:model="installments" :label="__('Installments')" :description="__('The total is split into monthly installments, one per invoice.')">
                <flux:select.option value="1">{{ __('Single payment') }}</flux:select.option>
                @for ($n = 2; $n <= \App\Support\PlanLimits::installmentsMax(auth()->user()); $n++)
                    <flux:select.option value="{{ $n }}">{{ $n }}x</flux:select.option>
                @endfor
            </flux:select>
            </div>
        @endif

        <flux:input wire:model="date" :label="__('Date')" type="date" required />

        @if (! $editing)
            <flux:switch wire:model="repeatMonthly" :label="__('Repeat every month')" :description="__('Posted automatically on the same day each month, only when the day arrives.')" data-test="repeat-monthly-switch" />
        @endif

        <flux:select wire:model="category" :label="__('Category')" :placeholder="__('Select a category')">
            @foreach ($categories as $category)
                <flux:select.option value="{{ $category->value }}">{{ $category->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex gap-2">
            <flux:spacer />
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" type="submit" data-test="save-transaction-button">{{ __('Save') }}</flux:button>
        </div>
    </form>
</flux:modal>

<flux:modal name="confirm-transaction-deletion" class="min-w-[22rem]">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Delete transaction') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Are you sure you want to delete this transaction?') }}
                {{ __('This action cannot be undone.') }}
            </flux:text>
        </div>

        @if ($deletingInstallment)
            <flux:radio.group wire:model="deleteScope" :label="__('Installment :number of :total', ['number' => $deletingInstallment->installment_number, 'total' => $deletingInstallment->installment_total])">
                <flux:radio value="one" :label="__('Only this installment')" />
                <flux:radio value="following" :label="__('This and the following installments')" />
                <flux:radio value="all" :label="__('All installments of the purchase')" />
            </flux:radio.group>
        @endif

        <div class="flex gap-2">
            <flux:spacer />
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" wire:click="deleteTransaction" data-test="confirm-delete-transaction-button">
                {{ __('Delete') }}
            </flux:button>
        </div>
    </div>
</flux:modal>

<flux:modal name="recurring-list" class="md:w-[32rem]">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Recurring transactions') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Posted automatically every month. Cancel to stop future months; what was already posted stays.') }}</flux:text>
        </div>

        @if (count($recurring) === 0)
            <div class="rounded-xl border border-dashed border-zinc-800 px-6 py-8 text-center">
                <flux:text>{{ __('No recurring transactions.') }}</flux:text>
            </div>
        @else
            <div class="divide-y divide-zinc-800 rounded-xl border border-zinc-800">
                @foreach ($recurring as $item)
                    <div wire:key="recurring-{{ $item->id }}" class="flex items-center gap-3 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium text-white">{{ $item->description }}</div>
                            <div class="text-sm text-zinc-400">
                                {{ __('Every month on day :day', ['day' => $item->day_of_month]) }} · {{ __('Next: :date', ['date' => $item->next_due_on->format('d/m/Y')]) }}
                            </div>
                        </div>

                        <div @class(['whitespace-nowrap font-semibold', 'text-emerald-400' => $item->type === \App\Enums\TransactionType::Income, 'text-red-400' => $item->type !== \App\Enums\TransactionType::Income])>
                            {{ \App\Support\Money::format($item->amount) }}
                        </div>

                        <flux:button size="sm" variant="ghost" wire:click="editRecurring({{ $item->id }})" data-test="edit-recurring-button">
                            {{ __('Edit') }}
                        </flux:button>

                        <flux:button size="sm" variant="ghost" wire:click="confirmCancelRecurring({{ $item->id }})" data-test="cancel-recurring-button">
                            {{ __('Cancel') }}
                        </flux:button>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="flex">
            <flux:spacer />
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Close') }}</flux:button>
            </flux:modal.close>
        </div>
    </div>
</flux:modal>

<flux:modal name="confirm-recurring-cancel" class="min-w-[22rem]">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Cancel recurring transaction') }}</flux:heading>
            <flux:text class="mt-2">
                @if ($cancellingRecurring)
                    {{ __('":description" will no longer be posted in the next months. Transactions already posted stay in your history.', ['description' => $cancellingRecurring->description]) }}
                @endif
            </flux:text>
        </div>

        <div class="flex gap-2">
            <flux:spacer />
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Keep') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" wire:click="cancelRecurring" data-test="confirm-cancel-recurring-button">
                {{ __('Cancel recurrence') }}
            </flux:button>
        </div>
    </div>
</flux:modal>

<flux:modal name="recurring-edit" class="md:w-[28rem]">
    <form wire:submit="saveRecurring" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Edit recurring amount') }}</flux:heading>
            @if ($editingRecurring)
                <flux:text class="mt-2">
                    {{ __('The new amount of ":description" applies only to the next months. Transactions already posted keep their amount.', ['description' => $editingRecurring->description]) }}
                </flux:text>
            @endif
        </div>

        <x-money-input model="recurringAmount" :label="__('Amount')" />

        <div class="flex gap-2">
            <flux:spacer />
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" type="submit" data-test="save-recurring-button">{{ __('Save') }}</flux:button>
        </div>
    </form>
</flux:modal>
