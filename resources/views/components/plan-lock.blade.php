@props(['deleteId'])

{{-- Capa vermelha com cadeado sobre um item que passa do limite do plano. --}}
<div class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-2 rounded-2xl bg-red-950/85 p-4 text-center backdrop-blur-sm" data-test="plan-lock">
    <flux:icon.lock-closed class="size-8 text-red-300" />
    <div class="font-medium text-red-100">{{ __('Locked by your plan') }}</div>
    <div class="max-w-md text-sm text-red-200/80">{{ __('This item is over the limit of your current plan. Subscribe to Premium to unlock it.') }}</div>
    <div class="mt-1 flex gap-2">
        <flux:button size="sm" variant="primary" :href="route('plans.index')" wire:navigate>{{ __('See plans') }}</flux:button>
        <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $deleteId }})" data-test="locked-delete-button">{{ __('Delete') }}</flux:button>
    </div>
</div>
