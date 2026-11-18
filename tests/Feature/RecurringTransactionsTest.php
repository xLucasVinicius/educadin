<?php

use App\Models\Account;
use App\Models\Card;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

function recurringDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date)->startOfDay();
}

function recurringFor(User $user, string $startsOn, array $attrs = []): RecurringTransaction
{
    $account = Account::factory()->for($user)->create();

    return RecurringTransaction::factory()->forAccount($account)->startingOn($startsOn)->create($attrs);
}

test('occurrences are posted only when the day arrives', function () {
    $user = User::factory()->create();
    $recurring = recurringFor($user, '2026-09-10');

    $created = $recurring->generateDue(recurringDay('2026-11-17'));

    expect($created)->toBe(3)
        ->and($recurring->transactions()->orderBy('date')->get()->map(fn ($t) => $t->date->toDateString())->all())
        ->toBe(['2026-09-10', '2026-10-10', '2026-11-10'])
        ->and($recurring->fresh()->next_due_on->toDateString())->toBe('2026-12-10');
});

test('future months stay empty', function () {
    $user = User::factory()->create();
    $recurring = recurringFor($user, '2026-11-25');

    expect($recurring->generateDue(recurringDay('2026-11-17')))->toBe(0)
        ->and(Transaction::count())->toBe(0)
        ->and($recurring->generateDue(recurringDay('2026-11-25')))->toBe(1);
});

test('generating twice does not duplicate occurrences', function () {
    $user = User::factory()->create();
    $recurring = recurringFor($user, '2026-11-01');

    $recurring->generateDue(recurringDay('2026-11-17'));
    $recurring->fresh()->generateDue(recurringDay('2026-11-17'));

    expect(Transaction::count())->toBe(1);
});

test('a day that does not exist in a short month uses its last day', function () {
    $user = User::factory()->create();
    $recurring = recurringFor($user, '2026-01-31');

    $recurring->generateDue(recurringDay('2026-03-31'));

    expect($recurring->transactions()->orderBy('date')->get()->map(fn ($t) => $t->date->toDateString())->all())
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

test('generated transactions copy the recurrence and belong to its owner', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $card = Card::factory()->for($user)->credit()->create(['account_id' => $account->id, 'closing_day' => 25, 'due_day' => 5]);
    $recurring = RecurringTransaction::factory()->forCard($card)->startingOn('2026-11-02')->create(['amount' => 3990, 'description' => 'Streaming']);

    $recurring->generateDue(recurringDay('2026-11-17'));

    $transaction = Transaction::sole();
    expect($transaction->card_id)->toBe($card->id)
        ->and($transaction->account_id)->toBeNull()
        ->and($transaction->user_id)->toBe($user->id)
        ->and($transaction->amount)->toBe(3990)
        ->and($transaction->description)->toBe('Streaming')
        ->and($transaction->recurring_transaction_id)->toBe($recurring->id)
        ->and($transaction->isRecurring())->toBeTrue();
});

test('cancelling posts what is already due and stops future months', function () {
    $user = User::factory()->create();
    $recurring = recurringFor($user, '2026-10-10');

    $this->travelTo(recurringDay('2026-11-17'));
    $recurring->cancel();

    expect($recurring->fresh()->isActive())->toBeFalse()
        ->and(Transaction::count())->toBe(2);

    $this->travelTo(recurringDay('2027-03-01'));
    $recurring->fresh()->generateDue();
    RecurringTransaction::generateDueFor($user->id);

    expect(Transaction::count())->toBe(2);
});

test('deleting a recurrence keeps the transactions already posted', function () {
    $user = User::factory()->create();
    $recurring = recurringFor($user, '2026-11-01');
    $recurring->generateDue(recurringDay('2026-11-17'));

    $recurring->delete();

    expect(Transaction::count())->toBe(1)
        ->and(Transaction::sole()->recurring_transaction_id)->toBeNull();
});

test('opening a screen posts the due recurrences of the user', function () {
    $user = User::factory()->create();
    $recurring = recurringFor($user, now()->startOfMonth()->subMonth()->toDateString());
    $other = recurringFor(User::factory()->create(), now()->startOfMonth()->subMonth()->toDateString());

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($recurring->transactions()->count())->toBeGreaterThanOrEqual(1)
        ->and($other->transactions()->count())->toBe(0);
});

test('a recurring transaction can be created from the form', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('createTransaction')
        ->set('description', 'Aluguel')
        ->set('amount', '1.500,00')
        ->set('date', now()->subDays(3)->toDateString())
        ->set('category', 'housing')
        ->set('repeatMonthly', true)
        ->call('saveTransaction')
        ->assertHasNoErrors();

    $recurring = RecurringTransaction::sole();
    expect($recurring->account_id)->toBe($account->id)
        ->and($recurring->amount)->toBe(150000)
        ->and($recurring->day_of_month)->toBe(now()->subDays(3)->day)
        ->and($recurring->transactions()->count())->toBe(1);
});

test('a recurrence starting in the future posts nothing yet', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('createTransaction')
        ->set('description', 'Academia')
        ->set('amount', '99,90')
        ->set('date', now()->addDays(5)->toDateString())
        ->set('category', 'health')
        ->set('repeatMonthly', true)
        ->call('saveTransaction')
        ->assertHasNoErrors();

    expect(RecurringTransaction::count())->toBe(1)
        ->and(Transaction::count())->toBe(0);
});

test('repeating a credit card purchase ignores installments', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Card::factory()->for($user)->credit()->create(['account_id' => $account->id, 'closing_day' => 28, 'due_day' => 10]);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->call('createTransaction')
        ->set('description', 'Streaming')
        ->set('amount', '39,90')
        ->set('date', now()->toDateString())
        ->set('category', 'leisure')
        ->set('installments', 6)
        ->set('repeatMonthly', true)
        ->call('saveTransaction')
        ->assertHasNoErrors();

    expect(Transaction::count())->toBe(1)
        ->and(Transaction::sole()->isInstallment())->toBeFalse()
        ->and(RecurringTransaction::count())->toBe(1);
});

test('a recurrence can be cancelled from the screen', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $recurring = RecurringTransaction::factory()->forAccount($account)->startingOn(now()->subDays(2)->toDateString())->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('confirmCancelRecurring', $recurring->id)
        ->assertSet('cancellingRecurringId', $recurring->id)
        ->call('cancelRecurring')
        ->assertSet('cancellingRecurringId', null);

    expect($recurring->fresh()->isActive())->toBeFalse();
});

test('a user cannot cancel the recurrence of someone else', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $foreign = recurringFor(User::factory()->create(), now()->toDateString());
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')->call('confirmCancelRecurring', $foreign->id)->assertNotFound();

    expect($foreign->fresh()->isActive())->toBeTrue();
});

test('editing the amount affects only the next months', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $recurring = RecurringTransaction::factory()->forAccount($account)->startingOn(now()->subDays(2)->toDateString())->create(['amount' => 10000]);
    $recurring->generateDue();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('editRecurring', $recurring->id)
        ->assertSet('editingRecurringId', $recurring->id)
        ->assertSet('recurringAmount', '100,00')
        ->set('recurringAmount', '129,90')
        ->call('saveRecurring')
        ->assertHasNoErrors()
        ->assertSet('editingRecurringId', null);

    expect($recurring->fresh()->amount)->toBe(12990)
        ->and(Transaction::sole()->amount)->toBe(10000);

    $recurring->fresh()->generateDue(now()->toImmutable()->addMonth()->startOfDay());

    expect(Transaction::orderBy('date')->pluck('amount')->all())->toBe([10000, 12990]);
});

test('the recurring amount must be a positive valid value', function (string $value) {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $recurring = RecurringTransaction::factory()->forAccount($account)->create(['amount' => 10000]);
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('editRecurring', $recurring->id)
        ->set('recurringAmount', $value)
        ->call('saveRecurring')
        ->assertHasErrors('recurringAmount');

    expect($recurring->fresh()->amount)->toBe(10000);
})->with(['0,00', '-5,00', 'abc', '']);

test('a user cannot edit the recurrence of someone else', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $foreign = recurringFor(User::factory()->create(), now()->toDateString());
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')->call('editRecurring', $foreign->id)->assertNotFound();
});
