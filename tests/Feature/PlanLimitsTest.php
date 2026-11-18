<?php

use App\Models\Account;
use App\Models\Card;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PlanLimits;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-11-17 12:00:00');
});

function premiumUser(?string $until = null): User
{
    $user = User::factory()->create();
    $user->forceFill(['plan' => 'premium', 'premium_until' => $until])->save();

    return $user;
}

function newAccount(string $name)
{
    return Livewire::test('pages::accounts.index')
        ->call('create')
        ->set('name', $name)
        ->set('type', 'checking')
        ->set('color', 'amber')
        ->set('initialBalance', '0,00')
        ->call('save');
}

test('limits follow the plan and an expired premium falls back to free', function () {
    $free = User::factory()->create();
    $premium = premiumUser();
    $expired = premiumUser('2026-11-10');

    expect(PlanLimits::limit($free, 'accounts'))->toBe(3)
        ->and(PlanLimits::limit($premium, 'accounts'))->toBeNull()
        ->and(PlanLimits::limit($expired, 'accounts'))->toBe(3)
        ->and(PlanLimits::installmentsMax($free))->toBe(12)
        ->and(PlanLimits::installmentsMax($premium))->toBe(24);
});

test('a free user cannot create more accounts than the limit', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->count(3)->create();
    $this->actingAs($user);

    newAccount('Quarta conta');

    expect($user->accounts()->count())->toBe(3);
});

test('a free user below the limit can still create accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->count(2)->create();
    $this->actingAs($user);

    newAccount('Terceira conta');

    expect($user->accounts()->count())->toBe(3);
});

test('premium has no account limit', function () {
    $user = premiumUser();
    Account::factory()->for($user)->count(3)->create();
    $this->actingAs($user);

    newAccount('Quarta conta');

    expect($user->accounts()->count())->toBe(4);
});

test('a free user cannot create more cards than the limit', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Card::factory()->for($user)->count(2)->create(['account_id' => $account->id]);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->call('create')
        ->set('name', 'Terceiro')
        ->set('type', 'debit')
        ->set('accountId', (string) $account->id)
        ->call('save');

    expect($user->cards()->count())->toBe(2);
});

test('accounts over the limit are locked, the oldest stay free', function () {
    $user = User::factory()->create();
    $accounts = Account::factory()->for($user)->count(4)->create();

    expect(PlanLimits::lockedIds($user, 'accounts'))->toBe([$accounts[3]->id])
        ->and(PlanLimits::isLocked($user, $accounts[3]))->toBeTrue()
        ->and(PlanLimits::isLocked($user, $accounts[0]))->toBeFalse();

    $user->forceFill(['plan' => 'premium'])->save();

    expect(PlanLimits::lockedIds($user->fresh(), 'accounts'))->toBe([]);
});

test('a locked account shows the lock cover and hides its transactions', function () {
    $user = User::factory()->create();
    $accounts = Account::factory()->for($user)->count(4)->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('select', $accounts[0]->id)
        ->assertDontSee('data-test="plan-lock"', false)
        ->assertSee('data-test="new-transaction-button"', false)
        ->call('select', $accounts[3]->id)
        ->assertSee('data-test="plan-lock"', false)
        ->assertDontSee('data-test="new-transaction-button"', false);
});

test('nothing can be created or edited in a locked account', function () {
    $user = User::factory()->create();
    $accounts = Account::factory()->for($user)->count(4)->create();
    $locked = $accounts[3];
    $existing = Transaction::factory()->forAccount($locked)->create(['description' => 'Antigo', 'date' => '2026-11-05']);
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('select', $locked->id)
        ->call('createTransaction')
        ->set('description', 'Novo')
        ->set('amount', '10,00')
        ->set('date', '2026-11-06')
        ->set('category', 'food')
        ->call('saveTransaction')
        ->call('edit', $locked->id)
        ->call('editTransaction', $existing->id);

    expect(Transaction::where('description', 'Novo')->exists())->toBeFalse()
        ->and($existing->fresh()->description)->toBe('Antigo');
});

test('a locked account can still be deleted', function () {
    $user = User::factory()->create();
    $accounts = Account::factory()->for($user)->count(4)->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('confirmDelete', $accounts[3]->id)
        ->call('delete');

    expect($user->accounts()->count())->toBe(3);
});

test('a locked card shows the lock cover and blocks invoice payment', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $cards = Card::factory()->for($user)->credit()->count(3)->create(['account_id' => $account->id, 'credit_limit' => 500000, 'closing_day' => 28, 'due_day' => 10]);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->call('select', $cards[2]->id)
        ->assertSee('data-test="plan-lock"', false)
        ->call('payInvoice');

    expect(Transaction::whereNull('card_id')->count())->toBe(0);
});

test('a free user cannot create more recurring transactions than the limit', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    RecurringTransaction::factory()->forAccount($account)->count(3)->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('createTransaction')
        ->set('description', 'Quarta')
        ->set('amount', '10,00')
        ->set('date', '2026-11-20')
        ->set('category', 'bills')
        ->set('repeatMonthly', true)
        ->call('saveTransaction');

    expect(RecurringTransaction::where('user_id', $user->id)->count())->toBe(3);
});

test('cancelled recurring transactions do not count against the limit', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    RecurringTransaction::factory()->forAccount($account)->count(3)->create(['cancelled_at' => now()]);

    expect(PlanLimits::reached($user, 'recurring'))->toBeFalse();
});

test('free users are limited to 12 installments and premium to 24', function (bool $premium, int $count, bool $allowed) {
    $user = $premium ? premiumUser() : User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Card::factory()->for($user)->credit()->create(['account_id' => $account->id, 'credit_limit' => 5000000, 'closing_day' => 28, 'due_day' => 10]);
    $this->actingAs($user);

    $test = Livewire::test('pages::cards.index')
        ->call('createTransaction')
        ->set('description', 'Compra')
        ->set('amount', '2400,00')
        ->set('date', '2026-11-05')
        ->set('category', 'shopping')
        ->set('installments', $count)
        ->call('saveTransaction');

    $allowed ? $test->assertHasNoErrors() : $test->assertHasErrors(['installments']);
    expect(Transaction::count())->toBe($allowed ? $count : 0);
})->with([
    'free 12x' => [false, 12, true],
    'free 13x' => [false, 13, false],
    'premium 24x' => [true, 24, true],
    'premium 25x' => [true, 25, false],
]);
