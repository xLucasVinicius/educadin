<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Card;
use App\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Limites de cada plano (definidos em config/educadin.php, "plans.*.limits").
 * Um limite nulo significa sem limite.
 */
class PlanLimits
{
    /** Chave do plano que vale hoje para o usuário (Premium vencido volta ao Gratuito). */
    public static function planKey(User $user): string
    {
        return $user->isPremium() ? 'premium' : 'free';
    }

    public static function limit(User $user, string $key): int|bool|null
    {
        return config('educadin.plans.'.self::planKey($user).'.limits.'.$key);
    }

    /** Quantos itens o usuário tem hoje (contas, cartões ou recorrentes ativos). */
    public static function count(User $user, string $key): int
    {
        return match ($key) {
            'accounts' => $user->accounts()->count(),
            'cards' => $user->cards()->count(),
            'recurring' => RecurringTransaction::where('user_id', $user->id)->whereNull('cancelled_at')->count(),
        };
    }

    /** Já atingiu o limite do plano e não pode criar mais um? */
    public static function reached(User $user, string $key): bool
    {
        $limit = self::limit($user, $key);

        return $limit !== null && self::count($user, $key) >= $limit;
    }

    /** Máximo de parcelas permitido no plano. */
    public static function installmentsMax(User $user): int
    {
        return (int) (self::limit($user, 'installments') ?? 24);
    }

    /** O usuário pode ver o histórico completo no Desempenho? */
    public static function hasFullHistory(User $user): bool
    {
        return (bool) self::limit($user, 'history');
    }

    /**
     * Ids dos itens que passam do limite do plano (os mais antigos continuam livres).
     * Eles ficam somente leitura.
     *
     * @return array<int, int>
     */
    public static function lockedIds(User $user, string $key): array
    {
        $limit = self::limit($user, $key);

        if ($limit === null) {
            return [];
        }

        $query = $key === 'cards' ? $user->cards() : $user->accounts();

        return $query->orderBy('id')->pluck('id')->slice($limit)->values()->all();
    }

    public static function isLocked(User $user, ?Model $item): bool
    {
        if (! $item instanceof Account && ! $item instanceof Card) {
            return false;
        }

        return in_array($item->getKey(), self::lockedIds($user, $item instanceof Card ? 'cards' : 'accounts'), true);
    }
}
