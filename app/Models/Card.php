<?php

namespace App\Models;

use App\Enums\CardType;
use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property string $name
 * @property CardType $type
 * @property int|null $credit_limit Valor em centavos (somente crédito).
 * @property int|null $closing_day
 * @property int|null $due_day
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'name', 'type', 'credit_limit', 'closing_day', 'due_day'])]
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CardType::class,
            'credit_limit' => 'integer',
            'closing_day' => 'integer',
            'due_day' => 'integer',
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

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function recurringTransactions(): HasMany
    {
        return $this->hasMany(RecurringTransaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    /**
     * Data de fechamento da fatura do mês informado (ajustada a meses curtos).
     */
    public function closingDateFor(CarbonImmutable $month): CarbonImmutable
    {
        $first = $month->startOfMonth();

        return $first->day(min((int) $this->closing_day, $first->daysInMonth));
    }

    /**
     * Período de compras da fatura que fecha no mês informado: do dia seguinte
     * ao fechamento anterior até o fechamento deste mês.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function invoicePeriod(CarbonImmutable $month): array
    {
        $previous = $this->closingDateFor($month->startOfMonth()->subMonthNoOverflow());

        return [$previous->addDay(), $this->closingDateFor($month)];
    }

    /**
     * Data de vencimento da fatura que fecha no mês informado.
     */
    public function dueDateFor(CarbonImmutable $month): CarbonImmutable
    {
        $base = $month->startOfMonth();

        if ((int) $this->due_day <= (int) $this->closing_day) {
            $base = $base->addMonthNoOverflow();
        }

        return $base->day(min((int) $this->due_day, $base->daysInMonth));
    }

    /**
     * Mês ("Y-m") da fatura em que uma compra feita na data cai.
     */
    public function invoiceMonthFor(string $date): string
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        $month = $day->greaterThan($this->closingDateFor($day)) ? $day->startOfMonth()->addMonthNoOverflow() : $day->startOfMonth();

        return $month->format('Y-m');
    }

    public function isCredit(): bool
    {
        return $this->type === CardType::Credit;
    }
}
