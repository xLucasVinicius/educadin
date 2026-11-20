<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

function registerWith(string $password)
{
    return test()->post(route('register.store'), [
        'name' => 'Fulano',
        'email' => 'fulano@example.com',
        'password' => $password,
        'password_confirmation' => $password,
    ]);
}

test('a weak password is refused at registration', function (string $password) {
    registerWith($password)->assertSessionHasErrors('password');

    expect(User::where('email', 'fulano@example.com')->exists())->toBeFalse();
})->with([
    'too short' => 'Ab1!',
    'no uppercase' => 'senha-forte1!',
    'no lowercase' => 'SENHA-FORTE1!',
    'no number' => 'Senha-forte!!',
    'no special character' => 'SenhaForte123',
]);

test('a password that meets every requirement is accepted', function () {
    registerWith('Senha-Forte1!')->assertSessionHasNoErrors();

    expect(User::where('email', 'fulano@example.com')->exists())->toBeTrue();
});

test('a weak password is refused when resetting', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->post(route('password.update'), [
        'token' => $token, 'email' => $user->email,
        'password' => 'senhafraca', 'password_confirmation' => 'senhafraca',
    ])->assertSessionHasErrors('password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('the password pages list the requirements only under the password field', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('data-test="password-requirements"', false)
        ->assertSee(__('One uppercase letter'))
        ->assertSee(__('One special character (e.g. ! @ # $ %)'));

    expect(substr_count($this->get(route('register'))->getContent(), 'data-test="password-requirements"'))->toBe(1);
});
