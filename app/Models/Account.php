<?php

namespace App\Models;

use App\Enums\AccountColor;
use App\Enums\AccountType;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property AccountType $type
 * @property AccountColor $color
 * @property int $initial_balance Valor em centavos.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'color', 'initial_balance'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'color' => AccountColor::class,
            'initial_balance' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Carrega os totais de receitas e despesas junto com as contas,
     * para calcular o saldo atual sem uma consulta por conta.
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeWithBalance(Builder $query): Builder
    {
        return $query
            ->withSum(['transactions as income_total' => fn ($q) => $q->where('type', 'income')], 'amount')
            ->withSum(['transactions as expense_total' => fn ($q) => $q->where('type', 'expense')], 'amount');
    }

    /**
     * Saldo atual em centavos: saldo inicial + receitas - despesas.
     */
    public function currentBalance(): int
    {
        if (array_key_exists('income_total', $this->attributes)) {
            $income = (int) $this->income_total;
            $expense = (int) $this->expense_total;
        } else {
            $income = (int) $this->transactions()->where('type', 'income')->sum('amount');
            $expense = (int) $this->transactions()->where('type', 'expense')->sum('amount');
        }

        return $this->initial_balance + $income - $expense;
    }
}
