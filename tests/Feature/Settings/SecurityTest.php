<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use Livewire\Livewire;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
    Features::passkeys([
        'confirmPassword' => true,
    ]);
});

test('security settings page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'));

    $response->assertOk();

    $response->assertSee(__('Passkeys'));
    $response->assertSee(__('No passkeys yet'));
    $response->assertSee(__('Two-factor authentication'));
    $response->assertSee(__('Enable 2FA'));
});

test('security settings page opens directly with every section visible', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee(__('Update password'))
        ->assertSee(__('Enable 2FA'))
        ->assertSee(__('No passkeys yet'));
});

test('the password is only asked after clicking a sensitive action', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.security')
        ->call('requestUnlock', 'enable2fa')
        ->assertSet('pendingIntent', 'enable2fa')
        ->assertNotDispatched('start-two-factor-setup')
        ->set('confirm_password', 'wrong-password')
        ->call('unlockSensitive')
        ->assertHasErrors(['confirm_password'])
        ->assertNotDispatched('start-two-factor-setup')
        ->set('confirm_password', 'password')
        ->call('unlockSensitive')
        ->assertHasNoErrors()
        ->assertSet('pendingIntent', '')
        ->assertDispatched('start-two-factor-setup');

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

test('once confirmed, sensitive actions run without asking again', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    session(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security')
        ->call('requestUnlock', 'enable2fa')
        ->assertSet('pendingIntent', '')
        ->assertDispatched('start-two-factor-setup');
});

test('disabling two factor asks for the password and then disables', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $this->actingAs($user);

    Livewire::test('pages::settings.security')
        ->call('requestUnlock', 'disable2fa')
        ->assertSet('twoFactorEnabled', true)
        ->set('confirm_password', 'password')
        ->call('unlockSensitive')
        ->assertSet('twoFactorEnabled', false);

    expect($user->refresh()->two_factor_secret)->toBeNull();
});

test('sensitive actions are refused until the password is confirmed', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $this->actingAs($user);

    Livewire::test('pages::settings.security')->call('disable')->assertForbidden();
    Livewire::test('pages::settings.two-factor.recovery-codes')->call('viewRecoveryCodes')->assertForbidden();

    expect($user->refresh()->two_factor_secret)->not->toBeNull();
});

test('two factor setup is refused until the password is confirmed', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
        ->call('startTwoFactorSetup')
        ->assertForbidden();
});

test('security settings page renders without two factor when feature is disabled', function () {
    config(['fortify.features' => []]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee(__('Update password'))
        ->assertDontSee(__('Manage your passkeys for passwordless sign-in'))
        ->assertDontSee(__('Add a passkey to sign in without a password'))
        ->assertDontSee(__('Two-factor authentication'));
});

test('two factor authentication disabled when confirmation abandoned between requests', function () {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($user);

    $component = Livewire::test('pages::settings.security');

    $component->assertSet('twoFactorEnabled', false);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
    ]);
});

test('two factor setup does not reveal the secret when already enabled', function () {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user);

    Livewire::test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
        ->call('startTwoFactorSetup')
        ->assertDispatched('two-factor-enabled')
        ->assertSet('manualSetupKey', '')
        ->assertSet('qrCodeSvg', '');

    expect(decrypt($user->refresh()->two_factor_secret))->toBe('test-secret');
});

test('recovery codes are only loaded when requested', function () {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user);
    session(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.two-factor.recovery-codes')
        ->assertSet('recoveryCodes', [])
        ->call('viewRecoveryCodes')
        ->assertSet('recoveryCodes', ['code1', 'code2']);
});

test('password can be updated', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.security')
        ->set('current_password', 'password')
        ->set('password', 'Nova-senha-123!')
        ->set('password_confirmation', 'Nova-senha-123!')
        ->call('updatePassword');

    $response->assertHasNoErrors();

    expect(Hash::check('Nova-senha-123!', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.security')
        ->set('current_password', 'wrong-password')
        ->set('password', 'Nova-senha-123!')
        ->set('password_confirmation', 'Nova-senha-123!')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});
