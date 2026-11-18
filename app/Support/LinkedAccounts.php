<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Contas que já entraram neste navegador (na sessão atual). Permitem trocar de
 * perfil sem digitar a senha de novo; sair de todas apaga a sessão e a lista.
 */
class LinkedAccounts
{
    public const KEY = 'linked_accounts';

    /** Quantas contas podem ficar ligadas ao mesmo tempo. */
    public const MAX = 5;

    /**
     * @return array<int, int>
     */
    public static function ids(): array
    {
        return array_values(array_unique(array_map('intval', (array) session(self::KEY, []))));
    }

    public static function has(int $id): bool
    {
        return in_array($id, self::ids(), true);
    }

    public static function add(int $id): void
    {
        $ids = array_values(array_diff(self::ids(), [$id]));
        $ids[] = $id;

        // Passou do limite: a conta mais antiga sai da lista.
        session([self::KEY => array_slice($ids, -self::MAX)]);
    }

    public static function remove(int $id): void
    {
        session([self::KEY => array_values(array_diff(self::ids(), [$id]))]);
    }

    /**
     * Contas ligadas que ainda podem ser usadas (existem e não estão suspensas).
     *
     * @return Collection<int, User>
     */
    public static function users(?int $except = null): Collection
    {
        $ids = array_values(array_diff(self::ids(), array_filter([$except])));

        if ($ids === []) {
            return collect();
        }

        return User::whereIn('id', $ids)->whereNull('suspended_at')->get()
            ->sortBy(fn (User $u) => array_search($u->id, $ids, true))
            ->values();
    }
}
