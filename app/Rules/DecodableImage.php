<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Proves an upload is a real JPEG, PNG or WebP bitmap, not just a file with
 * an image-looking name or MIME header: the content type is sniffed from
 * the bytes, the declared dimensions are bounded, and the whole file is
 * actually decoded. A truncated or hand-crafted file that merely starts
 * with image magic bytes fails the decode.
 *
 * Fails closed: if this PHP's GD cannot decode a type, that type is refused
 * rather than waved through unchecked.
 */
class DecodableImage implements ValidationRule
{
    /** @var array<int, array{mime: string, extension: string, decoder: string}> */
    private const TYPES = [
        IMAGETYPE_JPEG => ['mime' => 'image/jpeg', 'extension' => 'jpg', 'decoder' => 'imagecreatefromjpeg'],
        IMAGETYPE_PNG => ['mime' => 'image/png', 'extension' => 'png', 'decoder' => 'imagecreatefrompng'],
        IMAGETYPE_WEBP => ['mime' => 'image/webp', 'extension' => 'webp', 'decoder' => 'imagecreatefromwebp'],
    ];

    /**
     * What an accepted file really is, or null when it is not an acceptable
     * decodable image.
     *
     * @return array{mime: string, extension: string, width: int, height: int}|null
     */
    public static function inspect(UploadedFile $file): ?array
    {
        $path = $file->getRealPath();

        if ($path === false || ! extension_loaded('gd')) {
            return null;
        }

        $info = @getimagesize($path);

        if ($info === false || ! isset(self::TYPES[$info[2]])) {
            return null;
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1 || $width * $height > (int) config('auction.images.max_pixels', 16000000)) {
            return null;
        }

        $type = self::TYPES[$info[2]];

        if (! function_exists($type['decoder'])) {
            return null;
        }

        // GD silently paints grey over whatever a truncated JPEG is missing
        // and reports success, so completeness is checked structurally too.
        if ($info[2] === IMAGETYPE_JPEG && ! self::jpegIsComplete($path)) {
            return null;
        }

        $bitmap = @($type['decoder'])($path);

        if ($bitmap === false) {
            return null;
        }

        unset($bitmap);

        return ['mime' => $type['mime'], 'extension' => $type['extension'], 'width' => $width, 'height' => $height];
    }

    /**
     * Walks the JPEG marker structure to the end-of-image marker. Anything
     * after EOI (an appended motion-photo video, say) is ignored; a file
     * that runs out before EOI was cut short.
     */
    private static function jpegIsComplete(string $path): bool
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false || strlen($bytes) < 4 || ! str_starts_with($bytes, "\xFF\xD8")) {
            return false;
        }

        $length = strlen($bytes);
        $i = 2;

        while ($i < $length) {
            while ($i < $length && $bytes[$i] === "\xFF") {
                $i++;
            }

            if ($i >= $length) {
                return false;
            }

            $marker = ord($bytes[$i]);
            $i++;

            if ($marker === 0xD9) {
                return true;
            }

            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8)) {
                continue;
            }

            if ($i + 2 > $length) {
                return false;
            }

            $segment = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);

            if ($segment < 2) {
                return false;
            }

            $i += $segment;

            if ($marker !== 0xDA) {
                continue;
            }

            // Entropy-coded scan data: FF is always stuffed (FF00) or a
            // restart marker until the next real marker begins.
            while ($i < $length) {
                $next = strpos($bytes, "\xFF", $i);

                if ($next === false || $next + 1 >= $length) {
                    return false;
                }

                $following = ord($bytes[$next + 1]);

                if ($following === 0x00 || ($following >= 0xD0 && $following <= 0xD7)) {
                    $i = $next + 2;

                    continue;
                }

                $i = $next;

                break;
            }
        }

        return false;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The :attribute could not be uploaded. Try again.');

            return;
        }

        if (self::inspect($value) === null) {
            $max = number_format(((int) config('auction.images.max_pixels', 16000000)) / 1000000, 0);
            $fail("The :attribute must be a real JPG, PNG or WebP photo that opens correctly, up to {$max} megapixels.");
        }
    }
}
