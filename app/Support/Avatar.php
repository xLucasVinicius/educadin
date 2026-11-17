<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Foto de perfil: valida a imagem enviada, recorta em quadrado, reduz para
 * 256x256 e guarda só essa versão (JPEG) no disco privado.
 */
class Avatar
{
    public const SIZE = 256;

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Converte uma imagem em data URL ("data:image/jpeg;base64,...") para bytes
     * JPEG já tratados, ou null se não for uma imagem válida.
     */
    public static function fromDataUrl(string $dataUrl): ?string
    {
        if (! preg_match('/^data:image\/(?:jpeg|png|webp);base64,([A-Za-z0-9+\/]+={0,2})$/', $dataUrl, $match)) {
            return null;
        }

        $bytes = base64_decode($match[1], true);

        return $bytes === false ? null : self::process($bytes);
    }

    /**
     * Recorta no centro, reduz e recodifica. A recodificação descarta metadados
     * e qualquer conteúdo que não seja a imagem em si.
     */
    public static function process(string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring') || strlen($bytes) > 3_000_000) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! in_array($info['mime'], self::MIMES, true)) {
            return null;
        }

        [$width, $height] = $info;

        if ($width < 32 || $height < 32 || $width > 4096 || $height > 4096) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);

        if (! $source) {
            return null;
        }

        $side = min($width, $height);
        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled(
            $target, $source, 0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            self::SIZE, self::SIZE, $side, $side,
        );

        ob_start();
        imagejpeg($target, null, 88);
        $jpeg = ob_get_clean();

        return $jpeg ?: null;
    }

    public static function store(User $user, string $jpeg): void
    {
        $path = 'avatars/'.$user->id.'-'.Str::random(16).'.jpg';
        $old = $user->avatar_path;

        Storage::disk('local')->put($path, $jpeg);
        $user->forceFill(['avatar_path' => $path])->save();

        if ($old) {
            Storage::disk('local')->delete($old);
        }
    }

    public static function remove(User $user): void
    {
        $old = $user->avatar_path;

        $user->forceFill(['avatar_path' => null])->save();

        if ($old) {
            Storage::disk('local')->delete($old);
        }
    }
}
