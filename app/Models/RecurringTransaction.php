<?php

namespace App\Models;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use Carbon\CarbonImmutable;
use Database\Factories\RecurringTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Lançamento que se repete todo mês (aluguel, assinaturas...). As ocorrências
 * só são criadas quando o dia chega; meses futuros ficam vazios.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $account_id
 * @property int|null $card_id
 * @property TransactionType $type
 * @property string $description
 * @property int $amount Valor em centavos, sempre positivo.
 * @property TransactionCategory $category
 * @property int $day_of_month
 * @property CarbonImmutable $next_due_on Próxima ocorrência ainda não lançada.
 * @property CarbonImmutable|null $cancelled_at
 */
class RecurringTransaction extends Model
{
    /** @use HasFactory<RecurringTransactionFactory> */
    use HasFactory;

    /** Limite de segurança de ocorrências lançadas de uma vez. */
    private const MAX_CATCH_UP = 60;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'category' => TransactionCategory::class,
            'amount' => 'integer',
            'day_of_month' => 'integer',
            'next_due_on' => 'immutable_date:Y-m-d',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function isActive(): bool
    {
        return $this->cancelled_at === null;
    }

    /** Data da ocorrência no mês informado (dia 31 vira 28/29/30 em meses curtos). */
    public function occurrenceIn(CarbonImmutable $month): CarbonImmutable
    {
        $first = $month->startOfMonth();

        return $first->day(min($this->day_of_month, $first->daysInMonth));
    }

    /**
     * Cria os lançamentos cujo dia já chegou e ainda não foram criados.
     *
     * @return int Quantidade de lançamentos criados.
     */
    public function generateDue(?CarbonImmutable $today = null): int
    {
        if (! $this->isActive()) {
            return 0;
        }

        $today ??= now()->toImmutable()->startOfDay();
        $next = $this->next_due_on;
        $created = 0;

        while ($next->lessThanOrEqualTo($today) && $created < self::MAX_CATCH_UP) {
            try {
                $transaction = $this->transactions()->firstOrCreate(
                    ['date' => $next->toDateString()],
                    [
                        'user_id' => $this->user_id,
                        'account_id' => $this->account_id,
                        'card_id' => $this->card_id,
                        'type' => $this->type,
                        'description' => $this->description,
                        'amount' => $this->amount,
                        'category' => $this->category,
                    ],
                );

                $created += $transaction->wasRecentlyCreated ? 1 : 0;
            } catch (UniqueConstraintViolationException) {
                // Outra requisição lançou esta ocorrência ao mesmo tempo.
            }

            $next = $this->occurrenceIn($next->startOfMonth()->addMonthNoOverflow());
        }

        if (! $next->equalTo($this->next_due_on)) {
            $this->update(['next_due_on' => $next->toDateString()]);
        }

        return $created;
    }

    /**
     * Cancela a recorrência: lança o que já venceu e para de criar novos meses.
     * O histórico já lançado é mantido.
     */
    public function cancel(): void
    {
        $this->generateDue();

        $this->update(['cancelled_at' => now()]);
    }

    /** Lança, para o usuário, tudo o que já venceu em suas recorrências ativas. */
    public static function generateDueFor(int $userId, ?CarbonImmutable $today = null): void
    {
        $today ??= now()->toImmutable()->startOfDay();

        static::query()
            ->where('user_id', $userId)
            ->whereNull('cancelled_at')
            ->where('next_due_on', '<=', $today->toDateString())
            ->get()
            ->each(fn (self $recurring) => $recurring->generateDue($today));
    }
}
