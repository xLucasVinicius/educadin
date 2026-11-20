<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-4 sm:gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @php
            $savedAccounts = \App\Support\LinkedAccounts::users();
            $knownAccounts = \App\Support\KnownAccounts::users($savedAccounts->pluck('id')->all());
        @endphp

        @if ($savedAccounts->isNotEmpty() || $knownAccounts->isNotEmpty())
            <div class="flex flex-col gap-1 rounded-xl border border-zinc-800 p-3" data-test="saved-accounts">
                <div class="mb-1 text-xs font-medium text-zinc-500">{{ __('Accounts used on this browser') }}</div>

                {{-- Ainda abertas neste navegador: entram direto. --}}
                @foreach ($savedAccounts as $saved)
                    <form method="POST" action="{{ route('linked-accounts.switch', $saved) }}">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-3 rounded-lg px-2 py-1.5 text-start hover:bg-zinc-800" data-test="saved-account-{{ $saved->id }}">
                            <flux:avatar size="sm" :name="$saved->name" :initials="$saved->initials()" :src="$saved->avatarUrl()" color="auto" />
                            <span class="grid min-w-0 flex-1 leading-tight">
                                <span class="truncate text-sm font-medium">{{ $saved->name }}</span>
                                <span class="truncate text-xs text-zinc-500">{{ $saved->email }}</span>
                            </span>
                            <span class="text-xs text-emerald-400">{{ __('Signed in') }}</span>
                        </button>
                    </form>
                @endforeach

                {{-- Já entraram um dia: pedem a senha de novo. --}}
                @foreach ($knownAccounts as $known)
                    <div class="flex items-center gap-1" wire:key="known-{{ $known->id }}">
                        <flux:modal.trigger name="known-login-{{ $known->id }}">
                            <button
                                type="button"
                                class="flex min-w-0 flex-1 items-center gap-3 rounded-lg px-2 py-1.5 text-start hover:bg-zinc-800"
                                data-test="known-account-{{ $known->id }}"
                            >
                                <flux:avatar size="sm" :name="$known->name" :initials="$known->initials()" :src="$known->avatarUrl()" color="auto" />
                                <span class="grid min-w-0 flex-1 leading-tight">
                                    <span class="truncate text-sm font-medium">{{ $known->name }}</span>
                                    <span class="truncate text-xs text-zinc-500">{{ $known->email }}</span>
                                </span>
                                <flux:icon.lock-closed class="size-3.5 text-zinc-500" />
                            </button>
                        </flux:modal.trigger>

                        <form method="POST" action="{{ route('linked-accounts.forget', $known) }}">
                            @csrf
                            <button type="submit" class="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-800 hover:text-white" aria-label="{{ __('Remove from this list') }}" data-test="forget-account-{{ $known->id }}">
                                <flux:icon.x-mark class="size-4" />
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>

            {{-- Confirmação de senha para entrar numa conta lembrada. --}}
            @foreach ($knownAccounts as $known)
                @php
                    $failed = $errors->has('email') && old('from_known') && old('email') === $known->email;
                @endphp
                <flux:modal name="known-login-{{ $known->id }}" class="md:w-96">
                    <form method="POST" action="{{ route('login.store') }}" class="space-y-6" data-test="known-login-form-{{ $known->id }}">
                        @csrf
                        <input type="hidden" name="email" value="{{ $known->email }}">
                        <input type="hidden" name="from_known" value="1">

                        <div class="flex flex-col items-center gap-2 text-center">
                            <flux:avatar size="xl" :name="$known->name" :initials="$known->initials()" :src="$known->avatarUrl()" color="auto" />
                            <div>
                                <flux:heading size="lg">{{ $known->name }}</flux:heading>
                                <flux:text class="text-sm">{{ $known->email }}</flux:text>
                            </div>
                        </div>

                        <div>
                            <flux:input name="password" type="password" :label="__('Password')" autocomplete="current-password" required viewable :invalid="$failed" />
                            @if ($failed)
                                <div class="mt-2 text-sm font-medium text-red-400" data-test="known-login-error">
                                    {{ $errors->first('email') === __('auth.failed') ? __('Incorrect password.') : $errors->first('email') }}
                                </div>
                            @endif
                        </div>

                        <div class="flex gap-2">
                            <flux:modal.close>
                                <flux:button variant="ghost" class="flex-1">{{ __('Cancel') }}</flux:button>
                            </flux:modal.close>
                            <flux:button type="submit" variant="primary" class="flex-1" data-test="known-login-submit-{{ $known->id }}">{{ __('Log in') }}</flux:button>
                        </div>
                    </form>

                    @if ($failed)
                        <div x-data x-init="$nextTick(() => $flux.modal('known-login-{{ $known->id }}').show())"></div>
                    @endif
                </flux:modal>
            @endforeach
        @endif

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" @class(['flex flex-col gap-4 sm:gap-6', '[&_[data-flux-error]]:!hidden [&_[data-flux-control]]:!border-white/10' => old('from_known')])>
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('from_known') ? '' : old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="voce@email.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Don\'t have an account?') }}</span>
            <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
