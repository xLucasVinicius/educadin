<?php

use App\Models\Account;
use App\Models\Card;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

function dashDay(int $offset = 0, int $monthsBack = 0): string
{
    return now()->startOfMonth()->subMonthsNoOverflow($monthsBack)->addDays($offset)->toDateString();
}

test('user without accounts sees the welcome message', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Crie sua primeira conta');
});

test('total balance sums every account including transactions', function () {
    $user = User::factory()->create();
    $a = Account::factory()->for($user)->create(['initial_balance' => 100000]);
    $b = Account::factory()->for($user)->create(['initial_balance' => 50000]);
    Transaction::factory()->forAccount($a)->income()->create(['amount' => 30000, 'date' => dashDay(1)]);
    Transaction::factory()->forAccount($b)->create(['amount' => 10000, 'date' => dashDay(2)]);
    $this->actingAs($user);

    $component = Livewire::test('pages::dashboard');

    expect($component->instance()->totalBalance)->toBe(170000);
    $component->assertSee(Money::format(170000), false);
});

test('month totals count only accounts of the selected month', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $card = Card::factory()->for($user)->create(['account_id' => $account->id]);
    Transaction::factory()->forAccount($account)->income()->create(['amount' => 50000, 'date' => dashDay(1)]);
    Transaction::factory()->forAccount($account)->create(['amount' => 20000, 'date' => dashDay(2)]);
    Transaction::factory()->forAccount($account)->create(['amount' => 77777, 'date' => dashDay(1, 1)]);
    Transaction::factory()->forCard($card)->create(['amount' => 99999, 'date' => dashDay(3)]);
    $this->actingAs($user);

    $component = Livewire::test('pages::dashboard');

    expect($component->instance()->monthTotals)->toBe(['income' => 50000, 'expense' => 20000]);

    $component->call('previousMonth');

    expect($component->instance()->monthTotals)->toBe(['income' => 0, 'expense' => 77777]);
});

test('spending by category includes accounts and cards and adds up to 100 percent', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $card = Card::factory()->for($user)->create(['account_id' => $account->id]);
    Transaction::factory()->forAccount($account)->create(['amount' => 30000, 'category' => 'food', 'date' => dashDay(1)]);
    Transaction::factory()->forCard($card)->create(['amount' => 10000, 'category' => 'food', 'date' => dashDay(2)]);
    Transaction::factory()->forCard($card)->create(['amount' => 20000, 'category' => 'leisure', 'date' => dashDay(3)]);
    Transaction::factory()->forAccount($account)->income()->create(['amount' => 90000, 'date' => dashDay(1)]);
    $this->actingAs($user);

    $slices = Livewire::test('pages::dashboard')->instance()->expensesByCategory;

    expect($slices)->toHaveCount(2)
        ->and($slices[0]['label'])->toBe('Alimentação')
        ->and($slices[0]['amount'])->toBe(40000)
        ->and($slices[0]['percent'])->toBe(66.7)
        ->and($slices[1]['label'])->toBe('Lazer')
        ->and(round(array_sum(array_column($slices, 'share'))))->toBe(100.0);
});

test('categories beyond the sixth are grouped as others', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    foreach (['food', 'housing', 'transport', 'health', 'education', 'leisure', 'shopping', 'bills'] as $i => $category) {
        Transaction::factory()->forAccount($account)->create([
            'amount' => 10000 - $i * 100, 'category' => $category, 'date' => dashDay(1),
        ]);
    }
    $this->actingAs($user);

    $slices = Livewire::test('pages::dashboard')->instance()->expensesByCategory;

    expect($slices)->toHaveCount(7)
        ->and($slices[6]['label'])->toBe('Outros')
        ->and($slices[6]['amount'])->toBe((10000 - 6 * 100) + (10000 - 7 * 100));
});

test('balance series shows the end of month balance for the last 6 months', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['initial_balance' => 100000]);
    Transaction::factory()->forAccount($account)->create(['amount' => 20000, 'date' => dashDay(1, 1)]);
    Transaction::factory()->forAccount($account)->income()->create(['amount' => 50000, 'date' => dashDay(1)]);
    Transaction::factory()->forAccount($account)->create(['amount' => 5000, 'date' => dashDay(1, 8)]);
    $this->actingAs($user);

    $series = Livewire::test('pages::dashboard')->instance()->balanceSeries;
    $values = array_column($series, 'value');

    // Compra antiga (8 meses atrás) já entra no ponto de partida: 100000 - 5000.
    expect($series)->toHaveCount(6)
        ->and($values)->toBe([95000, 95000, 95000, 95000, 75000, 125000]);
});

test('chart geometry is built for the series', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['initial_balance' => 100000]);
    $this->actingAs($user);

    $chart = Livewire::test('pages::dashboard')->instance()->chart;

    expect($chart['dots'])->toHaveCount(6)
        ->and($chart['line'])->not->toBeEmpty()
        ->and($chart['area'])->toStartWith('M ');
});

test('cards show the month spending and ignore other users data', function () {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->credit()->create(['name' => 'Meu Cartao', 'credit_limit' => 100000]);
    Transaction::factory()->forCard($card)->create(['amount' => 25000, 'date' => dashDay(1)]);
    $other = Card::factory()->credit()->create(['name' => 'Cartao Alheio']);
    Transaction::factory()->forCard($other)->create(['amount' => 88888, 'date' => dashDay(1)]);
    Account::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->assertSee('Meu Cartao')
        ->assertDontSee('Cartao Alheio')
        ->assertSee(Money::format(25000), false);
});

test('latest transactions list only the user own records', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->forAccount($account)->create(['description' => 'Meu lancamento', 'date' => dashDay(1)]);
    Transaction::factory()->create(['description' => 'Lancamento alheio', 'date' => dashDay(1)]);
    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->assertSee('Meu lancamento')
        ->assertDontSee('Lancamento alheio');
});

test('month can be navigated', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $this->actingAs($user);

    $current = Livewire::test('pages::dashboard')->instance()->monthLabel;

    $component = Livewire::test('pages::dashboard')->call('previousMonth');

    expect($component->instance()->monthLabel)->not->toBe($current);

    $component->call('nextMonth');

    expect($component->instance()->monthLabel)->toBe($current);
});
