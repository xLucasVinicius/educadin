<?php

use App\Enums\TransactionCategory;
use App\Models\Account;
use App\Models\Card;
use App\Models\InvoicePayment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

function creditCard(User $user, array $attrs = []): Card
{
    $account = Account::factory()->for($user)->create(['initial_balance' => 100000]);

    return Card::factory()->for($user)->credit()->create($attrs + [
        'account_id' => $account->id, 'credit_limit' => 500000, 'closing_day' => 10, 'due_day' => 20,
    ]);
}

test('invoice period goes from the day after the previous closing to the closing day', function () {
    $card = creditCard(User::factory()->create(), ['closing_day' => 10, 'due_day' => 20]);
    [$start, $end] = $card->invoicePeriod(CarbonImmutable::parse('2026-11-01'));

    expect($start->toDateString())->toBe('2026-10-11')
        ->and($end->toDateString())->toBe('2026-11-10')
        ->and($card->dueDateFor(CarbonImmutable::parse('2026-11-01'))->toDateString())->toBe('2026-11-20');
});

test('due date falls in the next month when due day is not after closing day', function () {
    $card = creditCard(User::factory()->create(), ['closing_day' => 25, 'due_day' => 5]);

    expect($card->dueDateFor(CarbonImmutable::parse('2026-11-01'))->toDateString())->toBe('2026-12-05');
});

test('closing day is clamped in short months', function () {
    $card = creditCard(User::factory()->create(), ['closing_day' => 31]);

    expect($card->closingDateFor(CarbonImmutable::parse('2026-02-01'))->toDateString())->toBe('2026-02-28');
});

test('purchase after closing day belongs to the next invoice', function () {
    $card = creditCard(User::factory()->create(), ['closing_day' => 10]);

    expect($card->invoiceMonthFor('2026-11-10'))->toBe('2026-11')
        ->and($card->invoiceMonthFor('2026-11-11'))->toBe('2026-12')
        ->and($card->invoiceMonthFor('2026-12-31'))->toBe('2027-01');
});

test('invoice lists only purchases inside its period', function () {
    $user = User::factory()->create();
    $card = creditCard($user, ['closing_day' => 10]);
    Transaction::factory()->forCard($card)->create(['description' => 'Dentro', 'amount' => 1000, 'date' => '2026-11-10']);
    Transaction::factory()->forCard($card)->create(['description' => 'Fora cedo', 'amount' => 2000, 'date' => '2026-10-10']);
    Transaction::factory()->forCard($card)->create(['description' => 'Fora tarde', 'amount' => 4000, 'date' => '2026-11-11']);
    $this->actingAs($user);

    $component = Livewire::test('pages::cards.index')->set('month', '2026-11');

    $component->assertSee('Dentro')->assertDontSee('Fora cedo')->assertDontSee('Fora tarde');
    expect($component->instance()->invoice['total'])->toBe(1000);
});

test('saving a purchase moves the view to the invoice it belongs to', function () {
    $user = User::factory()->create();
    creditCard($user, ['closing_day' => 10]);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->call('createTransaction')
        ->set('description', 'Compra')->set('amount', '10,00')
        ->set('date', '2026-11-15')->set('category', 'food')
        ->call('saveTransaction')
        ->assertHasNoErrors()
        ->assertSet('month', '2026-12');
});

test('paying an invoice creates an expense on the linked account and a payment', function () {
    $user = User::factory()->create();
    $card = creditCard($user);
    Transaction::factory()->forCard($card)->create(['amount' => 30000, 'date' => '2026-11-05']);
    Transaction::factory()->forCard($card)->create(['amount' => 20000, 'date' => '2026-11-08']);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->set('month', '2026-11')
        ->call('confirmPayInvoice')
        ->call('payInvoice')
        ->assertHasNoErrors();

    $payment = InvoicePayment::firstOrFail();
    $transaction = Transaction::findOrFail($payment->transaction_id);

    expect($payment->amount)->toBe(50000)
        ->and($payment->month)->toBe('2026-11')
        ->and($transaction->category)->toBe(TransactionCategory::CardBill)
        ->and($transaction->account_id)->toBe($card->account_id)
        ->and($transaction->card_id)->toBeNull()
        ->and($transaction->amount)->toBe(50000)
        ->and($card->account()->withBalance()->first()->currentBalance())->toBe(50000);
});

test('an invoice cannot be paid twice', function () {
    $user = User::factory()->create();
    $card = creditCard($user);
    Transaction::factory()->forCard($card)->create(['amount' => 30000, 'date' => '2026-11-05']);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')->set('month', '2026-11')->call('payInvoice')->call('payInvoice');

    expect(InvoicePayment::count())->toBe(1)
        ->and(Transaction::whereNull('card_id')->count())->toBe(1);
});

test('an empty invoice cannot be paid', function () {
    $user = User::factory()->create();
    creditCard($user);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')->set('month', '2026-11')->call('payInvoice');

    expect(InvoicePayment::count())->toBe(0);
});

test('undoing a payment removes the payment and the account expense', function () {
    $user = User::factory()->create();
    $card = creditCard($user);
    Transaction::factory()->forCard($card)->create(['amount' => 30000, 'date' => '2026-11-05']);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')->set('month', '2026-11')->call('payInvoice');
    Livewire::test('pages::cards.index')->set('month', '2026-11')->call('undoInvoicePayment', InvoicePayment::firstOrFail()->id);

    expect(InvoicePayment::count())->toBe(0)
        ->and(Transaction::whereNull('card_id')->count())->toBe(0);
});

test('deleting the account expense also undoes the payment', function () {
    $user = User::factory()->create();
    $card = creditCard($user);
    Transaction::factory()->forCard($card)->create(['amount' => 30000, 'date' => '2026-11-05']);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')->set('month', '2026-11')->call('payInvoice');
    Transaction::whereNull('card_id')->delete();

    expect(InvoicePayment::count())->toBe(0);
});

test('used credit ignores paid invoices', function () {
    $user = User::factory()->create();
    $card = creditCard($user);
    Transaction::factory()->forCard($card)->create(['amount' => 30000, 'date' => '2026-10-05']);
    Transaction::factory()->forCard($card)->create(['amount' => 20000, 'date' => '2026-11-05']);
    $this->actingAs($user);

    $component = Livewire::test('pages::cards.index')->set('month', '2026-10')->call('payInvoice');

    expect($component->instance()->usedCredit)->toBe(20000);
    $component->assertSee(Money::format(480000), false);
});

test('the bill payment transaction cannot be edited from the account screen', function () {
    $user = User::factory()->create();
    $card = creditCard($user);
    Transaction::factory()->forCard($card)->create(['amount' => 30000, 'date' => '2026-11-05']);
    $this->actingAs($user);
    Livewire::test('pages::cards.index')->set('month', '2026-11')->call('payInvoice');
    $bill = Transaction::whereNull('card_id')->firstOrFail();

    Livewire::test('pages::accounts.index')
        ->call('editTransaction', $bill->id)
        ->assertSet('editingTransactionId', null);
});

test('only the owner pays invoices of the active card', function () {
    $user = User::factory()->create();
    $foreign = creditCard(User::factory()->create());
    Transaction::factory()->forCard($foreign)->create(['amount' => 30000, 'date' => '2026-11-05']);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')->call('select', $foreign->id)->assertNotFound();

    expect(InvoicePayment::count())->toBe(0);
});

test('bill payments are not counted twice in the dashboard categories', function () {
    $user = User::factory()->create();
    $card = creditCard($user);
    $month = now()->startOfMonth();
    Transaction::factory()->forCard($card)->create(['amount' => 30000, 'date' => $month->addDays(1)->toDateString(), 'description' => 'Compra']);
    $this->actingAs($user);

    $component = Livewire::test('pages::dashboard');
    $before = $component->instance()->expensesByCategory;

    Transaction::factory()->forAccount($card->account)->create([
        'category' => TransactionCategory::CardBill, 'amount' => 30000, 'date' => $month->addDays(2)->toDateString(),
    ]);

    $after = Livewire::test('pages::dashboard')->instance()->expensesByCategory;

    expect($after)->toBe($before);
});

test('a purchase made after paying reopens the invoice with only the remaining amount', function () {
    $user = User::factory()->create();
    $card = creditCard($user);
    Transaction::factory()->forCard($card)->create(['amount' => 30000, 'date' => '2026-11-05']);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')->set('month', '2026-11')->call('payInvoice');

    Transaction::factory()->forCard($card)->create(['amount' => 12000, 'date' => '2026-11-06']);

    $component = Livewire::test('pages::cards.index')->set('month', '2026-11');
    $invoice = $component->instance()->invoice;

    expect($invoice['total'])->toBe(42000)
        ->and($invoice['paid'])->toBe(30000)
        ->and($invoice['remaining'])->toBe(12000)
        ->and($invoice['status'])->not->toBe('paid');

    $component->call('payInvoice');

    expect(InvoicePayment::count())->toBe(2)
        ->and(InvoicePayment::latest('id')->first()->amount)->toBe(12000)
        ->and($component->instance()->invoice['status'])->toBe('paid')
        ->and($component->instance()->usedCredit)->toBe(0);
});

test('undoing removes only the chosen payment', function () {
    $user = User::factory()->create();
    $card = creditCard($user);
    Transaction::factory()->forCard($card)->create(['amount' => 30000, 'date' => '2026-11-05']);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')->set('month', '2026-11')->call('payInvoice');
    Transaction::factory()->forCard($card)->create(['amount' => 12000, 'date' => '2026-11-06']);

    Livewire::test('pages::cards.index')->set('month', '2026-11')->call('payInvoice');
    Livewire::test('pages::cards.index')->set('month', '2026-11')->call('undoInvoicePayment', InvoicePayment::latest('id')->firstOrFail()->id);

    expect(InvoicePayment::count())->toBe(1)
        ->and(InvoicePayment::first()->amount)->toBe(30000);
});

test('the invoice value is what is still owed and the payment shows in the transactions list', function () {
    $user = User::factory()->create();
    $card = creditCard($user);
    Transaction::factory()->forCard($card)->create(['description' => 'Compra A', 'amount' => 30000, 'date' => '2026-11-05']);
    $this->actingAs($user);

    $component = Livewire::test('pages::cards.index')->set('month', '2026-11')->call('payInvoice');

    expect($component->instance()->invoice['remaining'])->toBe(0);
    $component->assertSee('Compra A')->assertSee('Pagamento de fatura');

    Transaction::factory()->forCard($card)->create(['amount' => 12000, 'date' => '2026-11-06']);

    expect(Livewire::test('pages::cards.index')->set('month', '2026-11')->instance()->invoice['remaining'])->toBe(12000);
});

test('payments of other users are never shown', function () {
    $user = User::factory()->create();
    creditCard($user);
    $foreign = creditCard(User::factory()->create());
    Transaction::factory()->forCard($foreign)->create(['amount' => 30000, 'date' => '2026-11-05']);
    $foreignPayment = InvoicePayment::create([
        'user_id' => $foreign->user_id, 'card_id' => $foreign->id, 'account_id' => $foreign->account_id,
        'transaction_id' => Transaction::factory()->forAccount($foreign->account)->create()->id,
        'month' => '2026-11', 'amount' => 30000, 'paid_at' => '2026-11-06',
    ]);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')->call('undoInvoicePayment', $foreignPayment->id)->assertNotFound();
});
