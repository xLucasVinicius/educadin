<?php

use App\Models\User;
use Livewire\Livewire;

test('guests are sent to the login', function () {
    $this->get(route('plans.index'))->assertRedirect(route('login'));
});

test('the plans page lists both plans with their benefits', function () {
    $this->actingAs(User::factory()->create())->get(route('plans.index'))
        ->assertOk()
        ->assertSee('data-test="plan-free"', false)
        ->assertSee('data-test="plan-premium"', false)
        ->assertSee(config('educadin.plans.free.features.0'))
        ->assertSee(config('educadin.plans.premium.features.0'));
});

test('premium benefits are listed only on the premium card', function () {
    $this->actingAs(User::factory()->create());

    $html = Livewire::test('pages::plans.index')->html();

    foreach (config('educadin.plans.premium.features') as $feature) {
        expect(substr_count($html, e($feature)))->toBe(1);
    }
});

test('a free user sees the current plan on the free card only', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::plans.index');

    expect($component->instance()->currentPlan)->toBe('free');
    expect(substr_count($component->html(), 'data-test="current-plan-badge"'))->toBe(1);
});

test('a premium user sees the current plan on the premium card with its end date', function () {
    $user = User::factory()->create();
    $user->forceFill(['plan' => 'premium', 'premium_until' => '2027-03-15'])->save();
    $this->actingAs($user->fresh());

    $component = Livewire::test('pages::plans.index');

    expect($component->instance()->currentPlan)->toBe('premium');
    $component->assertSee('15/03/2027');
});

test('an expired premium counts as the free plan', function () {
    $user = User::factory()->create();
    $user->forceFill(['plan' => 'premium', 'premium_until' => now()->subDay()->toDateString()])->save();
    $this->actingAs($user->fresh());

    expect(Livewire::test('pages::plans.index')->instance()->currentPlan)->toBe('free');
});

test('the sidebar has a link to the plans page', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertSee('data-test="plans-link"', false);
});
