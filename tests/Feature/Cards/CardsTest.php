<?php

use App\Enums\CardType;
use App\Models\Account;
use App\Models\Card;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('cards.index'))->assertRedirect(route('login'));
});

test('page shows only the user own cards', function () {
    $user = User::factory()->create();
    Card::factory()->for($user)->create(['name' => 'Meu Cartao']);
    Card::factory()->create(['name' => 'Cartao Alheio']);

    $this->actingAs($user)
        ->get(route('cards.index'))
        ->assertOk()
        ->assertSee('Meu Cartao')
        ->assertDontSee('Cartao Alheio');
});

test('credit card can be created', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->set('name', 'Nubank')
        ->set('type', 'credit')
        ->set('accountId', (string) $account->id)
        ->set('creditLimit', '4.500,00')
        ->set('closingDay', '5')
        ->set('dueDay', '12')
        ->call('save')
        ->assertHasNoErrors();

    $card = $user->cards()->firstOrFail();
    expect($card->type)->toBe(CardType::Credit)
        ->and($card->credit_limit)->toBe(450000)
        ->and($card->closing_day)->toBe(5)
        ->and($card->due_day)->toBe(12)
        ->and($card->account_id)->toBe($account->id);
});

test('debit card ignores credit fields', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->set('name', 'Itaú Débito')
        ->set('type', 'debit')
        ->set('accountId', (string) $account->id)
        ->set('creditLimit', '999,00')
        ->set('closingDay', '5')
        ->call('save')
        ->assertHasNoErrors();

    $card = $user->cards()->firstOrFail();
    expect($card->credit_limit)->toBeNull()
        ->and($card->closing_day)->toBeNull()
        ->and($card->due_day)->toBeNull();
});

test('credit card requires limit and days', function (array $data, string $field) {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->set('name', 'Cartao')
        ->set('type', 'credit')
        ->set('accountId', (string) $account->id)
        ->set('creditLimit', $data['creditLimit'] ?? '100,00')
        ->set('closingDay', $data['closingDay'] ?? '5')
        ->set('dueDay', $data['dueDay'] ?? '10')
        ->call('save')
        ->assertHasErrors([$field]);
})->with([
    'limit invalid' => [['creditLimit' => 'abc'], 'creditLimit'],
    'limit negative' => [['creditLimit' => '-10,00'], 'creditLimit'],
    'closing day missing' => [['closingDay' => ''], 'closingDay'],
    'closing day zero' => [['closingDay' => '0'], 'closingDay'],
    'closing day too big' => [['closingDay' => '32'], 'closingDay'],
    'due day letters' => [['dueDay' => 'ab'], 'dueDay'],
]);

test('name is required and unique per user', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Card::factory()->for($user)->create(['name' => 'Nubank', 'account_id' => $account->id]);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->set('name', '')->set('type', 'debit')->set('accountId', (string) $account->id)
        ->call('save')->assertHasErrors(['name']);

    Livewire::test('pages::cards.index')
        ->set('name', 'Nubank')->set('type', 'debit')->set('accountId', (string) $account->id)
        ->call('save')->assertHasErrors(['name']);
});

test('card cannot be linked to another user account', function () {
    $user = User::factory()->create();
    $foreignAccount = Account::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->set('name', 'Cartao')
        ->set('type', 'debit')
        ->set('accountId', (string) $foreignAccount->id)
        ->call('save')
        ->assertHasErrors(['accountId']);

    expect($user->cards()->count())->toBe(0);
});

test('own card can be edited', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->credit()->create([
        'name' => 'Velho', 'credit_limit' => 100000, 'closing_day' => 3, 'due_day' => 10,
    ]);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->call('edit', $card->id)
        ->assertSet('name', 'Velho')
        ->assertSet('creditLimit', '1.000,00')
        ->assertSet('closingDay', '3')
        ->set('name', 'Novo')
        ->set('creditLimit', '2.000,50')
        ->call('save')
        ->assertHasNoErrors();

    expect($card->refresh()->name)->toBe('Novo')
        ->and($card->credit_limit)->toBe(200050);
});

test('foreign cards cannot be edited or deleted', function () {
    $user = User::factory()->create();
    $foreign = Card::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::cards.index')->call('edit', $foreign->id)->assertNotFound();
    Livewire::test('pages::cards.index')->call('confirmDelete', $foreign->id)->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
});

test('own card can be deleted', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->call('confirmDelete', $card->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect($card->fresh())->toBeNull();
});

test('account with linked cards cannot be deleted', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('confirmDelete', $card->account_id)
        ->call('delete');

    expect(Account::find($card->account_id))->not->toBeNull();
});

test('deleting a user also removes accounts and cards', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $user->delete();

    expect(Card::find($card->id))->toBeNull()
        ->and(Account::find($card->account_id))->toBeNull();
});
