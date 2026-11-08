<?php

namespace App\Services\Auctions;

use App\Models\AuctionImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Derived sizes of a listing photo (R-42 security and performance, audit F-14).
 *
 * The uploaded file is kept untouched on the private disk as the source, but it is never what a browser receives: a
 * phone photo is 3 to 8 MB and carries the GPS position of the seller's home in its EXIF block. Every response is a
 * re-encoded copy, so:
 *
 *   thumb   480 px on the long edge   lot cards, lists, gallery thumbnails
 *   medium 1200 px                    the gallery's main picture
 *   large  2000 px                    "open the photo" and the default when no size is asked for
 *
 * Copies are WebP where this PHP's GD can write it (about 30 percent smaller than JPEG at the same look), JPEG
 * otherwise, with transparency flattened onto white for JPEG. Re-encoding drops every metadata block; the EXIF
 * orientation is applied first, so a portrait phone photo does not turn sideways when its tag is gone. A photo is never
 * enlarged. They are written next to the source on the private disk (`{auction}/{uuid}-{size}.{ext}`) when the photo is
 * uploaded, and generated on first request for photos uploaded before this existed or whose generation failed, so a
 * missing copy is a delay, never an error. If a copy cannot be made at all (no GD, no memory for a very large image)
 * the caller falls back to the source file.
 */
class AuctionImageVariants
{
    /** Size name => longest edge in pixels. */
    public const SIZES = ['thumb' => 480, 'medium' => 1200, 'large' => 2000];

    public const DEFAULT_SIZE = 'large';

    /** @return string|null a known size name, or null when the name is not one */
    public static function normalize(?string $size): ?string
    {
        if ($size === null || $size === '') {
            return self::DEFAULT_SIZE;
        }

        return isset(self::SIZES[$size]) ? $size : null;
    }

    public static function usesWebp(): bool
    {
        return function_exists('imagewebp');
    }

    /** @return array{0: string, 1: string} file extension and mime type of the copies */
    public static function format(): array
    {
        return self::usesWebp() ? ['webp', 'image/webp'] : ['jpg', 'image/jpeg'];
    }

    /** The disk path a copy of this source lives at (whether or not it exists yet). */
    public static function path(string $sourcePath, string $size): string
    {
        return preg_replace('/\.[A-Za-z0-9]+$/', '', $sourcePath).'-'.$size.'.'.self::format()[0];
    }

    /**
     * Every path a copy could ever have had, for cleaning up after a photo is removed (a deployment that switched between
     * WebP and JPEG must not leave orphans behind).
     *
     * @return array<int, string>
     */
    public static function allPaths(string $sourcePath): array
    {
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $sourcePath);
        $paths = [];

        foreach (array_keys(self::SIZES) as $size) {
            foreach (['webp', 'jpg'] as $extension) {
                $paths[] = "{$base}-{$size}.{$extension}";
            }
        }

        return $paths;
    }

    /**
     * The disk path of the requested copy, made now if it does not exist. Null when it cannot be made.
     */
    public function ensure(AuctionImage $image, string $size): ?string
    {
        $disk = Storage::disk(AuctionImage::DISK);
        $path = self::path($image->path, $size);

        if ($disk->exists($path)) {
            return $path;
        }

        try {
            return $this->generate($image, [$size])[$size] ?? null;
        } catch (Throwable $e) {
            Log::warning('Could not make a smaller copy of a listing photo.', ['image_id' => $image->getKey(), 'size' => $size, 'error' => class_basename($e).': '.$e->getMessage()]);

            return null;
        }
    }

    /**
     * Make the copies (all sizes by default) from one decode of the source. Never throws for an unreadable image: the
     * upload that calls this has already been validated, and a failure here only means the copies are made later.
     *
     * @param  array<int, string>|null  $sizes
     * @return array<string, string> size => disk path, for the copies that now exist
     */
    public function generate(AuctionImage $image, ?array $sizes = null): array
    {
        if (! extension_loaded('gd')) {
            return [];
        }

        $disk = Storage::disk(AuctionImage::DISK);
        $sizes ??= array_keys(self::SIZES);
        $bytes = $disk->get($image->path);

        if (! is_string($bytes) || $bytes === '') {
            return [];
        }

        $this->makeRoomFor($image);

        $source = @imagecreatefromstring($bytes);
        unset($bytes);

        if ($source === false) {
            return [];
        }

        $source = $this->orient($source, $image);
        $made = [];

        try {
            foreach ($sizes as $size) {
                $encoded = $this->encode($source, self::SIZES[$size]);

                if ($encoded === null) {
                    continue;
                }

                $path = self::path($image->path, $size);
                $temporary = $path.'.'.Str::random(8).'.tmp';

                // Written under a temporary name and moved into place, so a reader never sees half a file.
                if ($disk->put($temporary, $encoded) && $disk->move($temporary, $path)) {
                    $made[$size] = $path;
                } else {
                    $disk->delete($temporary);
                }
            }
        } finally {
            unset($source);
        }

        return $made;
    }

    /** Remove every copy of a photo (the source is removed by whoever owns the row). */
    public function forget(string $sourcePath): void
    {
        Storage::disk(AuctionImage::DISK)->delete(self::allPaths($sourcePath));
    }

    /**
     * A photo is validated to at most 16 megapixels (about 64 MB decoded). PHP's default 128 MB limit is not always
     * enough next to the framework, so the limit is raised for this request when it is lower than that needs.
     */
    private function makeRoomFor(AuctionImage $image): void
    {
        $needed = (int) ((($image->width ?: 4000) * ($image->height ?: 3000)) * 5.5) + 48 * 1024 * 1024;
        $limit = $this->memoryLimitBytes();

        if ($limit !== -1 && $limit < memory_get_usage(true) + $needed) {
            @ini_set('memory_limit', (string) ($needed + memory_get_usage(true) + 32 * 1024 * 1024));
        }
    }

    private function memoryLimitBytes(): int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Apply the EXIF orientation of a JPEG so the pixels are upright once the tag is gone.
     */
    private function orient(\GdImage $image, AuctionImage $row): \GdImage
    {
        if ($row->mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $path = Storage::disk(AuctionImage::DISK)->path($row->path);
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated instanceof \GdImage) {
            return $rotated;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    /** The image scaled to fit `$edge` pixels on its longer side (never enlarged), as WebP or JPEG bytes. */
    private function encode(\GdImage $source, int $edge): ?string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, $edge / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($canvas === false) {
            return null;
        }

        if (self::usesWebp()) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            imagealphablending($canvas, true);
        } else {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        }

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $ok = self::usesWebp()
            ? imagewebp($canvas, null, $edge <= 480 ? 78 : 82)
            : imagejpeg($canvas, null, $edge <= 480 ? 78 : 84);
        $bytes = (string) ob_get_clean();
        unset($canvas);

        return $ok && $bytes !== '' ? $bytes : null;
    }
}
