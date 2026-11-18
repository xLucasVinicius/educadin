@php
    $others = \App\Support\LinkedAccounts::users(auth()->id());
@endphp

{{-- Outras contas deste navegador, adicionar conta e sair. Usado nos menus de perfil. --}}
@if ($others->isNotEmpty())
    <flux:menu.separator />

    <div class="px-2 py-1 text-xs font-medium text-zinc-500" data-test="linked-accounts-title">{{ __('Accounts on this browser') }}</div>

    @foreach ($others as $other)
        <form method="POST" action="{{ route('linked-accounts.switch', $other) }}" class="w-full" wire:key="linked-{{ $other->id }}">
            @csrf
            <flux:menu.item as="button" type="submit" class="w-full cursor-pointer" data-test="switch-account-{{ $other->id }}">
                <span class="flex items-center gap-2">
                    <flux:avatar size="xs" :name="$other->name" :initials="$other->initials()" :src="$other->avatarUrl()" color="auto" />
                    <span class="grid text-start leading-tight">
                        <span class="truncate">{{ $other->name }}</span>
                        <span class="truncate text-xs text-zinc-500">{{ $other->email }}</span>
                    </span>
                </span>
            </flux:menu.item>
        </form>
    @endforeach
@endif

<flux:menu.separator />

<form method="POST" action="{{ route('linked-accounts.add') }}" class="w-full">
    @csrf
    <flux:menu.item as="button" type="submit" icon="user-plus" class="w-full cursor-pointer" data-test="add-account-button">
        {{ __('Add another account') }}
    </flux:menu.item>
</form>

<form method="POST" action="{{ route('linked-accounts.leave') }}" class="w-full">
    @csrf
    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">
        {{ $others->isNotEmpty() ? __('Log out of this account') : __('Log out') }}
    </flux:menu.item>
</form>
