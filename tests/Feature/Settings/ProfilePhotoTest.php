<?php

use App\Models\User;
use App\Support\Avatar;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function photoDataUrl(int $width = 300, int $height = 200, string $format = 'png'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));

    ob_start();
    $format === 'jpeg' ? imagejpeg($image) : imagepng($image);
    $bytes = ob_get_clean();

    return 'data:image/'.$format.';base64,'.base64_encode($bytes);
}

beforeEach(function () {
    Storage::fake('local');
});

test('a new user has the default photo', function () {
    $user = User::factory()->create();

    expect($user->avatar_path)->toBeNull()
        ->and($user->avatarUrl())->toBeNull();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    $this->actingAs($user)->get(route('profile.photo'))->assertNotFound();
});

test('a photo can be saved as a 256x256 jpeg', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('croppedPhoto', photoDataUrl())
        ->call('savePhoto')
        ->assertHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();
    Storage::disk('local')->assertExists($user->avatar_path);
    $info = getimagesizefromstring(Storage::disk('local')->get($user->avatar_path));

    expect($info[0])->toBe(Avatar::SIZE)
        ->and($info[1])->toBe(Avatar::SIZE)
        ->and($info['mime'])->toBe('image/jpeg')
        ->and($user->avatarUrl())->toContain('profile/photo');
})->skip(! function_exists('imagecreatetruecolor'), 'GD não está instalado.');

test('replacing the photo deletes the old file', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')->set('croppedPhoto', photoDataUrl())->call('savePhoto');
    $first = $user->refresh()->avatar_path;

    Livewire::test('pages::settings.profile')->set('croppedPhoto', photoDataUrl(200, 300, 'jpeg'))->call('savePhoto');
    $second = $user->refresh()->avatar_path;

    expect($second)->not->toBe($first);
    Storage::disk('local')->assertMissing($first);
    Storage::disk('local')->assertExists($second);
})->skip(! function_exists('imagecreatetruecolor'), 'GD não está instalado.');

test('the photo can be removed to go back to the default', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')->set('croppedPhoto', photoDataUrl())->call('savePhoto');
    $path = $user->refresh()->avatar_path;

    Livewire::test('pages::settings.profile')->call('removePhoto')->assertRedirect(route('profile.edit'));

    expect($user->refresh()->avatar_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
})->skip(! function_exists('imagecreatetruecolor'), 'GD não está instalado.');

test('invalid files are rejected', function (string $value) {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('croppedPhoto', $value)
        ->call('savePhoto')
        ->assertHasErrors(['croppedPhoto']);

    expect($user->refresh()->avatar_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'empty' => '',
    'plain text' => 'ola',
    'not an image disguised as png' => 'data:image/png;base64,'.base64_encode('isto nao e uma imagem'),
    'gif is not allowed' => 'data:image/gif;base64,R0lGODlhAQABAAAAACw=',
    'svg is not allowed' => 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
]);

test('tiny images are rejected', function () {
    expect(Avatar::fromDataUrl(photoDataUrl(10, 10)))->toBeNull();
})->skip(! function_exists('imagecreatetruecolor'), 'GD não está instalado.');

test('each user only receives their own photo', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($owner);
    Livewire::test('pages::settings.profile')->set('croppedPhoto', photoDataUrl())->call('savePhoto');

    $this->actingAs($owner->refresh())->get(route('profile.photo'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->actingAs($other)->get(route('profile.photo'))->assertNotFound();
})->skip(! function_exists('imagecreatetruecolor'), 'GD não está instalado.');

test('guests cannot get a profile photo', function () {
    $this->get(route('profile.photo'))->assertRedirect(route('login'));
});

test('the photo shows up in the sidebar menu', function () {
    $user = User::factory()->create(['avatar_path' => 'avatars/x.jpg']);

    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('profile/photo', false);
});
