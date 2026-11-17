<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Number;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('accounts.index'))->assertRedirect(route('login'));
});

test('page shows only the user own accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Minha Conta']);
    Account::factory()->create(['name' => 'Conta Alheia']);

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertOk()
        ->assertSee('Minha Conta')
        ->assertDontSee('Conta Alheia');
});

test('account can be created converting the amount to cents', function (string $input, int $cents) {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->set('name', 'Nubank')
        ->set('type', 'digital')
        ->set('initialBalance', $input)
        ->call('save')
        ->assertHasNoErrors();

    $account = $user->accounts()->firstOrFail();
    expect($account->initial_balance)->toBe($cents)
        ->and($account->type)->toBe(AccountType::Digital);
})->with([
    ['1.234,56', 123456],
    ['-50,00', -5000],
    ['50', 5000],
    ['50,5', 5050],
    ['0,00', 0],
]);

test('validation rules are enforced', function (array $data, string $field) {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::accounts.index')
        ->set('name', $data['name'] ?? 'Conta')
        ->set('type', $data['type'] ?? 'checking')
        ->set('initialBalance', $data['initialBalance'] ?? '0,00')
        ->call('save')
        ->assertHasErrors([$field]);
})->with([
    'name required' => [['name' => ''], 'name'],
    'type invalid' => [['type' => 'xyz'], 'type'],
    'type required' => [['type' => ''], 'type'],
    'amount invalid' => [['initialBalance' => 'abc'], 'initialBalance'],
    'amount with dot decimals' => [['initialBalance' => '12.5'], 'initialBalance'],
]);

test('account name must be unique per user but can repeat across users', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Nubank']);
    Account::factory()->create(['name' => 'Itaú']);

    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->set('name', 'Nubank')->set('type', 'digital')->set('initialBalance', '0,00')
        ->call('save')
        ->assertHasErrors(['name']);

    Livewire::test('pages::accounts.index')
        ->set('name', 'Itaú')->set('type', 'checking')->set('initialBalance', '0,00')
        ->call('save')
        ->assertHasNoErrors();
});

test('required message is in portuguese', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::accounts.index')
        ->set('name', '')->set('type', 'checking')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee('O campo nome é obrigatório.');
});

test('own account can be edited', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Velha', 'initial_balance' => 1000]);

    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('edit', $account->id)
        ->assertSet('name', 'Velha')
        ->assertSet('initialBalance', '10,00')
        ->set('name', 'Nova')
        ->set('initialBalance', '-5,25')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->refresh()->name)->toBe('Nova')
        ->and($account->initial_balance)->toBe(-525);
});

test('foreign accounts cannot be edited or deleted', function () {
    $user = User::factory()->create();
    $foreign = Account::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::accounts.index')->call('edit', $foreign->id)->assertNotFound();
    Livewire::test('pages::accounts.index')->call('confirmDelete', $foreign->id)->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
});

test('own account can be deleted', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('confirmDelete', $account->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect($account->fresh())->toBeNull();
});

test('balances are shown formatted in reais', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Devedora', 'initial_balance' => -5000]);

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertSee(Number::currency(-50, 'BRL', 'pt_BR'), false);
});
