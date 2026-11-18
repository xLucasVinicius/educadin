<?php

use App\Models\Account;
use App\Models\User;
use Livewire\Livewire;

function adminUser(array $attrs = []): User
{
    $user = User::factory()->create($attrs);
    $user->forceFill(['is_admin' => true])->save();

    return $user;
}

function ownerUser(): User
{
    $user = User::factory()->create(['email' => 'dono@example.com']);
    config(['educadin.owner_emails' => ['dono@example.com']]);

    return $user;
}

test('guests are sent to the login', function () {
    $this->get(route('admin.index'))->assertRedirect(route('login'));
});

test('regular users get a 404 and do not see the admin link', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.index'))->assertNotFound();
    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('data-test="admin-link"', false);
});

test('admins and owners can open the panel and see the link', function () {
    $this->actingAs(adminUser())->get(route('admin.index'))->assertOk();
    $this->actingAs(ownerUser())->get(route('admin.index'))->assertOk();
    $this->actingAs(adminUser())->get(route('dashboard'))->assertSee('data-test="admin-link"', false);
});

test('an owner e-mail only counts when it is verified', function () {
    $owner = User::factory()->unverified()->create(['email' => 'dono@example.com']);
    config(['educadin.owner_emails' => ['dono@example.com']]);

    expect($owner->isOwner())->toBeFalse()
        ->and($owner->isAdmin())->toBeFalse();
});

test('the owner list ignores case and spaces in the e-mails', function () {
    $owner = User::factory()->create(['email' => 'Dono@Example.com']);
    config(['educadin.owner_emails' => ['dono@example.com']]);

    expect($owner->isOwner())->toBeTrue();
});

test('the panel shows registration numbers and users', function () {
    $admin = adminUser(['name' => 'Chefe']);
    User::factory()->create(['name' => 'Maria Silva', 'email' => 'maria@example.com']);
    $this->actingAs($admin);

    Livewire::test('pages::admin.index')
        ->assertSee('Maria Silva')
        ->assertSee('maria@example.com')
        ->assertSee('Chefe');

    expect(Livewire::test('pages::admin.index')->instance()->stats['total'])->toBe(2);
});

test('the panel never shows financial data', function () {
    $admin = adminUser();
    $other = User::factory()->create();
    Account::factory()->for($other)->create(['name' => 'Conta Secreta']);
    $this->actingAs($admin);

    Livewire::test('pages::admin.index')->assertDontSee('Conta Secreta');
});

test('users can be searched by name or e-mail', function () {
    $admin = adminUser();
    User::factory()->create(['name' => 'Maria Silva', 'email' => 'maria@example.com']);
    User::factory()->create(['name' => 'João Souza', 'email' => 'joao@example.com']);
    $this->actingAs($admin);

    Livewire::test('pages::admin.index')
        ->set('search', 'maria')
        ->assertSee('Maria Silva')
        ->assertDontSee('João Souza')
        ->set('search', 'joao@')
        ->assertSee('João Souza')
        ->assertDontSee('Maria Silva');
});

test('suspending blocks the account and reactivating restores it', function () {
    $admin = adminUser();
    $user = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test('pages::admin.index')
        ->call('confirmSuspend', $user->id)
        ->call('suspend');

    expect($user->fresh()->isSuspended())->toBeTrue();

    Livewire::test('pages::admin.index')->call('reactivate', $user->id);

    expect($user->fresh()->isSuspended())->toBeFalse();
});

test('a suspended user is signed out when opening the app', function () {
    $user = User::factory()->create();
    $user->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('an admin cannot suspend or delete themselves', function () {
    $admin = adminUser();
    $this->actingAs($admin);

    Livewire::test('pages::admin.index')->call('confirmSuspend', $admin->id)->assertForbidden();
    Livewire::test('pages::admin.index')->call('confirmDelete', $admin->id)->assertForbidden();
});

test('an admin cannot manage owners or other admins', function () {
    $owner = ownerUser();
    $otherAdmin = adminUser();
    $this->actingAs(adminUser());

    Livewire::test('pages::admin.index')->call('confirmSuspend', $owner->id)->assertForbidden();
    Livewire::test('pages::admin.index')->call('confirmDelete', $otherAdmin->id)->assertForbidden();
});

test('an owner can manage admins but not other owners', function () {
    $owner = ownerUser();
    $admin = adminUser();
    $secondOwner = User::factory()->create(['email' => 'dono2@example.com']);
    config(['educadin.owner_emails' => ['dono@example.com', 'dono2@example.com']]);
    $this->actingAs($owner);

    Livewire::test('pages::admin.index')->call('confirmSuspend', $admin->id)->assertSet('targetId', $admin->id);
    Livewire::test('pages::admin.index')->call('confirmSuspend', $secondOwner->id)->assertForbidden();
});

test('deleting a user removes the account and its data', function () {
    $admin = adminUser();
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $this->actingAs($admin);

    Livewire::test('pages::admin.index')
        ->call('confirmDelete', $user->id)
        ->call('delete');

    expect(User::find($user->id))->toBeNull()
        ->and(Account::count())->toBe(0);
});

test('only owners can grant and remove admin access', function () {
    $owner = ownerUser();
    $user = User::factory()->create();

    $this->actingAs(adminUser());
    Livewire::test('pages::admin.index')->call('toggleAdmin', $user->id)->assertForbidden();
    expect($user->fresh()->is_admin)->toBeFalse();

    $this->actingAs($owner);
    Livewire::test('pages::admin.index')->call('toggleAdmin', $user->id);
    expect($user->fresh()->is_admin)->toBeTrue();

    Livewire::test('pages::admin.index')->call('toggleAdmin', $user->id);
    expect($user->fresh()->is_admin)->toBeFalse();
});

test('an owner cannot change their own access nor another owner', function () {
    $owner = ownerUser();
    $second = User::factory()->create(['email' => 'dono2@example.com']);
    config(['educadin.owner_emails' => ['dono@example.com', 'dono2@example.com']]);
    $this->actingAs($owner);

    Livewire::test('pages::admin.index')->call('toggleAdmin', $owner->id)->assertForbidden();
    Livewire::test('pages::admin.index')->call('toggleAdmin', $second->id)->assertForbidden();
});

test('a new admin can open the panel and a removed one cannot', function () {
    $owner = ownerUser();
    $user = User::factory()->create();

    $this->actingAs($owner);
    Livewire::test('pages::admin.index')->call('toggleAdmin', $user->id);

    $this->actingAs($user->fresh())->get(route('admin.index'))->assertOk();

    $this->actingAs($owner);
    Livewire::test('pages::admin.index')->call('toggleAdmin', $user->id);

    $this->actingAs($user->fresh())->get(route('admin.index'))->assertNotFound();
});

test('the plan can be set to premium with an expiration date', function () {
    $user = User::factory()->create();
    $this->actingAs(adminUser());

    Livewire::test('pages::admin.index')
        ->call('editPlan', $user->id)
        ->assertSet('plan', 'free')
        ->set('plan', 'premium')
        ->set('premiumUntil', now()->addMonth()->toDateString())
        ->call('savePlan')
        ->assertHasNoErrors();

    $user = $user->fresh();
    expect($user->plan)->toBe('premium')
        ->and($user->premium_until->toDateString())->toBe(now()->addMonth()->toDateString())
        ->and($user->isPremium())->toBeTrue();
});

test('premium without a date never expires and an expired date is not premium', function () {
    $user = User::factory()->create();
    $this->actingAs(adminUser());

    Livewire::test('pages::admin.index')
        ->call('editPlan', $user->id)
        ->set('plan', 'premium')
        ->set('premiumUntil', '')
        ->call('savePlan');

    expect($user->fresh()->isPremium())->toBeTrue()
        ->and($user->fresh()->premium_until)->toBeNull();

    $user->forceFill(['premium_until' => now()->subDay()->toDateString()])->save();

    expect($user->fresh()->isPremium())->toBeFalse();
});

test('going back to free clears the expiration date', function () {
    $user = User::factory()->create();
    $user->forceFill(['plan' => 'premium', 'premium_until' => now()->addMonth()->toDateString()])->save();
    $this->actingAs(adminUser());

    Livewire::test('pages::admin.index')
        ->call('editPlan', $user->id)
        ->assertSet('plan', 'premium')
        ->set('plan', 'free')
        ->call('savePlan');

    $user = $user->fresh();
    expect($user->plan)->toBe('free')
        ->and($user->premium_until)->toBeNull()
        ->and($user->isPremium())->toBeFalse();
});

test('invalid plans and dates are rejected', function () {
    $user = User::factory()->create();
    $this->actingAs(adminUser());

    Livewire::test('pages::admin.index')
        ->call('editPlan', $user->id)
        ->set('plan', 'gold')
        ->call('savePlan')
        ->assertHasErrors('plan')
        ->set('plan', 'premium')
        ->set('premiumUntil', '31/12/2026')
        ->call('savePlan')
        ->assertHasErrors('premiumUntil');
});

test('actions stop working when admin access is removed while the page is open', function () {
    $other = User::factory()->create();
    $admin = adminUser();
    $this->actingAs($admin);

    $component = Livewire::test('pages::admin.index');

    $admin->forceFill(['is_admin' => false])->save();

    $component->call('editPlan', $other->id)->assertNotFound();

    expect($other->fresh()->plan)->not->toBe('premium');
});

test('every user shows the plan badge: free, premium or expired', function () {
    $free = User::factory()->create(['name' => 'Usuario Free']);
    $premium = User::factory()->create(['name' => 'Usuario Premium']);
    $premium->forceFill(['plan' => 'premium', 'premium_until' => now()->addDays(5)->toDateString()])->save();
    $expired = User::factory()->create(['name' => 'Usuario Vencido']);
    $expired->forceFill(['plan' => 'premium', 'premium_until' => now()->subDay()->toDateString()])->save();
    $this->actingAs(adminUser());

    Livewire::test('pages::admin.index')
        ->assertSeeText(__('Free'))
        ->assertSeeText(__('Premium'))
        ->assertSeeText(__('Premium expired'));
});

test('the admin list shows the profile photo of users who have one', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    \Illuminate\Support\Facades\Storage::disk('local')->put('avatars/foto.jpg', 'conteudo');

    $admin = adminUser();
    $with = User::factory()->create(['avatar_path' => 'avatars/foto.jpg']);
    $without = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test('pages::admin.index')
        ->assertSee('data-test="admin-user-photo-'.$with->id.'"', false)
        ->assertDontSee('data-test="admin-user-photo-'.$without->id.'"', false);
});

test('admins can load a user photo, others cannot', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    \Illuminate\Support\Facades\Storage::disk('local')->put('avatars/foto.jpg', 'conteudo');

    $target = User::factory()->create(['avatar_path' => 'avatars/foto.jpg']);
    $noPhoto = User::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('admin.users.photo', $target))->assertNotFound();

    $this->actingAs(adminUser())
        ->get(route('admin.users.photo', $target))->assertOk();

    $this->actingAs(adminUser())
        ->get(route('admin.users.photo', $noPhoto))->assertNotFound();
});
