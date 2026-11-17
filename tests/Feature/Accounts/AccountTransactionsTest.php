<?php

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Card;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

function monthDay(int $offset = 0, int $months = 0): string
{
    return now()->startOfMonth()->subMonthsNoOverflow($months)->addDays($offset)->toDateString();
}

test('income and expense can be created with amounts converted to cents', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $this->actingAs($user);

    $component = Livewire::test('pages::accounts.index')
        ->call('createTransaction')
        ->set('kind', 'income')
        ->set('description', 'Salário')
        ->set('amount', '1.234,56')
        ->set('date', monthDay(1))
        ->set('category', 'salary')
        ->call('saveTransaction')
        ->assertHasNoErrors();

    $component
        ->call('createTransaction')
        ->set('kind', 'expense')
        ->set('description', 'Mercado')
        ->set('amount', '50')
        ->set('date', monthDay(2))
        ->set('category', 'food')
        ->call('saveTransaction')
        ->assertHasNoErrors();

    $income = Transaction::where('description', 'Salário')->firstOrFail();
    $expense = Transaction::where('description', 'Mercado')->firstOrFail();

    expect($income->amount)->toBe(123456)
        ->and($income->type)->toBe(TransactionType::Income)
        ->and($income->account_id)->toBe($account->id)
        ->and($income->card_id)->toBeNull()
        ->and($income->user_id)->toBe($user->id)
        ->and($expense->amount)->toBe(5000)
        ->and($expense->type)->toBe(TransactionType::Expense);
});

test('current balance is initial balance plus income minus expenses', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['initial_balance' => 100000]);
    Transaction::factory()->forAccount($account)->income()->create(['amount' => 50000, 'date' => monthDay(1)]);
    Transaction::factory()->forAccount($account)->create(['amount' => 20000, 'date' => monthDay(2)]);

    expect($account->currentBalance())->toBe(130000);

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertOk()
        ->assertSee(Money::format(130000), false);
});

test('card transactions do not appear on the account page nor change its balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['initial_balance' => 100000]);
    $card = Card::factory()->for($user)->create(['account_id' => $account->id]);
    Transaction::factory()->forCard($card)->create(['description' => 'Compra no cartao', 'amount' => 9999, 'date' => monthDay(1)]);

    $this->actingAs($user);

    Livewire::test('pages::accounts.index')->assertDontSee('Compra no cartao');

    expect($account->fresh()->currentBalance())->toBe(100000);
});

test('transaction validation rules are enforced', function (array $data, string $field) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->set('kind', $data['kind'] ?? 'expense')
        ->set('description', $data['description'] ?? 'Teste')
        ->set('amount', $data['amount'] ?? '10,00')
        ->set('date', $data['date'] ?? monthDay(1))
        ->set('category', $data['category'] ?? 'food')
        ->call('saveTransaction')
        ->assertHasErrors([$field]);
})->with([
    'description required' => [['description' => ''], 'description'],
    'amount zero' => [['amount' => '0,00'], 'amount'],
    'amount invalid' => [['amount' => 'abc'], 'amount'],
    'amount negative' => [['amount' => '-5,00'], 'amount'],
    'date invalid' => [['date' => 'ontem'], 'date'],
    'category required' => [['category' => ''], 'category'],
    'income category on expense' => [['kind' => 'expense', 'category' => 'salary'], 'category'],
    'expense category on income' => [['kind' => 'income', 'category' => 'food'], 'category'],
    'kind invalid' => [['kind' => 'xyz'], 'kind'],
]);

test('only the selected month is listed and months can be navigated', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->forAccount($account)->create(['description' => 'Compra deste mes', 'date' => monthDay(1)]);
    Transaction::factory()->forAccount($account)->create(['description' => 'Compra antiga', 'date' => monthDay(1, 1)]);
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->assertSee('Compra deste mes')
        ->assertDontSee('Compra antiga')
        ->call('previousMonth')
        ->assertSee('Compra antiga')
        ->assertDontSee('Compra deste mes')
        ->call('nextMonth')
        ->assertSee('Compra deste mes');
});

test('month totals only count the selected month', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->forAccount($account)->income()->create(['amount' => 30000, 'date' => monthDay(1)]);
    Transaction::factory()->forAccount($account)->create(['amount' => 10000, 'date' => monthDay(2)]);
    Transaction::factory()->forAccount($account)->create(['amount' => 99999, 'date' => monthDay(1, 1)]);
    $this->actingAs($user);

    $component = Livewire::test('pages::accounts.index');

    expect($component->instance()->monthTotals)->toBe(['income' => 30000, 'expense' => 10000]);
});

test('only the active account transactions are listed', function () {
    $user = User::factory()->create();
    $a = Account::factory()->for($user)->create(['name' => 'A Conta']);
    $b = Account::factory()->for($user)->create(['name' => 'B Conta']);
    Transaction::factory()->forAccount($a)->create(['description' => 'Lancamento da A', 'date' => monthDay(1)]);
    Transaction::factory()->forAccount($b)->create(['description' => 'Lancamento da B', 'date' => monthDay(1)]);
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->assertSee('Lancamento da A')
        ->assertDontSee('Lancamento da B')
        ->call('selectNext')
        ->assertSee('Lancamento da B')
        ->assertDontSee('Lancamento da A')
        ->call('selectPrevious')
        ->assertSee('Lancamento da A');
});

test('select only accepts own accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $foreign = Account::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')->call('select', $foreign->id)->assertNotFound();
});

test('own transaction can be edited', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->forAccount($account)->create([
        'description' => 'Velho', 'amount' => 1000, 'date' => monthDay(1),
    ]);
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('editTransaction', $transaction->id)
        ->assertSet('description', 'Velho')
        ->assertSet('amount', '10,00')
        ->assertSet('kind', 'expense')
        ->set('description', 'Novo')
        ->set('amount', '25,50')
        ->call('saveTransaction')
        ->assertHasNoErrors();

    expect($transaction->refresh()->description)->toBe('Novo')
        ->and($transaction->amount)->toBe(2550);
});

test('foreign transactions cannot be edited or deleted', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $foreign = Transaction::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')->call('editTransaction', $foreign->id)->assertNotFound();
    Livewire::test('pages::accounts.index')->call('confirmDeleteTransaction', $foreign->id)->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
});

test('transactions of another account of the same user are not reachable from the active one', function () {
    $user = User::factory()->create();
    $a = Account::factory()->for($user)->create(['name' => 'A Conta']);
    $b = Account::factory()->for($user)->create(['name' => 'B Conta']);
    $other = Transaction::factory()->forAccount($b)->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')->call('editTransaction', $other->id)->assertNotFound();
});

test('own transaction can be deleted', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->forAccount($account)->create(['date' => monthDay(1)]);
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('confirmDeleteTransaction', $transaction->id)
        ->call('deleteTransaction')
        ->assertHasNoErrors();

    expect($transaction->fresh())->toBeNull();
});

test('deleting an account also deletes its transactions and warns how many', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->forAccount($account)->count(2)->create(['date' => monthDay(1)]);
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('confirmDelete', $account->id)
        ->assertSee('2 lançamentos desta conta também serão excluídos.')
        ->call('delete');

    expect(Account::find($account->id))->toBeNull()
        ->and(Transaction::count())->toBe(0);
});

test('account color must be valid', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::accounts.index')
        ->set('name', 'Conta')->set('type', 'checking')->set('color', 'pink-neon')->set('initialBalance', '0,00')
        ->call('save')
        ->assertHasErrors(['color']);
});
