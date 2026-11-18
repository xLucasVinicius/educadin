<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-900">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-zinc-950">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Dashboard') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="building-library" :href="route('accounts.index')" :current="request()->routeIs('accounts.*')" wire:navigate>
                    {{ __('Accounts') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="credit-card" :href="route('cards.index')" :current="request()->routeIs('cards.*')" wire:navigate>
                    {{ __('Cards') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="chart-bar" :href="route('performance.index')" :current="request()->routeIs('performance.*')" wire:navigate data-test="performance-link">
                    {{ __('Performance') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="banknotes" :href="route('plans.index')" :current="request()->routeIs('plans.*')" wire:navigate data-test="plans-link">
                    {{ __('Plans') }}
                </flux:sidebar.item>

            </flux:sidebar.nav>

            <flux:spacer />

            @if (auth()->user()->isAdmin())
                <flux:sidebar.nav>
                    <flux:sidebar.item icon="shield-check" :href="route('admin.index')" :current="request()->routeIs('admin.*')" wire:navigate data-test="admin-link">
                        {{ __('Admin') }}
                    </flux:sidebar.item>
                </flux:sidebar.nav>
            @endif

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    :avatar="auth()->user()->avatarUrl()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                    :src="auth()->user()->avatarUrl()"
                                    color="auto"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <x-linked-accounts-menu />
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
