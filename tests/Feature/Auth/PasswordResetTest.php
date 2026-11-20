<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

test('the forgot password page renders', function () {
    $this->get(route('password.request'))->assertOk();
});

test('a reset link is emailed to an existing account', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
});

test('no link is sent for an unknown email', function () {
    Notification::fake();

    $this->post(route('password.email'), ['email' => 'ninguem@example.com']);

    Notification::assertNothingSent();
});

test('the reset page renders with a valid token link', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk();
});

test('the password can be reset with a valid token', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Nova-senha-123!',
        'password_confirmation' => 'Nova-senha-123!',
    ])->assertRedirect(route('login'));

    expect(Hash::check('Nova-senha-123!', $user->fresh()->password))->toBeTrue();
});

test('an invalid token does not change the password', function () {
    $user = User::factory()->create();

    $this->post(route('password.update'), [
        'token' => 'token-invalido',
        'email' => $user->email,
        'password' => 'Nova-senha-123!',
        'password_confirmation' => 'Nova-senha-123!',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('the reset email is written in portuguese', function () {
    $user = User::factory()->create();
    $mail = (new ResetPassword('token'))->toMail($user);
    $text = collect([$mail->subject, ...$mail->introLines, $mail->actionText, ...$mail->outroLines])->implode(' ');

    expect($text)->toContain('Redefinição de senha')->and($text)->toContain('Redefinir senha');
});

test('an admin can send a reset link to a regular user', function () {
    Notification::fake();
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();
    $user = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test('pages::admin.index')->call('sendPasswordReset', $user->id);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('an admin cannot send a reset link to the owner', function () {
    Notification::fake();
    $owner = User::factory()->create(['email' => 'dono@example.com']);
    config(['educadin.owner_emails' => ['dono@example.com']]);
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();
    $this->actingAs($admin);

    Livewire::test('pages::admin.index')->call('sendPasswordReset', $owner->id)->assertForbidden();

    Notification::assertNothingSent();
});
