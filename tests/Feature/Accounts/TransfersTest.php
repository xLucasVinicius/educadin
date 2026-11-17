<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use Livewire\Livewire;

function twoAccounts(User $user, int $balanceA = 100000, int $balanceB = 50000): array
{
    return [
        Account::factory()->for($user)->create(['name' => 'A Origem', 'initial_balance' => $balanceA]),
        Account::factory()->for($user)->create(['name' => 'B Destino', 'initial_balance' => $balanceB]),
    ];
}

function transferDate(int $offset = 1): string
{
    return now()->startOfMonth()->addDays($offset)->toDateString();
}

test('a transfer moves the balance from the active account to the destination', function () {
    $user = User::factory()->create();
    [$a, $b] = twoAccounts($user);
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('createTransfer')
        ->set('transferTo', (string) $b->id)
        ->set('transferAmount', '300,50')
        ->set('transferDate', transferDate())
        ->set('transferDescription', 'Reserva')
        ->call('saveTransfer')
        ->assertHasNoErrors();

    $transfer = Transfer::firstOrFail();

    expect($transfer->from_account_id)->toBe($a->id)
        ->and($transfer->to_account_id)->toBe($b->id)
        ->and($transfer->amount)->toBe(30050)
        ->and($transfer->user_id)->toBe($user->id)
        ->and($a->newQuery()->withBalance()->find($a->id)->currentBalance())->toBe(100000 - 30050)
        ->and($b->newQuery()->withBalance()->find($b->id)->currentBalance())->toBe(50000 + 30050)
        ->and(Transaction::count())->toBe(0);
});

test('transfers do not count as income or expense and keep the total balance', function () {
    $user = User::factory()->create();
    [$a, $b] = twoAccounts($user);
    Transfer::factory()->between($a, $b)->create(['amount' => 40000, 'date' => transferDate()]);
    $this->actingAs($user);

    $accounts = Livewire::test('pages::accounts.index');
    expect($accounts->instance()->monthTotals)->toBe(['income' => 0, 'expense' => 0]);

    $dashboard = Livewire::test('pages::dashboard');
    expect($dashboard->instance()->totalBalance)->toBe(150000)
        ->and($dashboard->instance()->monthTotals)->toBe(['income' => 0, 'expense' => 0]);
});

test('the transfer appears on both accounts with the right direction', function () {
    $user = User::factory()->create();
    [$a, $b] = twoAccounts($user);
    Transfer::factory()->between($a, $b)->create(['amount' => 40000, 'date' => transferDate()]);
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->assertSee('Transferência para B Destino')
        ->call('selectNext')
        ->assertSee('Transferência de A Origem');
});

test('transfer to the same account is rejected', function () {
    $user = User::factory()->create();
    [$a] = twoAccounts($user);
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->set('transferTo', (string) $a->id)
        ->set('transferAmount', '10,00')
        ->set('transferDate', transferDate())
        ->call('saveTransfer')
        ->assertHasErrors(['transferTo']);

    expect(Transfer::count())->toBe(0);
});

test('transfer to an account of another user is rejected', function () {
    $user = User::factory()->create();
    twoAccounts($user);
    $foreign = Account::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->set('transferTo', (string) $foreign->id)
        ->set('transferAmount', '10,00')
        ->set('transferDate', transferDate())
        ->call('saveTransfer')
        ->assertHasErrors(['transferTo']);

    expect(Transfer::count())->toBe(0);
});

test('invalid amounts are rejected', function (string $amount) {
    $user = User::factory()->create();
    [, $b] = twoAccounts($user);
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->set('transferTo', (string) $b->id)
        ->set('transferAmount', $amount)
        ->set('transferDate', transferDate())
        ->call('saveTransfer')
        ->assertHasErrors(['transferAmount']);

    expect(Transfer::count())->toBe(0);
})->with(['0,00', '-10,00', 'abc', '']);

test('a transfer can be deleted and balances return', function () {
    $user = User::factory()->create();
    [$a, $b] = twoAccounts($user);
    $transfer = Transfer::factory()->between($a, $b)->create(['amount' => 40000, 'date' => transferDate()]);
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('confirmDeleteTransfer', $transfer->id)
        ->call('deleteTransfer');

    expect(Transfer::count())->toBe(0)
        ->and($a->newQuery()->withBalance()->find($a->id)->currentBalance())->toBe(100000);
});

test('foreign transfers cannot be deleted', function () {
    $user = User::factory()->create();
    twoAccounts($user);
    $foreign = Transfer::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')->call('confirmDeleteTransfer', $foreign->id)->assertNotFound();

    expect(Transfer::count())->toBe(1);
});

test('the opening balance of the month includes earlier transfers', function () {
    $user = User::factory()->create();
    [$a, $b] = twoAccounts($user);
    Transfer::factory()->between($a, $b)->create(['amount' => 40000, 'date' => now()->startOfMonth()->subDay()->toDateString()]);
    $this->actingAs($user);

    expect(Livewire::test('pages::accounts.index')->instance()->openingBalance)->toBe(60000);
});

test('transfers only exist with at least two accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')->call('createTransfer')->assertNotFound();
});

test('deleting an account removes its transfers', function () {
    $user = User::factory()->create();
    [$a, $b] = twoAccounts($user);
    Transfer::factory()->between($a, $b)->create(['amount' => 40000, 'date' => transferDate()]);
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('confirmDelete', $a->id)
        ->call('delete');

    expect(Transfer::count())->toBe(0)
        ->and($b->newQuery()->withBalance()->find($b->id)->currentBalance())->toBe(50000);
});
