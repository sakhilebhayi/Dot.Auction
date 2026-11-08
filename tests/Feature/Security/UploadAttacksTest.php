<?php

namespace Tests\Feature\Security;

use App\Models\Auction;
use App\Models\AuctionImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Hostile files sent to the photo upload (POST seller/auctions/{id}/images), by their content: what is refused, what is
 * accepted but made harmless, and where anything that is kept ends up.
 */
class UploadAttacksTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Auction $lot;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(AuctionImage::DISK);
        Storage::fake('public');
        $this->seller = User::factory()->withPersonalTeam()->create();
        $this->lot = Auction::factory()->draft()->create(['seller_id' => $this->seller->id]);
    }

    private function send(string $bytes, string $name = 'photo.jpg', string $mime = 'image/jpeg')
    {
        $path = sys_get_temp_dir().'/'.uniqid('up', true);
        file_put_contents($path, $bytes);

        return $this->actingAs($this->seller)->postJson(route('seller.auctions.images.store', $this->lot), ['images' => [new UploadedFile($path, $name, $mime, null, true)]]);
    }

    private function jpeg(): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(120, 90), null, 80);

        return (string) ob_get_clean();
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function refusedFiles(): array
    {
        return [
            'svg with a script' => ['<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>', 'x.svg', 'image/svg+xml'],
            'svg named as a jpg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.jpg', 'image/jpeg'],
            'html named as a jpg' => ['<html><body><script>alert(1)</script></body></html>', 'x.jpg', 'image/jpeg'],
            'php named as a png' => ['<?php system($_GET["c"]); ?>', 'shell.php.png', 'image/png'],
            'a gif (not an accepted type)' => ["GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;", 'x.gif', 'image/gif'],
            'a bmp' => ['BM'.str_repeat("\0", 60), 'x.bmp', 'image/bmp'],
            'an empty file' => ['', 'x.jpg', 'image/jpeg'],
            'a jpeg header and nothing else' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF\x00", 'x.jpg', 'image/jpeg'],
            'a pdf' => ["%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF", 'x.jpg', 'image/jpeg'],
            'a zip' => ["PK\x03\x04".str_repeat("\0", 40), 'x.png', 'image/png'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedFiles')]
    public function test_hostile_files_are_refused_and_nothing_is_stored(string $bytes, string $name, string $mime): void
    {
        $this->send($bytes, $name, $mime)->assertStatus(422);

        $this->assertSame(0, AuctionImage::count());
        $this->assertSame([], Storage::disk(AuctionImage::DISK)->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_png_that_claims_a_huge_canvas_is_refused_before_it_is_decoded(): void
    {
        // 40,000 x 40,000 px declared in a ~70 byte file: decoding it would need 6.4 GB.
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 40000, 40000, 8, 2, 0, 0, 0).pack('N', crc32('IHDR'.pack('NNCCCCC', 40000, 40000, 8, 2, 0, 0, 0)))
            .pack('N', 0).'IEND'.pack('N', crc32('IEND'));

        $start = microtime(true);
        $this->send($png, 'bomb.png', 'image/png')->assertStatus(422);

        $this->assertLessThan(2.0, microtime(true) - $start, 'refused on the header, without a decode');
        $this->assertSame(0, AuctionImage::count());
    }

    public function test_a_jpeg_with_a_php_script_appended_is_kept_only_as_a_re_encoded_picture(): void
    {
        $polyglot = $this->jpeg().'<?php system($_GET["c"]); ?>';

        $this->send($polyglot, 'cat.php.jpg')->assertOk();

        $image = AuctionImage::firstOrFail();
        $disk = Storage::disk(AuctionImage::DISK);

        // Stored under a server-made name inside the auction's folder; the client's name is only metadata and is never a path.
        $this->assertMatchesRegularExpression('#^'.$this->lot->id.'/[0-9a-f-]{36}\.jpg$#', $image->path);
        $this->assertSame([], Storage::disk('public')->allFiles());

        // What a visitor can ever download is a fresh encoding with no appended bytes.
        $this->actingAs(User::factory()->withPersonalTeam()->create());
        $this->actingAs($this->seller);
        foreach ([null, 'thumb', 'medium', 'large'] as $size) {
            $body = $this->get($image->url($size))->assertOk()->streamedContent();
            $this->assertStringNotContainsString('<?php', $body);
            $this->assertStringNotContainsString('system(', $body);
        }
        $this->assertTrue($disk->exists($image->path));
    }

    public function test_a_hostile_file_name_never_reaches_the_disk_or_a_page(): void
    {
        $name = '../../../../public/<script>alert(1)</script>"onerror=1.jpg';

        $this->send($this->jpeg(), $name)->assertOk();

        $image = AuctionImage::firstOrFail();
        $this->assertStringNotContainsString('..', $image->path);
        $this->assertStringNotContainsString('script', $image->path);
        $this->assertSame([$image->path], array_values(array_filter(Storage::disk(AuctionImage::DISK)->allFiles(), fn ($f) => ! preg_match('/-(thumb|medium|large)\./', $f))));

        $html = $this->actingAs($this->seller)->get(route('seller.auctions.edit', $this->lot))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>"onerror', $html);
        $this->assertStringNotContainsString('onerror=1', $html);
    }

    public function test_a_client_that_lies_about_the_type_gets_the_real_type_recorded(): void
    {
        $this->send($this->jpeg(), 'looks-like.png', 'image/png')->assertOk();

        $this->assertSame('image/jpeg', AuctionImage::firstOrFail()->mime);
        $this->assertStringEndsWith('.jpg', AuctionImage::firstOrFail()->path);
    }

    public function test_the_temporary_upload_the_profile_photo_uses_is_limited_to_a_small_picture(): void
    {
        $this->assertSame(['required', 'file', 'mimes:jpg,jpeg,png', 'max:1024'], config('livewire.temporary_file_upload.rules'));
        $this->assertStringContainsString('throttle', (string) json_encode(config('livewire.temporary_file_upload.middleware')));
    }
}
