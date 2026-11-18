<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

test('compact money is short and keeps the sign', function (int $cents, string $expected) {
    expect(Money::compact($cents))->toBe($expected);
})->with([
    [0, 'R$ 0'],
    [80000, 'R$ 800'],
    [105000, 'R$ 1,05 mil'],
    [150000, 'R$ 1,5 mil'],
    [1234500, 'R$ 12,3 mil'],
    [25000000, 'R$ 250 mil'],
    [100000000, 'R$ 1 mi'],
    [-200000, '-R$ 2 mil'],
]);

test('axis ticks are ascending, evenly spaced and cover the data', function (int $min, int $max) {
    $ticks = Money::niceTicks($min, $max);
    $steps = array_unique(array_map(fn ($i) => $ticks[$i + 1] - $ticks[$i], range(0, count($ticks) - 2)));

    expect($ticks[0])->toBeLessThanOrEqual($min)
        ->and(end($ticks))->toBeGreaterThanOrEqual($max)
        ->and(count($ticks))->toBeGreaterThanOrEqual(3)
        ->and(count($ticks))->toBeLessThanOrEqual(8)
        ->and($steps)->toHaveCount(1);
})->with([
    'normal' => [95000, 125000],
    'negative to positive' => [-50000, 300000],
    'flat' => [100000, 100000],
    'zero' => [0, 0],
    'big' => [1000000, 9800000],
]);

test('the balance chart has axis values on the borders', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['initial_balance' => 100000]);
    Transaction::factory()->forAccount($account)->income()->create(['amount' => 50000, 'date' => now()->toDateString()]);
    $this->actingAs($user);

    $chart = Livewire::test('pages::dashboard')->instance()->chart;

    expect($chart['ticks'])->not->toBeEmpty()
        ->and(collect($chart['ticks'])->pluck('label')->all())->each->toStartWith('R$')
        ->and($chart['ticks'][0]['y'])->toBeGreaterThan(end($chart['ticks'])['y']);

    foreach ($chart['dots'] as $dot) {
        expect($dot['y'])->toBeGreaterThanOrEqual($chart['top'])->toBeLessThanOrEqual($chart['bottom'])
            ->and($dot['x'])->toBeGreaterThanOrEqual($chart['left']);
    }
});

test('every month has a hover area and every point a tooltip with its value', function () {
    $this->travelTo('2026-11-17 12:00:00');

    $user = User::factory()->create();
    Account::factory()->for($user)->create(['initial_balance' => 123456]);
    $this->actingAs($user);

    $component = Livewire::test('pages::dashboard');
    $html = $component->html();

    foreach (range(0, 11) as $i) {
        expect($html)->toContain('data-test="chart-hover-'.$i.'"');
    }

    foreach (range(0, 10) as $i) {
        expect($html)->toContain('data-test="chart-tooltip-'.$i.'"');
    }

    // Dezembro ainda não chegou: sem ponto e sem balão.
    expect($html)->not->toContain('data-test="chart-tooltip-11"');

    $component->assertSeeText(Money::format(123456));
});

test('the chart title says which year is shown', function () {
    $this->travelTo('2026-11-17 12:00:00');

    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::dashboard')->assertSeeText('2026');
});

test('the line chart comes before the category chart', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $this->actingAs($user);

    $html = Livewire::test('pages::dashboard')->html();

    expect(strpos($html, __('Balance evolution')))->toBeLessThan(strpos($html, __('Spending by category')));
});
