<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $card_id
 * @property int $account_id
 * @property int $transaction_id
 * @property string $month Mês de fechamento da fatura, "Y-m".
 * @property int $amount Valor pago, em centavos.
 */
#[Fillable(['user_id', 'card_id', 'account_id', 'transaction_id', 'month', 'amount', 'paid_at'])]
class InvoicePayment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'immutable_date:Y-m-d',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
