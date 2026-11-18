<?php

use App\Models\User;
use App\Support\LinkedAccounts;

function loginAs(User $user)
{
    return test()->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
}

test('logging in remembers the account on this browser', function () {
    $user = User::factory()->create();

    loginAs($user);

    expect(LinkedAccounts::ids())->toBe([$user->id]);
});

test('a second account can be added and both stay linked', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    loginAs($a);
    $this->post(route('linked-accounts.add'))->assertRedirect(route('login'));
    $this->assertGuest();

    loginAs($b);

    $this->assertAuthenticatedAs($b);
    expect(LinkedAccounts::ids())->toBe([$a->id, $b->id]);
});

test('switching goes to another linked account without a password', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    loginAs($a);
    $this->post(route('linked-accounts.add'));
    loginAs($b);

    $this->post(route('linked-accounts.switch', $a))->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($a);
    expect(LinkedAccounts::ids())->toContain($a->id, $b->id);
});

test('an account that never logged in on this browser cannot be switched to', function () {
    $a = User::factory()->create();
    $stranger = User::factory()->create();
    loginAs($a);

    $this->post(route('linked-accounts.switch', $stranger))->assertNotFound();

    $this->assertAuthenticatedAs($a);
});

test('a suspended linked account is not switched to', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    loginAs($a);
    $this->post(route('linked-accounts.add'));
    loginAs($b);
    $a->forceFill(['suspended_at' => now()])->save();

    $this->post(route('linked-accounts.switch', $a));

    $this->assertAuthenticatedAs($b);
    expect(LinkedAccounts::ids())->not->toContain($a->id);
});

test('leaving moves to the next linked account and the last one ends the session', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    loginAs($a);
    $this->post(route('linked-accounts.add'));
    loginAs($b);

    $this->post(route('linked-accounts.leave'))->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($a);
    expect(LinkedAccounts::ids())->toBe([$a->id]);

    $this->post(route('linked-accounts.leave'))->assertRedirect('/');
    $this->assertGuest();
});

test('the profile menu lists the other accounts of this browser', function () {
    $a = User::factory()->create(['email' => 'primeira@example.com']);
    $b = User::factory()->create(['email' => 'segunda@example.com']);

    $this->withSession([LinkedAccounts::KEY => [$a->id, $b->id]])
        ->actingAs($b)->get(route('dashboard'))
        ->assertSee('segunda@example.com')
        ->assertSee('data-test="switch-account-'.$a->id.'"', false)
        ->assertDontSee('data-test="switch-account-'.$b->id.'"', false)
        ->assertSee('data-test="add-account-button"', false);
});

test('the menu has no account list when only one account is linked', function () {
    $a = User::factory()->create();

    $this->withSession([LinkedAccounts::KEY => [$a->id]])
        ->actingAs($a)->get(route('dashboard'))
        ->assertDontSee('data-test="linked-accounts-title"', false)
        ->assertSee('data-test="logout-button"', false);
});

test('the login page offers the accounts already used on this browser', function () {
    $a = User::factory()->create(['email' => 'salva@example.com']);

    $this->withSession([LinkedAccounts::KEY => [$a->id]])
        ->get(route('login'))
        ->assertSee('data-test="saved-account-'.$a->id.'"', false)
        ->assertSee('salva@example.com');
});

test('the login page has no saved accounts block for a fresh browser', function () {
    User::factory()->create();

    $this->get(route('login'))->assertDontSee('data-test="saved-accounts"', false);
});

test('the list is capped at five accounts, dropping the oldest', function () {
    foreach (range(1, 6) as $id) {
        LinkedAccounts::add($id);
    }

    expect(LinkedAccounts::ids())->toBe([2, 3, 4, 5, 6]);
});

test('logging in stores the account in the known accounts cookie', function () {
    $a = User::factory()->create();

    loginAs($a)->assertCookie('known_accounts', json_encode([$a->id]));
});

test('the login page lists known accounts after leaving everything, asking for the password', function () {
    $a = User::factory()->create(['email' => 'conhecida@example.com']);

    $this->withCookie('known_accounts', json_encode([$a->id]))
        ->get(route('login'))
        ->assertSee('data-test="known-account-'.$a->id.'"', false)
        ->assertSee('data-test="known-login-form-'.$a->id.'"', false)
        ->assertSee('name="email" value="conhecida@example.com"', false)
        ->assertSee('conhecida@example.com')
        ->assertDontSee('data-test="saved-account-'.$a->id.'"', false);
});

test('a known account does not sign in with the wrong password', function () {
    $a = User::factory()->create();

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $a->email, 'password' => 'errada'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('an account both signed in and known appears once, as signed in', function () {
    $a = User::factory()->create();

    $this->withCookie('known_accounts', json_encode([$a->id]))
        ->withSession([LinkedAccounts::KEY => [$a->id]])
        ->get(route('login'))
        ->assertSee('data-test="saved-account-'.$a->id.'"', false)
        ->assertDontSee('data-test="known-account-'.$a->id.'"', false);
});

test('known accounts never sign in without the password', function () {
    $a = User::factory()->create();

    $this->withCookie('known_accounts', json_encode([$a->id]))
        ->post(route('linked-accounts.switch', $a))
        ->assertNotFound();

    $this->assertGuest();
});

test('suspended, deleted and unknown ids are not shown', function () {
    $suspended = User::factory()->create(['email' => 'suspensa@example.com']);
    $suspended->forceFill(['suspended_at' => now()])->save();

    $this->withCookie('known_accounts', json_encode([$suspended->id, 99999]))
        ->get(route('login'))
        ->assertDontSee('suspensa@example.com')
        ->assertDontSee('data-test="saved-accounts"', false);
});

test('a known account can be forgotten', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->withCookie('known_accounts', json_encode([$a->id, $b->id]))
        ->post(route('linked-accounts.forget', $a))
        ->assertRedirect(route('login'))
        ->assertCookie('known_accounts', json_encode([$b->id]));
});

test('a stranger account cannot be forgotten through the list', function () {
    $a = User::factory()->create();

    $this->post(route('linked-accounts.forget', $a))->assertNotFound();
});

test('a wrong password in the known account modal shows the error inside the modal only', function () {
    $a = User::factory()->create();

    $this->withCookie('known_accounts', json_encode([$a->id]))
        ->from(route('login'))
        ->followingRedirects()
        ->post(route('login.store'), ['email' => $a->email, 'password' => 'errada', 'from_known' => 1])
        ->assertSee('data-test="known-login-error"', false)
        ->assertSee('[&_[data-flux-error]]:!hidden', false);

    $this->assertGuest();
});
