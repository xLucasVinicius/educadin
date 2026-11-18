<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Plans')] class extends Component {
    /** Plano atual do usuário: "free" ou "premium" (premium vencido volta a ser gratuito). */
    #[Computed]
    public function currentPlan(): string
    {
        return Auth::user()->isPremium() ? 'premium' : 'free';
    }

    /**
     * Conteúdo dos planos (editável em config/educadin.php).
     *
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function plans(): array
    {
        return config('educadin.plans', []);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Plans') }}</flux:heading>
        <flux:subheading>{{ __('See what each plan offers.') }}</flux:subheading>
    </div>

    <div class="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-200" data-test="plans-preview-notice">
        <flux:icon.information-circle class="mt-0.5 size-5 shrink-0" />
        <span>{{ __('Preview: the benefits and prices below are illustrative and will change. For now, all features are available to everyone.') }}</span>
    </div>

    @php
        $plans = $this->plans;
        $current = $this->currentPlan;
        $premiumUntil = auth()->user()->premium_until;
    @endphp

    <div class="grid max-w-3xl gap-4 md:grid-cols-2">
        @foreach (['free', 'premium'] as $key)
            @php
                $plan = $plans[$key];
                $isCurrent = $current === $key;
            @endphp

            <div
                wire:key="plan-{{ $key }}"
                data-test="plan-{{ $key }}"
                @class([
                    'relative flex flex-col rounded-xl border bg-zinc-900/60 p-5',
                    'border-accent' => $key === 'premium',
                    'border-zinc-800' => $key !== 'premium',
                ])
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-semibold {{ $key === 'premium' ? 'text-accent' : 'text-white' }}">{{ $plan['name'] }}</h2>
                        <p class="mt-1 text-sm text-zinc-400">{{ $plan['description'] }}</p>
                    </div>

                    @if ($isCurrent)
                        <flux:badge color="emerald" data-test="current-plan-badge">{{ __('Your plan') }}</flux:badge>
                    @endif
                </div>

                <div class="mt-3 flex items-baseline gap-2">
                    <span class="text-3xl font-semibold text-white">{{ $plan['price'] }}</span>
                    <span class="text-sm text-zinc-400">{{ $plan['period'] }}</span>
                </div>

                @if ($key === 'premium' && $isCurrent && $premiumUntil)
                    <p class="mt-2 text-sm text-zinc-400">{{ __('Valid until :date', ['date' => $premiumUntil->format('d/m/Y')]) }}</p>
                @endif

                <ul class="mt-4 space-y-2 text-sm">
                    @if ($key === 'premium')
                        <li class="flex items-start gap-3 font-medium text-white">
                            <flux:icon.check-circle class="mt-0.5 size-4 shrink-0 text-emerald-400" />
                            {{ __('Everything in the Free plan') }}
                        </li>
                    @endif

                    @foreach ($plan['features'] as $feature)
                        <li class="flex items-start gap-3 text-zinc-200">
                            <flux:icon.check-circle class="mt-0.5 size-4 shrink-0 text-emerald-400" />
                            {{ $feature }}
                        </li>
                    @endforeach

                </ul>

                <div class="mt-auto pt-5">
                    @if ($isCurrent)
                        <flux:button class="w-full" disabled>{{ __('Current plan') }}</flux:button>
                    @elseif ($key === 'premium')
                        <flux:button class="w-full" variant="primary" disabled data-test="subscribe-button">{{ __('Subscription coming soon') }}</flux:button>
                    @else
                        <flux:button class="w-full" disabled>{{ __('Free plan') }}</flux:button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</section>
