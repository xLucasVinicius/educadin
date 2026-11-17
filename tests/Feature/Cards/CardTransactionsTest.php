<?php

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Card;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

function cardMonthDay(int $offset = 0): string
{
    return now()->startOfMonth()->addDays($offset)->toDateString();
}

test('card transaction is created as an expense linked only to the card', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->call('createTransaction')
        ->set('kind', 'income') // cartões só aceitam despesa
        ->set('description', 'Restaurante')
        ->set('amount', '89,90')
        ->set('date', cardMonthDay(1))
        ->set('category', 'food')
        ->call('saveTransaction')
        ->assertHasNoErrors();

    $transaction = Transaction::firstOrFail();

    expect($transaction->type)->toBe(TransactionType::Expense)
        ->and($transaction->amount)->toBe(8990)
        ->and($transaction->card_id)->toBe($card->id)
        ->and($transaction->account_id)->toBeNull()
        ->and($transaction->user_id)->toBe($user->id);
});

test('income categories are rejected on cards', function () {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->set('description', 'Teste')->set('amount', '10,00')
        ->set('date', cardMonthDay(1))->set('category', 'salary')
        ->call('saveTransaction')
        ->assertHasErrors(['category']);
});

test('only the active card transactions are listed, never account ones', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $a = Card::factory()->for($user)->create(['name' => 'A Cartao', 'account_id' => $account->id]);
    $b = Card::factory()->for($user)->create(['name' => 'B Cartao', 'account_id' => $account->id]);
    Transaction::factory()->forCard($a)->create(['description' => 'Gasto do cartao A', 'date' => cardMonthDay(1)]);
    Transaction::factory()->forCard($b)->create(['description' => 'Gasto do cartao B', 'date' => cardMonthDay(1)]);
    Transaction::factory()->forAccount($account)->create(['description' => 'Gasto da conta', 'date' => cardMonthDay(1)]);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->assertSee('Gasto do cartao A')
        ->assertDontSee('Gasto do cartao B')
        ->assertDontSee('Gasto da conta')
        ->call('selectNext')
        ->assertSee('Gasto do cartao B')
        ->assertDontSee('Gasto do cartao A');
});

test('monthly spending of a credit card is shown', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->credit()->create(['credit_limit' => 100000]);
    Transaction::factory()->forCard($card)->create(['amount' => 25000, 'date' => cardMonthDay(1)]);
    $this->actingAs($user);

    $this->get(route('cards.index'))
        ->assertOk()
        ->assertSee(Money::format(25000), false)
        ->assertSee(Money::format(100000), false);
});

test('select only accepts own cards', function () {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();
    $foreign = Card::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::cards.index')->call('select', $foreign->id)->assertNotFound();
});

test('card transaction can be edited and deleted', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    $transaction = Transaction::factory()->forCard($card)->create(['description' => 'Velho', 'amount' => 1000, 'date' => cardMonthDay(1)]);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->call('editTransaction', $transaction->id)
        ->assertSet('description', 'Velho')
        ->set('description', 'Novo')
        ->call('saveTransaction')
        ->assertHasNoErrors();

    expect($transaction->refresh()->description)->toBe('Novo');

    Livewire::test('pages::cards.index')
        ->call('confirmDeleteTransaction', $transaction->id)
        ->call('deleteTransaction');

    expect($transaction->fresh())->toBeNull();
});

test('foreign card transactions cannot be edited', function () {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();
    $foreignCard = Card::factory()->create();
    $foreign = Transaction::factory()->forCard($foreignCard)->create();
    $this->actingAs($user);

    Livewire::test('pages::cards.index')->call('editTransaction', $foreign->id)->assertNotFound();
});

test('deleting a card also deletes its transactions and warns how many', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    Transaction::factory()->forCard($card)->count(3)->create(['date' => cardMonthDay(1)]);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->call('confirmDelete', $card->id)
        ->assertSee('3 lançamentos deste cartão também serão excluídos.')
        ->call('delete');

    expect(Card::find($card->id))->toBeNull()
        ->and(Transaction::count())->toBe(0);
});
