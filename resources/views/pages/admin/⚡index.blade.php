<?php

use App\Models\User;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Admin')] class extends Component {
    use WithPagination;

    #[Url(as: 'busca')]
    public string $search = '';

    /** Usuário alvo da ação em andamento (suspender, excluir ou editar o plano). */
    public ?int $targetId = null;

    public string $plan = 'free';

    public string $premiumUntil = '';

    /** Quem está usando o painel; nega o acesso a quem não é (mais) admin. */
    protected function admin(): User
    {
        $user = Auth::user();

        abort_unless($user?->isAdmin(), 404);

        return $user;
    }

    /** Usuário alvo que o admin atual pode suspender, reativar ou excluir. */
    protected function managedTarget(int $id): User
    {
        $target = User::findOrFail($id);

        abort_unless($this->admin()->canManage($target), 403);

        return $target;
    }

    /**
     * Números gerais de cadastro (nada de dados financeiros).
     *
     * @return array<string, int>
     */
    #[Computed]
    public function stats(): array
    {
        $this->admin();

        $owners = config('educadin.owner_emails', []);

        return [
            'total' => User::count(),
            'verified' => User::whereNotNull('email_verified_at')->count(),
            'last7' => User::where('created_at', '>=', now()->subDays(7))->count(),
            'last30' => User::where('created_at', '>=', now()->subDays(30))->count(),
            'suspended' => User::whereNotNull('suspended_at')->count(),
            'premium' => User::where('plan', 'premium')
                ->where(fn ($q) => $q->whereNull('premium_until')->orWhere('premium_until', '>=', now()->toDateString()))
                ->count(),
            'admins' => User::where(fn ($q) => $q->where('is_admin', true)->orWhereIn(DB::raw('lower(email)'), $owners ?: ['']))->count(),
        ];
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $this->admin();

        $term = trim($this->search);

        return User::query()
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10);
    }

    #[Computed]
    public function target(): ?User
    {
        return $this->targetId ? User::find($this->targetId) : null;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    protected function refreshData(): void
    {
        unset($this->stats, $this->users, $this->target);
    }

    /** Encerra as sessões abertas do usuário (quando as sessões ficam no banco). */
    protected function endSessions(User $user): void
    {
        try {
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        } catch (\Throwable) {
            // Sem sessões no banco: o bloqueio acontece no próximo acesso.
        }
    }

    public function confirmSuspend(int $id): void
    {
        $this->targetId = $this->managedTarget($id)->id;
        unset($this->target);

        Flux::modal('confirm-suspension')->show();
    }

    public function suspend(): void
    {
        $target = $this->managedTarget((int) $this->targetId);

        $target->forceFill(['suspended_at' => now()])->save();
        $this->endSessions($target);

        $this->targetId = null;
        $this->refreshData();

        Flux::modal('confirm-suspension')->close();
        Flux::toast(variant: 'success', text: __('Account suspended.'));
    }

    public function reactivate(int $id): void
    {
        $this->managedTarget($id)->forceFill(['suspended_at' => null])->save();

        $this->refreshData();
        Flux::toast(variant: 'success', text: __('Account reactivated.'));
    }

    public function confirmDelete(int $id): void
    {
        $this->targetId = $this->managedTarget($id)->id;
        unset($this->target);

        Flux::modal('confirm-user-deletion')->show();
    }

    public function delete(): void
    {
        $target = $this->managedTarget((int) $this->targetId);

        $this->endSessions($target);

        if ($target->avatar_path) {
            Storage::disk('local')->delete($target->avatar_path);
        }

        $target->delete();

        $this->targetId = null;
        $this->refreshData();

        Flux::modal('confirm-user-deletion')->close();
        Flux::toast(variant: 'success', text: __('Account deleted.'));
    }

    /** Dar ou tirar o acesso de admin: só os donos (ADMIN_EMAILS) podem. */
    public function toggleAdmin(int $id): void
    {
        $actor = $this->admin();
        abort_unless($actor->isOwner(), 403);

        $target = User::findOrFail($id);
        abort_if($target->isOwner() || $actor->is($target), 403);

        $target->forceFill(['is_admin' => ! $target->is_admin])->save();

        $this->refreshData();
        Flux::toast(variant: 'success', text: $target->is_admin
            ? __('Admin access granted.')
            : __('Admin access removed.'));
    }

    public function editPlan(int $id): void
    {
        $this->admin();

        $user = User::findOrFail($id);

        $this->resetValidation();
        $this->targetId = $user->id;
        $this->plan = $user->plan === 'premium' ? 'premium' : 'free';
        $this->premiumUntil = $user->premium_until?->toDateString() ?? '';
        unset($this->target);

        Flux::modal('plan-form')->show();
    }

    public function savePlan(): void
    {
        $this->admin();

        $user = User::findOrFail($this->targetId);

        $validated = $this->validate([
            'plan' => ['required', 'in:free,premium'],
            'premiumUntil' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $user->forceFill([
            'plan' => $validated['plan'],
            // O vencimento só faz sentido no premium; vazio = sem vencimento.
            'premium_until' => $validated['plan'] === 'premium' && $validated['premiumUntil']
                ? $validated['premiumUntil']
                : null,
        ])->save();

        $this->targetId = null;
        $this->refreshData();

        Flux::modal('plan-form')->close();
        Flux::toast(variant: 'success', text: __('Plan updated.'));
    }
}; ?>

<section class="flex w-full flex-col gap-4 lg:h-[calc(100dvh-4.5rem)]">
    <div class="shrink-0">
        <flux:heading size="xl" level="1">{{ __('Admin') }}</flux:heading>
        <flux:subheading>{{ __('Registered users and access. Financial data is never shown here.') }}</flux:subheading>
    </div>

    @php
        $stats = $this->stats;
        $cards = [
            ['label' => __('Users'), 'value' => $stats['total']],
            ['label' => __('New in 7 days'), 'value' => $stats['last7']],
            ['label' => __('New in 30 days'), 'value' => $stats['last30']],
            ['label' => __('Verified e-mail'), 'value' => $stats['verified']],
            ['label' => __('Premium'), 'value' => $stats['premium']],
            ['label' => __('Admins'), 'value' => $stats['admins']],
            ['label' => __('Suspended'), 'value' => $stats['suspended']],
        ];
        $me = auth()->user();
    @endphp

    <div class="grid shrink-0 grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7" data-test="admin-stats">
        @foreach ($cards as $card)
            <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4">
                <div class="text-sm text-zinc-400">{{ $card['label'] }}</div>
                <div class="mt-1 text-2xl font-semibold text-accent">{{ $card['value'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="flex shrink-0 items-center justify-between gap-3">
        <flux:heading size="lg">{{ __('Users') }}</flux:heading>
        <div class="w-full max-w-xs">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name or e-mail')" clearable data-test="admin-search" />
        </div>
    </div>

    @if ($this->users->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-800 px-6 py-12 text-center">
            <flux:text>{{ __('No users found.') }}</flux:text>
        </div>
    @else
        <div class="min-h-0 flex-1 divide-y divide-zinc-800 overflow-y-auto rounded-xl border border-zinc-800 bg-zinc-900/40" data-test="admin-user-list">
            @foreach ($this->users as $user)
                <div wire:key="user-{{ $user->id }}" class="flex flex-wrap items-center gap-4 px-4 py-3">
                    @if ($user->avatar_path)
                        <img
                            src="{{ route('admin.users.photo', ['user' => $user, 'v' => substr(md5($user->avatar_path), 0, 8)]) }}"
                            alt="{{ $user->name }}"
                            class="size-10 shrink-0 rounded-full object-cover"
                            data-test="admin-user-photo-{{ $user->id }}"
                        />
                    @else
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-800 text-sm font-semibold text-zinc-200">
                            {{ $user->initials() }}
                        </div>
                    @endif

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate font-medium text-white">{{ $user->name }}</span>
                            @if ($user->isOwner())
                                <flux:badge size="sm" color="amber">{{ __('Owner') }}</flux:badge>
                            @elseif ($user->is_admin)
                                <flux:badge size="sm" color="amber">{{ __('Admin') }}</flux:badge>
                            @endif
                            @if ($user->isPremium())
                                <flux:badge size="sm" color="emerald">
                                    {{ __('Premium') }}@if ($user->premium_until) · {{ __('until :date', ['date' => $user->premium_until->format('d/m/Y')]) }}@endif
                                </flux:badge>
                            @elseif ($user->plan === 'premium')
                                <flux:badge size="sm" color="orange">{{ __('Premium expired') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('Free') }}</flux:badge>
                            @endif
                            @if ($user->isSuspended())
                                <flux:badge size="sm" color="red">{{ __('Suspended') }}</flux:badge>
                            @endif
                            @unless ($user->hasVerifiedEmail())
                                <flux:badge size="sm" color="zinc">{{ __('E-mail not verified') }}</flux:badge>
                            @endunless
                        </div>
                        <div class="truncate text-sm text-zinc-400">
                            {{ $user->email }} · {{ __('Joined :date', ['date' => $user->created_at->format('d/m/Y')]) }}
                            @if ($user->two_factor_confirmed_at) · {{ __('2FA on') }}@endif
                        </div>
                    </div>

                    <flux:dropdown align="end">
                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" data-test="user-menu-button" />

                        <flux:menu>
                            <flux:menu.item icon="star" wire:click="editPlan({{ $user->id }})">
                                {{ __('Edit plan') }}
                            </flux:menu.item>

                            @if ($me->isOwner() && ! $user->isOwner() && ! $me->is($user))
                                <flux:menu.item icon="shield-check" wire:click="toggleAdmin({{ $user->id }})">
                                    {{ $user->is_admin ? __('Remove admin access') : __('Make admin') }}
                                </flux:menu.item>
                            @endif

                            @if ($me->canManage($user))
                                <flux:menu.separator />

                                @if ($user->isSuspended())
                                    <flux:menu.item icon="play" wire:click="reactivate({{ $user->id }})">
                                        {{ __('Reactivate account') }}
                                    </flux:menu.item>
                                @else
                                    <flux:menu.item icon="pause" wire:click="confirmSuspend({{ $user->id }})">
                                        {{ __('Suspend account') }}
                                    </flux:menu.item>
                                @endif

                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $user->id }})">
                                    {{ __('Delete account') }}
                                </flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @endforeach
        </div>

        <div class="shrink-0">
            <flux:pagination :paginator="$this->users" />
        </div>
    @endif

    <flux:modal name="confirm-suspension" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Suspend account') }}</flux:heading>
                <flux:text class="mt-2">
                    @if ($this->target)
                        {{ __(':name will be signed out and will not be able to sign in until the account is reactivated. No data is deleted.', ['name' => $this->target->name]) }}
                    @endif
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="suspend" data-test="confirm-suspend-button">{{ __('Suspend') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-user-deletion" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete account') }}</flux:heading>
                <flux:text class="mt-2">
                    @if ($this->target)
                        {{ __('The account of :name and all of its data (accounts, cards, transactions) will be permanently deleted.', ['name' => $this->target->name]) }}
                    @endif
                    {{ __('This action cannot be undone.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-user-button">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="plan-form" class="md:w-[28rem]">
        <form wire:submit="savePlan" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Edit plan') }}</flux:heading>
                @if ($this->target)
                    <flux:text class="mt-1">{{ $this->target->name }} · {{ $this->target->email }}</flux:text>
                @endif
            </div>

            <flux:select wire:model.live="plan" :label="__('Plan')">
                <flux:select.option value="free">{{ __('Free') }}</flux:select.option>
                <flux:select.option value="premium">{{ __('Premium') }}</flux:select.option>
            </flux:select>

            <div x-show="$wire.plan === 'premium'">
                <flux:input wire:model="premiumUntil" type="date" :label="__('Premium until')" :description="__('Leave empty for no expiration.')" />
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-plan-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
