<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $avatar_path Foto de perfil; nulo = foto padrão (iniciais).
 * @property Carbon|null $email_verified_at
 * @property bool $is_admin Acesso ao painel de admin (os donos vêm de ADMIN_EMAILS).
 * @property Carbon|null $suspended_at Conta suspensa por um admin.
 * @property string|null $plan "free" ou "premium".
 * @property CarbonImmutable|null $premium_until Fim do premium; vazio = sem vencimento.
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'suspended_at' => 'datetime',
            'premium_until' => 'immutable_date:Y-m-d',
        ];
    }

    /** Dono do sistema: e-mail em ADMIN_EMAILS (e verificado). */
    public function isOwner(): bool
    {
        return $this->hasVerifiedEmail()
            && in_array(Str::lower($this->email), config('educadin.owner_emails', []), true);
    }

    /** Acesso ao painel de admin: donos e quem recebeu o acesso de um dono. */
    public function isAdmin(): bool
    {
        return $this->isOwner() || ($this->hasVerifiedEmail() && (bool) $this->is_admin);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isPremium(): bool
    {
        return $this->plan === 'premium'
            && ($this->premium_until === null || $this->premium_until->greaterThanOrEqualTo(now()->startOfDay()));
    }

    /**
     * Se este usuário pode suspender, reativar ou excluir o outro: nunca a si
     * mesmo nem um dono; só um dono mexe em outro admin.
     */
    public function canManage(self $target): bool
    {
        if (! $this->isAdmin() || $this->is($target) || $target->isOwner()) {
            return false;
        }

        return ! $target->is_admin || $this->isOwner();
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    /**
     * Endereço da foto de perfil (somente do próprio usuário logado), ou null
     * quando vale a foto padrão. O número na URL força o navegador a recarregar
     * quando a foto muda.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path
            ? route('profile.photo', ['v' => substr(md5($this->avatar_path), 0, 8)])
            : null;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
