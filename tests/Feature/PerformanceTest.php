<?php

use App\Enums\TransactionCategory;
use App\Models\Account;
use App\Models\Card;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Performance;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-11-17 12:00:00');
});

function perfSetup(): array
{
    $user = User::factory()->create();
    $user->forceFill(['plan' => 'premium'])->save();
    $account = Account::factory()->for($user)->create(['initial_balance' => 0]);

    return [$user, $account];
}

test('guests are sent to the login', function () {
    $this->get(route('performance.index'))->assertRedirect(route('login'));
});

test('the page renders all cards for a user without data', function () {
    $this->actingAs(User::factory()->create())->get(route('performance.index'))
        ->assertOk()
        ->assertSee('data-test="annual-card"', false)
        ->assertSee('data-test="comparison-card"', false)
        ->assertSee('data-test="categories-card"', false)
        ->assertSee('data-test="spending-card"', false)
        ->assertSee('data-test="control-card"', false);
});

test('the annual chart sums income and expense per month of the year', function () {
    [$user, $account] = perfSetup();
    Transaction::factory()->forAccount($account)->income()->create(['amount' => 500000, 'date' => '2026-03-10']);
    Transaction::factory()->forAccount($account)->create(['amount' => 120000, 'date' => '2026-03-20']);
    Transaction::factory()->forAccount($account)->create(['amount' => 99900, 'date' => '2025-03-20']);
    $this->actingAs($user);

    $chart = Livewire::test('pages::performance.index')->instance()->annualChart;

    expect($chart['months'])->toHaveCount(12)
        ->and($chart['months'][2]['income'])->toBe(500000)
        ->and($chart['months'][2]['expense'])->toBe(120000)
        ->and($chart['income'])->toBe(500000)
        ->and($chart['expense'])->toBe(120000);
});

test('changing the year selector changes the annual data', function () {
    [$user, $account] = perfSetup();
    Transaction::factory()->forAccount($account)->create(['amount' => 99900, 'date' => '2025-03-20']);
    $this->actingAs($user);

    $component = Livewire::test('pages::performance.index')->set('annualYear', '2025');

    expect($component->instance()->annualChart['expense'])->toBe(99900);
});

test('invalid selector values fall back to the current period', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::performance.index')
        ->set('annualYear', 'abc')
        ->set('donutMonth', '2026-99');

    expect($component->instance()->annualChart['year'])->toBe(2026)
        ->and($component->instance()->spending)->toBe([]);
});

test('the comparison shows variation against the previous month', function () {
    [$user, $account] = perfSetup();
    Transaction::factory()->forAccount($account)->income()->create(['amount' => 200000, 'date' => '2026-10-05']);
    Transaction::factory()->forAccount($account)->income()->create(['amount' => 300000, 'date' => '2026-11-05']);
    Transaction::factory()->forAccount($account)->create(['amount' => 100000, 'date' => '2026-10-06']);
    Transaction::factory()->forAccount($account)->create(['amount' => 50000, 'date' => '2026-11-06']);
    $this->actingAs($user);

    $rows = collect(Livewire::test('pages::performance.index')->instance()->comparison['rows'])->keyBy('key');

    expect($rows['income']['variation'])->toBe(50.0)
        ->and($rows['income']['good'])->toBeTrue()
        ->and($rows['expense']['variation'])->toBe(-50.0)
        ->and($rows['expense']['good'])->toBeTrue()
        ->and($rows['result']['current'])->toBe(250000)
        ->and($rows['result']['previous'])->toBe(100000)
        ->and($rows['result']['variation'])->toBe(150.0);
});

test('the comparison has no variation when the previous month is empty', function () {
    [$user, $account] = perfSetup();
    Transaction::factory()->forAccount($account)->create(['amount' => 50000, 'date' => '2026-11-06']);
    $this->actingAs($user);

    $rows = collect(Livewire::test('pages::performance.index')->instance()->comparison['rows'])->keyBy('key');

    expect($rows['expense']['variation'])->toBeNull();
});

test('spending by category includes cards, skips invoice payments and groups the rest', function () {
    [$user, $account] = perfSetup();
    $card = Card::factory()->for($user)->create();
    Transaction::factory()->forAccount($account)->create(['amount' => 30000, 'date' => '2026-11-02', 'category' => TransactionCategory::Food]);
    Transaction::factory()->forCard($card)->create(['amount' => 10000, 'date' => '2026-11-03', 'category' => TransactionCategory::Food]);
    Transaction::factory()->forAccount($account)->create(['amount' => 60000, 'date' => '2026-11-04', 'category' => TransactionCategory::CardBill]);
    $this->actingAs($user);

    $spending = Livewire::test('pages::performance.index')->instance()->spending;

    expect($spending)->toHaveCount(1)
        ->and($spending[0]['amount'])->toBe(40000)
        ->and($spending[0]['percent'])->toBe(100.0);
});

test('the essential split classifies categories', function () {
    [$user, $account] = perfSetup();
    Transaction::factory()->forAccount($account)->create(['amount' => 30000, 'date' => '2026-11-02', 'category' => TransactionCategory::Housing]);
    Transaction::factory()->forAccount($account)->create(['amount' => 10000, 'date' => '2026-11-03', 'category' => TransactionCategory::Leisure]);
    $this->actingAs($user);

    $control = Livewire::test('pages::performance.index')->instance()->control;

    expect($control['essential'])->toBe(30000)
        ->and($control['other'])->toBe(10000)
        ->and($control['essentialPercent'])->toBe(75.0)
        ->and($control['otherPercent'])->toBe(25.0);
});

test('the category chart has one line of 12 points per category', function () {
    [$user, $account] = perfSetup();
    Transaction::factory()->forAccount($account)->create(['amount' => 30000, 'date' => '2026-05-02', 'category' => TransactionCategory::Food]);
    Transaction::factory()->forAccount($account)->create(['amount' => 10000, 'date' => '2026-06-03', 'category' => TransactionCategory::Leisure]);
    $this->actingAs($user);

    $chart = Livewire::test('pages::performance.index')->instance()->categoryChart;

    expect($chart['series'])->toHaveCount(2)
        ->and($chart['series'][0]['points'])->toHaveCount(12)
        ->and($chart['series'][0]['label'])->not->toBeEmpty();
});

test('other users data never leaks into the page', function () {
    [, $otherAccount] = perfSetup();
    Transaction::factory()->forAccount($otherAccount)->create(['amount' => 77700, 'date' => '2026-11-02']);
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::performance.index')->instance();

    expect($component->annualChart['expense'])->toBe(0)
        ->and($component->spending)->toBe([])
        ->and($component->control['total'])->toBe(0);
});

test('variation helper handles zero base', function () {
    expect(Performance::variation(100, 0))->toBeNull()
        ->and(Performance::variation(150, 100))->toBe(50.0)
        ->and(Performance::variation(50, 100))->toBe(-50.0);
});

test('the free plan only sees the current month and year', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->forAccount($account)->create(['amount' => 99900, 'date' => '2025-03-20']);
    Transaction::factory()->forAccount($account)->create(['amount' => 11100, 'date' => '2026-11-02']);
    $this->actingAs($user);

    $component = Livewire::test('pages::performance.index')
        ->set('annualYear', '2025')
        ->set('donutMonth', '2025-03');

    expect($component->instance()->annualChart['year'])->toBe(2026)
        ->and($component->instance()->annualChart['expense'])->toBe(11100)
        ->and($component->instance()->spending[0]['amount'])->toBe(11100);

    $component->assertSee('data-test="history-locked"', false);
});

test('premium sees the full history without the lock notice', function () {
    [$user] = perfSetup();
    $this->actingAs($user);

    Livewire::test('pages::performance.index')->assertDontSee('data-test="history-locked"', false);
});
