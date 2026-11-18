<?php

namespace App\Models;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $account_id
 * @property int|null $card_id
 * @property TransactionType $type
 * @property string $description
 * @property int $amount Valor em centavos, sempre positivo.
 * @property Carbon $date
 * @property string|null $installment_group Liga as parcelas de uma mesma compra.
 * @property int|null $installment_number Parcela atual (1, 2, ...).
 * @property int|null $installment_total Total de parcelas da compra.
 * @property int|null $recurring_transaction_id Recorrência que gerou o lançamento.
 * @property TransactionCategory $category
 */
#[Fillable(['user_id', 'account_id', 'card_id', 'type', 'description', 'amount', 'date', 'category', 'installment_group', 'installment_number', 'installment_total', 'recurring_transaction_id'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'category' => TransactionCategory::class,
            'amount' => 'integer',
            'date' => 'immutable_date:Y-m-d',
            'installment_number' => 'integer',
            'installment_total' => 'integer',
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

    public function isRecurring(): bool
    {
        return $this->recurring_transaction_id !== null;
    }

    public function isInstallment(): bool
    {
        return $this->installment_group !== null;
    }

    public function isIncome(): bool
    {
        return $this->type === TransactionType::Income;
    }

    /**
     * Valor com sinal, em centavos (receita positiva, despesa negativa).
     */
    public function signedAmount(): int
    {
        return $this->isIncome() ? $this->amount : -$this->amount;
    }
}
