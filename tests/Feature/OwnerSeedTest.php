<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

test('the seeder creates the fixed owner profile as user 1', function () {
    config(['educadin.owner' => ['name' => 'Dono Teste', 'email' => 'dono@example.com', 'password' => 'segredo-de-teste']]);

    $this->seed(DatabaseSeeder::class);

    $owner = User::find(1);

    expect($owner->email)->toBe('dono@example.com')
        ->and($owner->name)->toBe('Dono Teste')
        ->and(Hash::check('segredo-de-teste', $owner->password))->toBeTrue()
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and($owner->isPremium())->toBeTrue()
        ->and(User::where('email', 'test@example.com')->value('id'))->toBeGreaterThan(1);
});

test('the owner email is treated as owner of the system', function () {
    config(['educadin.owner_emails' => ['dono@example.com']]);
    $owner = User::factory()->create(['email' => 'dono@example.com']);

    expect($owner->isOwner())->toBeTrue()->and($owner->isAdmin())->toBeTrue();
});

test('without owner data in the env the seeder skips the owner', function () {
    config(['educadin.owner' => ['name' => 'Dono', 'email' => null, 'password' => null]]);

    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'test@example.com')->value('id'))->toBe(1);
});

test('an existing user 1 with another email is never overwritten', function () {
    $other = User::factory()->create(['email' => 'outro@example.com']);
    config(['educadin.owner' => ['name' => 'Dono', 'email' => 'dono@example.com', 'password' => 'segredo-de-teste']]);

    $this->seed(DatabaseSeeder::class);

    expect(User::find(1)->email)->toBe('outro@example.com')
        ->and(User::where('email', 'dono@example.com')->exists())->toBeFalse();
});
