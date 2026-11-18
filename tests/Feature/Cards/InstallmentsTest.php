<?php

use App\Models\Account;
use App\Models\Card;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function installmentCard(User $user, array $attrs = []): Card
{
    $account = Account::factory()->for($user)->create();

    return Card::factory()->for($user)->credit()->create($attrs + [
        'account_id' => $account->id, 'credit_limit' => 500000, 'closing_day' => 28, 'due_day' => 10,
    ]);
}

function buyInstallments(string $amount, int $installments, string $date = '2026-11-05'): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test('pages::cards.index')
        ->call('createTransaction')
        ->set('description', 'Tênis')
        ->set('amount', $amount)
        ->set('date', $date)
        ->set('category', 'shopping')
        ->set('installments', $installments)
        ->call('saveTransaction');
}

test('a purchase is split into monthly installments linked by a group', function () {
    $user = User::factory()->create();
    $card = installmentCard($user);
    $this->actingAs($user);

    buyInstallments('100,00', 3)->assertHasNoErrors();

    $list = Transaction::orderBy('installment_number')->get();

    expect($list)->toHaveCount(3)
        ->and($list->pluck('installment_number')->all())->toBe([1, 2, 3])
        ->and($list->pluck('installment_total')->unique()->all())->toBe([3])
        ->and($list->pluck('installment_group')->unique())->toHaveCount(1)
        ->and($list->map(fn ($t) => $t->date->toDateString())->all())->toBe(['2026-11-05', '2026-12-05', '2027-01-05'])
        ->and($list->pluck('card_id')->unique()->all())->toBe([$card->id])
        ->and($list->pluck('account_id')->filter()->all())->toBe([]);
});

test('the cents left over go to the first installment so the sum matches', function () {
    $user = User::factory()->create();
    installmentCard($user);
    $this->actingAs($user);

    buyInstallments('100,00', 3);

    $amounts = Transaction::orderBy('installment_number')->pluck('amount')->all();

    expect($amounts)->toBe([3334, 3333, 3333])
        ->and(array_sum($amounts))->toBe(10000);
});

test('a month without the same day uses its last day', function () {
    $user = User::factory()->create();
    installmentCard($user);
    $this->actingAs($user);

    buyInstallments('90,00', 3, '2026-01-31');

    expect(Transaction::orderBy('installment_number')->get()->map(fn ($t) => $t->date->toDateString())->all())
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

test('each installment falls in its own invoice', function () {
    $user = User::factory()->create();
    $card = installmentCard($user);
    $this->actingAs($user);

    buyInstallments('90,00', 3);

    $months = Transaction::orderBy('installment_number')->get()->map(fn ($t) => $card->invoiceMonthFor($t->date->toDateString()))->all();

    expect($months)->toBe(['2026-11', '2026-12', '2027-01']);
});

test('a single payment creates one plain transaction', function () {
    $user = User::factory()->create();
    installmentCard($user);
    $this->actingAs($user);

    buyInstallments('50,00', 1);

    expect(Transaction::count())->toBe(1)
        ->and(Transaction::first()->installment_group)->toBeNull();
});

test('invalid installment counts are rejected', function (int $count) {
    $user = User::factory()->create();
    installmentCard($user);
    $this->actingAs($user);

    buyInstallments('100,00', $count)->assertHasErrors(['installments']);

    expect(Transaction::count())->toBe(0);
})->with([0, -1, 25, 100]);

test('the amount must be at least one cent per installment', function () {
    $user = User::factory()->create();
    installmentCard($user);
    $this->actingAs($user);

    buyInstallments('0,05', 6)->assertHasErrors(['amount']);

    expect(Transaction::count())->toBe(0);
});

test('debit cards never split the purchase', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Card::factory()->for($user)->create(['account_id' => $account->id]);
    $this->actingAs($user);

    buyInstallments('100,00', 3)->assertHasNoErrors();

    expect(Transaction::count())->toBe(1);
});

test('accounts never split a transaction into installments', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->call('createTransaction')
        ->set('description', 'Mercado')->set('amount', '90,00')
        ->set('date', '2026-11-05')->set('category', 'food')
        ->set('installments', 3)
        ->call('saveTransaction')
        ->assertHasNoErrors();

    expect(Transaction::count())->toBe(1);
});

test('the whole purchase counts against the card limit right away', function () {
    $user = User::factory()->create();
    installmentCard($user);
    $this->actingAs($user);

    $component = buyInstallments('300,00', 3);

    expect($component->instance()->usedCredit)->toBe(30000);
});

test('an installment can be edited without touching the others', function () {
    $user = User::factory()->create();
    installmentCard($user);
    $this->actingAs($user);
    buyInstallments('90,00', 3);
    $second = Transaction::where('installment_number', 2)->firstOrFail();

    Livewire::test('pages::cards.index')
        ->call('editTransaction', $second->id)
        ->set('description', 'Tênis (corrigido)')
        ->call('saveTransaction')
        ->assertHasNoErrors();

    expect(Transaction::count())->toBe(3)
        ->and($second->refresh()->description)->toBe('Tênis (corrigido)')
        ->and(Transaction::where('description', 'Tênis')->count())->toBe(2);
});

test('deleting only one installment keeps the rest', function () {
    $user = User::factory()->create();
    installmentCard($user);
    $this->actingAs($user);
    buyInstallments('90,00', 3);
    $second = Transaction::where('installment_number', 2)->firstOrFail();

    Livewire::test('pages::cards.index')
        ->call('confirmDeleteTransaction', $second->id)
        ->call('deleteTransaction');

    expect(Transaction::pluck('installment_number')->sort()->values()->all())->toBe([1, 3]);
});

test('deleting this and the following installments keeps the earlier ones', function () {
    $user = User::factory()->create();
    installmentCard($user);
    $this->actingAs($user);
    buyInstallments('120,00', 4);
    $second = Transaction::where('installment_number', 2)->firstOrFail();

    Livewire::test('pages::cards.index')
        ->call('confirmDeleteTransaction', $second->id)
        ->set('deleteScope', 'following')
        ->call('deleteTransaction');

    expect(Transaction::pluck('installment_number')->all())->toBe([1]);
});

test('deleting all installments removes the whole purchase', function () {
    $user = User::factory()->create();
    $card = installmentCard($user);
    $this->actingAs($user);
    buyInstallments('120,00', 4);
    Transaction::factory()->forCard($card)->create(['description' => 'Outra compra', 'date' => '2026-11-06']);
    $third = Transaction::where('installment_number', 3)->firstOrFail();

    Livewire::test('pages::cards.index')
        ->call('confirmDeleteTransaction', $third->id)
        ->set('deleteScope', 'all')
        ->call('deleteTransaction');

    expect(Transaction::count())->toBe(1)
        ->and(Transaction::first()->description)->toBe('Outra compra');
});

test('the installment counter shows in the list', function () {
    $user = User::factory()->create();
    installmentCard($user);
    $this->actingAs($user);
    buyInstallments('90,00', 3);

    Livewire::test('pages::cards.index')->set('month', '2026-11')->assertSee('1/3');
});

test('future installments stay out of the dashboard recent transactions', function () {
    $user = User::factory()->create();
    $card = installmentCard($user);
    Account::factory()->for($user)->create();
    Transaction::factory()->forCard($card)->create([
        'description' => 'Parcela futura', 'date' => now()->addMonths(2)->toDateString(),
        'installment_group' => 'g1', 'installment_number' => 3, 'installment_total' => 3,
    ]);
    Transaction::factory()->forCard($card)->create([
        'description' => 'Parcela de hoje', 'date' => now()->toDateString(),
        'installment_group' => 'g1', 'installment_number' => 1, 'installment_total' => 3,
    ]);
    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->assertSee('Parcela de hoje')
        ->assertDontSee('Parcela futura');
});

test('foreign installments cannot be deleted', function () {
    $user = User::factory()->create();
    installmentCard($user);
    $foreignCard = installmentCard(User::factory()->create());
    $foreign = Transaction::factory()->forCard($foreignCard)->create([
        'installment_group' => 'g1', 'installment_number' => 1, 'installment_total' => 2,
    ]);
    $this->actingAs($user);

    Livewire::test('pages::cards.index')
        ->call('confirmDeleteTransaction', $foreign->id)
        ->assertNotFound();

    expect(Transaction::count())->toBe(1);
});
