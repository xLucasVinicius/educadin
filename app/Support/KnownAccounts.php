<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;

/**
 * Contas que já entraram alguma vez neste navegador, guardadas em um cookie
 * (criptografado pelo Laravel). Servem só para lembrar quem é: para entrar de
 * novo a senha sempre é pedida.
 */
class KnownAccounts
{
    public const COOKIE = 'known_accounts';

    public const MAX = 8;

    /**
     * @return array<int, int> do mais antigo para o mais recente
     */
    public static function ids(): array
    {
        $raw = request()->cookie(self::COOKIE);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? array_values(array_unique(array_map('intval', $decoded))) : [];
    }

    public static function has(int $id): bool
    {
        return in_array($id, self::ids(), true);
    }

    public static function add(int $id): void
    {
        $ids = array_values(array_diff(self::ids(), [$id]));
        $ids[] = $id;

        self::store(array_slice($ids, -self::MAX));
    }

    public static function remove(int $id): void
    {
        self::store(array_values(array_diff(self::ids(), [$id])));
    }

    /**
     * Contas conhecidas que ainda existem e não estão suspensas (as mais recentes primeiro).
     *
     * @param  array<int, int>  $except
     * @return Collection<int, User>
     */
    public static function users(array $except = []): Collection
    {
        $ids = array_values(array_diff(array_reverse(self::ids()), $except));

        if ($ids === []) {
            return collect();
        }

        return User::whereIn('id', $ids)->whereNull('suspended_at')->get()
            ->sortBy(fn (User $u) => array_search($u->id, $ids, true))
            ->values();
    }

    /**
     * @param  array<int, int>  $ids
     */
    private static function store(array $ids): void
    {
        Cookie::queue(Cookie::make(self::COOKIE, json_encode($ids), 60 * 24 * 730, null, null, null, true, false, 'lax'));
    }
}
