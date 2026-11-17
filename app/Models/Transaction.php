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
 * @property TransactionCategory $category
 */
#[Fillable(['user_id', 'account_id', 'card_id', 'type', 'description', 'amount', 'date', 'category'])]
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
