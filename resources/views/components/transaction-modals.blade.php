@props(['allowIncome' => true, 'categories' => [], 'editing' => false])

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

        <x-money-input model="amount" :label="__('Amount')" />

        <flux:input wire:model="date" :label="__('Date')" type="date" required />

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
