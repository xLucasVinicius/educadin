@props(['locked' => false])

@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-on:open-passkey-form.window="showForm = true"
    x-data="{
        supported: false,
        showForm: false,
        name: '',
        loading: false,
        error: null,
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        getDefaultPasskeyName() {
            const ua = navigator.userAgent;

            const browser = [
                { pattern: /Edg|Edge/, name: 'Edge' },
                { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
                { pattern: /Firefox|FxiOS/, name: 'Firefox' },
                { pattern: /Chrome|CriOS/, name: 'Chrome' },
                { pattern: /Safari/, name: 'Safari' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            const os = [
                { pattern: /iPhone/, name: 'iPhone' },
                { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
                { pattern: /Android/, name: 'Android' },
                { pattern: /Mac/, name: 'Mac' },
                { pattern: /Windows/, name: 'Windows' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            return [browser, os].filter(Boolean).join(' em ') || '';
        },
        init() {
            this.name = this.getDefaultPasskeyName();
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async register() {
            if (!this.name.trim()) return;

            this.loading = true;
            this.error = null;

            try {
                await window.Passkeys.register({ name: this.name });
                this.name = '';
                this.showForm = false;
                await $wire.loadPasskeys();
            } catch (e) {
                if (e.constructor?.name !== 'UserCancelledError') {
                    // Mensagens do navegador vêm em inglês: mostramos as nossas.
                    const messages = {
                        NotAllowedError: @js(__('The operation was cancelled or timed out. Try again.')),
                        InvalidStateError: @js(__('This passkey is already registered on this device.')),
                        SecurityError: @js(__('The browser blocked the passkey on this address. Use https or localhost.')),
                        NotSupportedError: @js(__('This device does not support passkeys.')),
                    };
                    this.error = messages[e.name] ?? @js(__('Could not register the passkey. Please try again.'));
                    console.error(e);
                }
            } finally {
                this.loading = false;
            }
        },
        cancel() {
            this.showForm = false;
            this.name = '';
            this.error = null;
        },
    }"
>
    <template x-if="!supported">
        <flux:text>{{ __('Passkeys are not supported in this browser.') }}</flux:text>
    </template>

    <template x-if="supported && !showForm">
        <div>
            @if ($locked)
                <flux:button variant="primary" icon="plus" wire:click="requestUnlock('passkey')">
                    {{ __('Add passkey') }}
                </flux:button>
            @else
                <flux:button variant="primary" icon="plus" x-on:click="showForm = true">
                    {{ __('Add passkey') }}
                </flux:button>
            @endif
        </div>
    </template>

    <template x-if="supported && showForm">
        <div class="space-y-4 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 p-4">
            <flux:input
                label="{{ __('Passkey name') }}"
                x-model="name"
                placeholder="{{ __('e.g., MacBook Pro, iPhone') }}"
                x-on:keydown.enter.prevent="register()"
                x-ref="passkeyNameInput"
                x-init="$nextTick(() => $refs.passkeyNameInput?.focus())"
            />
            <flux:text class="!mt-1">{{ __('Give this passkey a name to help you identify it later.') }}</flux:text>

            <p x-show="error" x-text="error" x-cloak class="text-sm text-red-600 dark:text-red-400"></p>

            <div class="flex gap-2">
                <flux:button
                    variant="primary"
                    x-on:click="register()"
                    x-bind:disabled="loading || !name.trim()"
                >
                    <span x-show="!loading">{{ __('Register passkey') }}</span>
                    <span x-show="loading" x-cloak>{{ __('Registering...') }}</span>
                </flux:button>
                <flux:button
                    variant="ghost"
                    x-on:click="cancel()"
                >
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </div>
    </template>
</div>
