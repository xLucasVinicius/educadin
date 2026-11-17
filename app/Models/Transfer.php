<?php

namespace App\Models;

use Database\Factories\TransferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Transferência entre duas contas do mesmo usuário. Não é receita nem
 * despesa: só move saldo de uma conta para a outra.
 *
 * @property int $id
 * @property int $user_id
 * @property int $from_account_id
 * @property int $to_account_id
 * @property string|null $description
 * @property int $amount Valor em centavos, sempre positivo.
 * @property Carbon $date
 */
#[Fillable(['user_id', 'from_account_id', 'to_account_id', 'description', 'amount', 'date'])]
class Transfer extends Model
{
    /** @use HasFactory<TransferFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'date' => 'immutable_date:Y-m-d',
        ];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }
}
