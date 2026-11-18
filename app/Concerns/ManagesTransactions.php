<?php

namespace App\Concerns;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Support\PlanLimits;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * Lógica compartilhada pelas telas de Contas e Cartões para mostrar e
 * gerenciar os lançamentos do item (conta ou cartão) que está na tela.
 */
trait ManagesTransactions
{
    use ParsesMoney;

    /** Mês exibido no formato "2026-11". Vazio = mês atual. */
    public string $month = '';

    public ?int $editingTransactionId = null;

    public ?int $deletingTransactionId = null;

    public string $kind = 'expense';

    public string $description = '';

    public string $amount = '0,00';

    public string $date = '';

    public string $category = '';

    /** Número de parcelas da compra (só cartão de crédito, só ao criar). */
    public int $installments = 1;

    /** Repetir o lançamento todo mês (só ao criar). */
    public bool $repeatMonthly = false;

    public ?int $cancellingRecurringId = null;

    public ?int $editingRecurringId = null;

    public string $recurringAmount = '0,00';

    /** Ao excluir uma parcela: "one", "following" (esta e as próximas) ou "all". */
    public string $deleteScope = 'one';

    /** Conta ou cartão atualmente em destaque (o "dono" dos lançamentos). */
    abstract protected function transactionOwner(): ?Model;

    /** Se a tela aceita receitas (cartões só aceitam despesas). */
    abstract protected function allowsIncome(): bool;

    /** Limpa os caches de computed da própria tela após mudar lançamentos. */
    abstract protected function forgetOwnerCaches(): void;

    /** Se a tela permite dividir a compra em parcelas (cartão de crédito). */
    protected function allowsInstallments(): bool
    {
        return false;
    }

    /** Mês exibido quando nenhum foi escolhido (cartão de crédito: fatura aberta). */
    protected function defaultMonthStart(): CarbonImmutable
    {
        return now()->toImmutable()->startOfMonth();
    }

    /**
     * Intervalo de datas dos lançamentos do mês exibido.
     *
     * @return array{0: string, 1: string}
     */
    protected function periodFor(CarbonImmutable $monthStart): array
    {
        return [$monthStart->toDateString(), $monthStart->endOfMonth()->toDateString()];
    }

    /** Mês ("Y-m") em que um lançamento da data informada é exibido. */
    protected function monthForDate(string $date): string
    {
        return substr($date, 0, 7);
    }

    protected function monthStart(): CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            return CarbonImmutable::parse($this->month.'-01')->startOfDay();
        }

        return $this->defaultMonthStart();
    }

    #[Computed]
    public function monthLabel(): string
    {
        return Str::ucfirst(
            $this->monthStart()->locale(app()->getLocale())->translatedFormat('F \d\e Y')
        );
    }

    /**
     * @return Collection<int, \App\Models\Transaction>
     */
    #[Computed]
    public function transactions(): Collection
    {
        $owner = $this->transactionOwner();

        if (! $owner) {
            return new Collection;
        }

        return $owner->transactions()
            ->whereBetween('date', $this->periodFor($this->monthStart()))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /** Lançamento que está para ser excluído (para saber se é uma parcela). */
    #[Computed]
    public function deletingTransaction(): ?Transaction
    {
        return $this->deletingTransactionId
            ? $this->transactionOwner()?->transactions()->find($this->deletingTransactionId)
            : null;
    }

    /**
     * Recorrências ativas do item em destaque.
     *
     * @return Collection<int, RecurringTransaction>
     */
    #[Computed]
    public function recurringTransactions(): Collection
    {
        $owner = $this->transactionOwner();

        if (! $owner) {
            return new Collection;
        }

        return $owner->recurringTransactions()
            ->whereNull('cancelled_at')
            ->orderBy('description')
            ->get();
    }

    #[Computed]
    public function cancellingRecurring(): ?RecurringTransaction
    {
        return $this->cancellingRecurringId
            ? $this->transactionOwner()?->recurringTransactions()->whereNull('cancelled_at')->find($this->cancellingRecurringId)
            : null;
    }

    #[Computed]
    public function editingRecurring(): ?RecurringTransaction
    {
        return $this->editingRecurringId
            ? $this->transactionOwner()?->recurringTransactions()->whereNull('cancelled_at')->find($this->editingRecurringId)
            : null;
    }

    /**
     * @return array{income: int, expense: int}
     */
    #[Computed]
    public function monthTotals(): array
    {
        return [
            'income' => (int) $this->transactions->filter->isIncome()->sum('amount'),
            'expense' => (int) $this->transactions->reject->isIncome()->sum('amount'),
        ];
    }

    /**
     * @return array<int, TransactionCategory>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return TransactionCategory::forType(TransactionType::tryFrom($this->kind) ?? TransactionType::Expense);
    }

    public function updatedKind(): void
    {
        $this->category = '';
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonthNoOverflow()->format('Y-m');
        $this->forgetTransactionCaches();
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonthNoOverflow()->format('Y-m');
        $this->forgetTransactionCaches();
    }

    /** Item bloqueado pelo plano (acima do limite): nada pode ser criado ou editado nele. */
    protected function blockedByPlan(): bool
    {
        if (! PlanLimits::isLocked(Auth::user(), $this->transactionOwner())) {
            return false;
        }

        Flux::toast(variant: 'danger', text: __('This item is locked by your plan.'));

        return true;
    }

    public function createTransaction(): void
    {
        abort_unless($this->transactionOwner(), 404);

        if ($this->blockedByPlan()) {
            return;
        }

        $this->resetValidation();
        $this->reset('editingTransactionId', 'description', 'category');
        $this->installments = 1;
        $this->repeatMonthly = false;
        $this->kind = 'expense';
        $this->amount = '0,00';
        $this->date = now()->toDateString();

        Flux::modal('transaction-form')->show();
    }

    public function editTransaction(int $id): void
    {
        $owner = $this->transactionOwner();
        abort_unless($owner, 404);

        if ($this->blockedByPlan()) {
            return;
        }

        $transaction = $owner->transactions()->findOrFail($id);

        if ($transaction->category === TransactionCategory::CardBill) {
            Flux::toast(variant: 'danger', text: __('This transaction was generated by a card invoice payment. Undo it on the Cards screen.'));

            return;
        }

        $this->resetValidation();
        $this->editingTransactionId = $transaction->id;
        $this->kind = $transaction->type->value;
        $this->description = $transaction->description;
        $this->amount = number_format($transaction->amount / 100, 2, ',', '.');
        $this->date = $transaction->date->toDateString();
        $this->category = $transaction->category->value;

        Flux::modal('transaction-form')->show();
    }

    public function saveTransaction(): void
    {
        $owner = $this->transactionOwner();
        abort_unless($owner, 404);

        if ($this->blockedByPlan()) {
            return;
        }

        if (! $this->allowsIncome()) {
            $this->kind = 'expense';
        }

        $this->description = trim($this->description);
        $this->amount = trim($this->amount);
        $type = TransactionType::tryFrom($this->kind) ?? TransactionType::Expense;

        $repeats = $this->repeatMonthly && ! $this->editingTransactionId;
        $usesInstallments = $this->allowsInstallments() && ! $this->editingTransactionId && ! $repeats;
        $count = $usesInstallments ? $this->installments : 1;

        if ($repeats && PlanLimits::reached(Auth::user(), 'recurring')) {
            Flux::toast(variant: 'danger', text: __('You reached the Free plan limit of :limit :items. Subscribe to Premium to add more.', [
                'limit' => PlanLimits::limit(Auth::user(), 'recurring'),
                'items' => __('active recurring transactions'),
            ]));

            return;
        }

        $validated = $this->validate([
            'installments' => $usesInstallments ? ['required', 'integer', 'between:1,'.PlanLimits::installmentsMax(Auth::user())] : ['nullable'],
            'kind' => ['required', Rule::enum(TransactionType::class)],
            'description' => ['required', 'string', 'max:150'],
            'amount' => [
                'bail',
                'required',
                'regex:'.$this->moneyPattern(allowNegative: false),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($this->parseAmountToCents((string) $value) <= 0) {
                        $fail(__('The :attribute must be greater than zero.', [
                            'attribute' => __('validation.attributes.amount'),
                        ]));
                    }
                },
            ],
            'date' => ['required', 'date_format:Y-m-d'],
            'category' => [
                'required',
                Rule::in(array_map(fn (TransactionCategory $c) => $c->value, TransactionCategory::forType($type))),
            ],
        ], [
            'amount.regex' => __('The :attribute must be a valid amount, e.g. 1.234,56.'),
        ]);

        $totalCents = $this->parseAmountToCents($validated['amount']);

        if ($count > 1 && $totalCents < $count) {
            $this->addError('amount', __('The amount is too small for this number of installments.'));

            return;
        }

        $data = [
            'user_id' => Auth::id(),
            'type' => $validated['kind'],
            'description' => $validated['description'],
            'amount' => $totalCents,
            'date' => $validated['date'],
            'category' => $validated['category'],
        ];

        if ($this->editingTransactionId) {
            $owner->transactions()->findOrFail($this->editingTransactionId)->update(Arr::except($data, 'user_id'));
            $message = __('Transaction updated.');
        } elseif ($repeats) {
            $recurring = $owner->recurringTransactions()->create([
                ...Arr::except($data, ['date']),
                'day_of_month' => (int) substr($data['date'], 8, 2),
                'next_due_on' => $data['date'],
            ]);
            $recurring->generateDue();

            $message = $recurring->next_due_on->isFuture()
                ? __('Recurring transaction created. The first one will be posted on :date.', ['date' => $recurring->next_due_on->format('d/m/Y')])
                : __('Recurring transaction created.');
        } elseif ($count > 1) {
            $this->createInstallments($owner, $data, $count);
            $message = __('Purchase split into :count installments.', ['count' => $count]);
        } else {
            $owner->transactions()->create($data);
            $message = __('Transaction created.');
        }

        // Mostra o mês do lançamento salvo para o usuário vê-lo na lista.
        $this->month = $this->monthForDate($validated['date']);
        $this->forgetTransactionCaches();

        Flux::modal('transaction-form')->close();
        Flux::toast(variant: 'success', text: $message);
    }

    public function confirmDeleteTransaction(int $id): void
    {
        $owner = $this->transactionOwner();
        abort_unless($owner, 404);

        $this->deletingTransactionId = $owner->transactions()->findOrFail($id)->id;
        $this->deleteScope = 'one';
        unset($this->deletingTransaction);

        Flux::modal('confirm-transaction-deletion')->show();
    }

    public function deleteTransaction(): void
    {
        $owner = $this->transactionOwner();
        abort_unless($owner, 404);

        $transaction = $owner->transactions()->findOrFail($this->deletingTransactionId);

        if ($transaction->isInstallment() && $this->deleteScope === 'all') {
            $owner->transactions()->where('installment_group', $transaction->installment_group)->delete();
        } elseif ($transaction->isInstallment() && $this->deleteScope === 'following') {
            $owner->transactions()
                ->where('installment_group', $transaction->installment_group)
                ->where('installment_number', '>=', $transaction->installment_number)
                ->delete();
        } else {
            $transaction->delete();
        }

        $this->deletingTransactionId = null;
        $this->deleteScope = 'one';
        $this->forgetTransactionCaches();

        Flux::modal('confirm-transaction-deletion')->close();
        Flux::toast(variant: 'success', text: __('Transaction deleted.'));
    }

    public function editRecurring(int $id): void
    {
        $owner = $this->transactionOwner();
        abort_unless($owner, 404);

        if ($this->blockedByPlan()) {
            return;
        }

        $recurring = $owner->recurringTransactions()->whereNull('cancelled_at')->findOrFail($id);

        $this->resetValidation();
        $this->editingRecurringId = $recurring->id;
        $this->recurringAmount = number_format($recurring->amount / 100, 2, ',', '.');
        unset($this->editingRecurring);

        Flux::modal('recurring-list')->close();
        Flux::modal('recurring-edit')->show();
    }

    /** Muda o valor das próximas ocorrências; o que já foi lançado não muda. */
    public function saveRecurring(): void
    {
        $owner = $this->transactionOwner();
        abort_unless($owner, 404);

        if ($this->blockedByPlan()) {
            return;
        }

        $recurring = $owner->recurringTransactions()->whereNull('cancelled_at')->findOrFail($this->editingRecurringId);

        $this->recurringAmount = trim($this->recurringAmount);

        $this->validate([
            'recurringAmount' => [
                'bail',
                'required',
                'regex:'.$this->moneyPattern(allowNegative: false),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($this->parseAmountToCents((string) $value) <= 0) {
                        $fail(__('The :attribute must be greater than zero.', [
                            'attribute' => __('validation.attributes.amount'),
                        ]));
                    }
                },
            ],
        ], [
            'recurringAmount.regex' => __('The :attribute must be a valid amount, e.g. 1.234,56.'),
        ]);

        // O que já venceu é lançado com o valor antigo antes da mudança.
        $recurring->generateDue();
        $recurring->update(['amount' => $this->parseAmountToCents($this->recurringAmount)]);

        $this->editingRecurringId = null;
        $this->forgetTransactionCaches();

        Flux::modal('recurring-edit')->close();
        Flux::toast(variant: 'success', text: __('Recurring transaction updated. The new amount applies to the next months.'));
    }

    public function confirmCancelRecurring(int $id): void
    {
        $owner = $this->transactionOwner();
        abort_unless($owner, 404);

        $this->cancellingRecurringId = $owner->recurringTransactions()->whereNull('cancelled_at')->findOrFail($id)->id;
        unset($this->cancellingRecurring);

        Flux::modal('recurring-list')->close();
        Flux::modal('confirm-recurring-cancel')->show();
    }

    public function cancelRecurring(): void
    {
        $owner = $this->transactionOwner();
        abort_unless($owner, 404);

        $owner->recurringTransactions()
            ->whereNull('cancelled_at')
            ->findOrFail($this->cancellingRecurringId)
            ->cancel();

        $this->cancellingRecurringId = null;
        $this->forgetTransactionCaches();

        Flux::modal('confirm-recurring-cancel')->close();
        Flux::toast(variant: 'success', text: __('Recurring transaction cancelled.'));
    }

    /**
     * Divide a compra em parcelas mensais. O resto da divisão (centavos) fica
     * na primeira parcela, para a soma bater com o valor total.
     *
     * @param  array<string, mixed>  $data
     */
    private function createInstallments(Model $owner, array $data, int $count): void
    {
        $base = intdiv($data['amount'], $count);
        $rest = $data['amount'] - $base * $count;
        $group = (string) Str::uuid();
        $first = CarbonImmutable::parse($data['date']);

        DB::transaction(function () use ($owner, $data, $count, $base, $rest, $group, $first): void {
            for ($i = 0; $i < $count; $i++) {
                $owner->transactions()->create([
                    ...$data,
                    'amount' => $base + ($i === 0 ? $rest : 0),
                    'date' => $first->addMonthsNoOverflow($i)->toDateString(),
                    'installment_group' => $group,
                    'installment_number' => $i + 1,
                    'installment_total' => $count,
                ]);
            }
        });
    }

    protected function forgetTransactionCaches(): void
    {
        unset($this->transactions, $this->monthTotals, $this->monthLabel, $this->deletingTransaction, $this->recurringTransactions, $this->cancellingRecurring, $this->editingRecurring);
        $this->forgetOwnerCaches();
    }
}
