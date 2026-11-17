<?php

use App\Concerns\PasswordValidationRules;
use App\Support\PasswordConfirmation;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

new #[Title('Security settings')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /** Senha digitada para liberar 2FA e chaves de acesso. */
    public string $confirm_password = '';

    /** O que o usuário clicou antes de a senha ser pedida; é executado após confirmar. */
    #[Locked]
    public string $pendingIntent = '';

    public bool $canManageTwoFactor;

    public bool $twoFactorEnabled;

    public bool $requiresConfirmation;

    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
    }

    /**
     * 2FA e chaves de acesso só ficam disponíveis depois de confirmar a senha
     * (vale pelo tempo definido em auth.password_timeout, como no Laravel).
     */
    #[Computed]
    public function unlocked(): bool
    {
        return PasswordConfirmation::fresh();
    }

    /**
     * Ação sensível: se a senha ainda não foi confirmada, abre o pedido de senha
     * e guarda o que o usuário quis fazer; se já foi confirmada, executa direto.
     */
    public function requestUnlock(string $intent): void
    {
        if ($this->unlocked) {
            $this->runIntent($intent);

            return;
        }

        $this->reset('confirm_password');
        $this->resetErrorBag();
        $this->pendingIntent = $intent;

        Flux::modal('confirm-password-modal')->show();
    }

    public function unlockSensitive(): void
    {
        $this->validate(['confirm_password' => ['required', 'string']]);

        if (! Hash::check($this->confirm_password, Auth::user()->password)) {
            $this->reset('confirm_password');
            $this->addError('confirm_password', __('auth.password'));

            return;
        }

        // Mesma chave que o middleware "password.confirm" usa, para as rotas do Fortify aceitarem.
        PasswordConfirmation::confirm();

        $this->reset('confirm_password');
        unset($this->unlocked);

        Flux::modal('confirm-password-modal')->close();

        $intent = $this->pendingIntent;
        $this->pendingIntent = '';

        $this->runIntent($intent);
    }

    /**
     * Continua a ação que o usuário tinha clicado antes de confirmar a senha.
     */
    private function runIntent(string $intent): void
    {
        if ($intent === 'enable2fa') {
            $this->dispatch('start-two-factor-setup');
            Flux::modal('two-factor-setup-modal')->show();
        } elseif ($intent === 'disable2fa') {
            $this->disable(app(DisableTwoFactorAuthentication::class));
        } elseif ($intent === 'passkey') {
            $this->js("window.dispatchEvent(new CustomEvent('open-passkey-form'))");
        } elseif (str_starts_with($intent, 'delete:')) {
            $this->confirmDelete((int) substr($intent, 7));
        }
        // "recovery": basta liberar; os códigos aparecem na tela.
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }

    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Show the delete confirmation modal.
     */
    public function confirmDelete(int $passkeyId): void
    {
        abort_unless($this->unlocked, 403);

        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;
    }

    /**
     * Delete the passkey.
     */
    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        abort_unless($this->unlocked, 403);

        if (! $this->deletingPasskeyId) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(auth()->user(), $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();
    }

    /**
     * Close the delete confirmation modal.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }

    /**
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        abort_unless($this->unlocked, 403);

        $disableTwoFactorAuthentication(auth()->user());

        $this->twoFactorEnabled = false;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Security settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
        <form method="POST" wire:submit="updatePassword" class="mt-6 space-y-6">
            <flux:input
                wire:model="current_password"
                :label="__('Current password')"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />
            <flux:input
                wire:model="password"
                :label="__('New password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />
            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="update-password-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>

        @if ($canManageTwoFactor)
            <section class="mt-12">
                <flux:heading>{{ __('Two-factor authentication') }}</flux:heading>
                <flux:subheading>{{ __('Manage your two-factor authentication settings') }}</flux:subheading>

                <div class="flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                    @if ($twoFactorEnabled)
                        <div class="space-y-4">
                            <flux:text>
                                {{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}
                            </flux:text>

                            <div class="flex justify-start">
                                <flux:button
                                    variant="danger"
                                    wire:click="requestUnlock('disable2fa')"
                                >
                                    {{ __('Disable 2FA') }}
                                </flux:button>
                            </div>

                            @if ($this->unlocked)
                                <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                            @else
                                <div class="flex items-center justify-between gap-4 rounded-xl border border-zinc-800 bg-zinc-900/40 p-4">
                                    <div>
                                        <flux:heading>{{ __('2FA recovery codes') }}</flux:heading>
                                        <flux:text class="mt-1 text-sm">{{ __('Confirm your password to see your recovery codes.') }}</flux:text>
                                    </div>
                                    <flux:button size="sm" wire:click="requestUnlock('recovery')">{{ __('View recovery codes') }}</flux:button>
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="space-y-4">
                            <flux:text variant="subtle">
                                {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}
                            </flux:text>

                            @if ($this->unlocked)
                                <flux:modal.trigger name="two-factor-setup-modal">
                                    <flux:button
                                        variant="primary"
                                        wire:click="$dispatch('start-two-factor-setup')"
                                    >
                                        {{ __('Enable 2FA') }}
                                    </flux:button>
                                </flux:modal.trigger>
                            @else
                                <flux:button variant="primary" wire:click="requestUnlock('enable2fa')">
                                    {{ __('Enable 2FA') }}
                                </flux:button>
                            @endif

                            <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                        </div>
                    @endif
                </div>
            </section>
        @endif

        @if ($canManagePasskeys)
            <section class="mt-12">
                <flux:heading>{{ __('Passkeys') }}</flux:heading>
                <flux:subheading>{{ __('Manage your passkeys for passwordless sign-in') }}</flux:subheading>

                <div class="mt-6 flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                    <div class="border rounded-lg border-zinc-200 dark:border-zinc-700 overflow-hidden">
                        @forelse ($passkeys as $passkey)
                            <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-zinc-200 dark:border-zinc-700' : '' }}">
                                <div class="flex items-center gap-4">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                                        <flux:icon.key class="size-5 text-zinc-500 dark:text-zinc-400" />
                                    </div>
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2.5">
                                            <p class="font-medium tracking-tight">{{ $passkey['name'] }}</p>
                                            @if ($passkey['authenticator'])
                                                <flux:badge size="sm">{{ $passkey['authenticator'] }}</flux:badge>
                                            @endif
                                        </div>
                                        <p class="text-zinc-500 dark:text-zinc-400 text-xs">
                                            {{ __('Added :time', ['time' => $passkey['created_at_diff']]) }}
                                            @if ($passkey['last_used_at_diff'])
                                                <span class="opacity-50 mx-1">/</span>
                                                {{ __('Last used :time', ['time' => $passkey['last_used_at_diff']]) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    icon:variant="outline"
                                    wire:click="requestUnlock('delete:{{ $passkey['id'] }}')"
                                    class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                />
                            </div>
                        @empty
                            <div class="p-8 text-center">
                                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-zinc-100 dark:bg-zinc-800">
                                    <flux:icon.key class="size-7 text-zinc-400 dark:text-zinc-500" />
                                </div>
                                <p class="font-medium">{{ __('No passkeys yet') }}</p>
                                <flux:text class="mt-1">{{ __('Add a passkey to sign in without a password') }}</flux:text>
                            </div>
                        @endforelse
                    </div>

                    <x-passkey-registration :locked="! $this->unlocked" />
                </div>
            </section>
        @endif
    </x-pages::settings.layout>

    <flux:modal name="confirm-password-modal" class="min-w-[22rem] md:w-96">
        <form wire:submit="unlockSensitive" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Confirm your password') }}</flux:heading>
                <flux:text class="mt-2">{{ __('For your security, enter your password to continue.') }}</flux:text>
            </div>

            <flux:input
                wire:model="confirm_password"
                :label="__('Password')"
                type="password"
                required
                autofocus
                autocomplete="current-password"
                viewable
            />

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="unlock-security-button">{{ __('Confirm') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal
        name="delete-passkey-modal"
        class="max-w-md md:min-w-md"
        @close="closeDeleteModal"
        wire:model="showDeleteModal"
    >
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Remove passkey') }}</flux:heading>
                <flux:text>
                    {{ __('Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.', ['name' => $deletingPasskeyName]) }}
                </flux:text>
            </div>

            <div class="flex gap-3 justify-end">
                <flux:button
                    variant="outline"
                    wire:click="closeDeleteModal"
                >
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    variant="danger"
                    wire:click="deletePasskey"
                >
                    {{ __('Remove passkey') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
