<?php

namespace Tests\Feature\Security;

use App\Models\Auction;
use App\Models\AuctionImage;
use App\Models\User;
use App\Services\Auctions\AuctionImageManager;
use App\Services\Auctions\AuctionImageVariants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Photos are served as small, metadata-free copies (audit F-14 and the GPS-in-EXIF note in the deployment guide).
 */
class ImageVariantsTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'GPS-MARKER-HOME-ADDRESS-12345';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(AuctionImage::DISK);
    }

    /** A noisy (so it does not compress to nothing) JPEG of the given size, optionally with an EXIF block. */
    private function jpeg(int $width, int $height, ?int $orientation = null, bool $leftRed = false): string
    {
        $im = imagecreatetruecolor($width, $height);
        for ($i = 0; $i < 400; $i++) {
            imagefilledrectangle($im, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        if ($leftRed) {
            imagefilledrectangle($im, 0, 0, intdiv($width, 2) - 1, $height, imagecolorallocate($im, 255, 0, 0));
            imagefilledrectangle($im, intdiv($width, 2), 0, $width, $height, imagecolorallocate($im, 0, 0, 255));
        }
        ob_start();
        imagejpeg($im, null, 90);
        $bytes = (string) ob_get_clean();

        return $orientation === null ? $bytes : $this->withExif($bytes, $orientation);
    }

    private function withExif(string $jpeg, int $orientation): string
    {
        $description = self::MARKER."\0";
        $dataOffset = 8 + 2 + 2 * 12 + 4;
        $tiff = 'II'."\x2A\x00".pack('V', 8).pack('v', 2)
            .pack('vvVV', 0x010E, 2, strlen($description), $dataOffset)
            .pack('vvVV', 0x0112, 3, 1, $orientation)
            .pack('V', 0).$description;
        $app1 = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\0\0".$tiff;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    private function upload(Auction $auction, string $bytes, string $name = 'photo.jpg'): AuctionImage
    {
        $path = sys_get_temp_dir().'/'.uniqid('img', true).'-'.$name;
        file_put_contents($path, $bytes);

        return app(AuctionImageManager::class)->add($auction, [new UploadedFile($path, $name, 'image/jpeg', null, true)])[0];
    }

    private function viewer(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    public function test_a_phone_sized_photo_is_served_as_three_smaller_copies_and_the_default_is_not_the_original(): void
    {
        $auction = Auction::factory()->create();
        $image = $this->upload($auction, $this->jpeg(4000, 3000));
        $originalBytes = Storage::disk(AuctionImage::DISK)->size($image->path);
        $this->actingAs($this->viewer());

        $sizes = [];
        foreach (['thumb' => 480, 'medium' => 1200, null => 2000] as $size => $edge) {
            $url = route('auctions.images.show', array_filter([$auction, $image, 'size' => $size === '' ? null : $size]));
            $response = $this->get($url)->assertOk();
            $body = $response->streamedContent();
            [$w, $h] = getimagesizefromstring($body);

            $this->assertSame($edge, max($w, $h), 'size '.var_export($size, true));
            $this->assertLessThan($originalBytes, strlen($body));
            $sizes[(string) $size] = strlen($body);
        }

        $this->assertLessThan($sizes['medium'], $sizes['thumb']);
        $this->assertLessThan($originalBytes / 10, $sizes['thumb'], 'a 480 px thumbnail should weigh a tenth of a 4000 px photo or less');
    }

    public function test_a_small_photo_is_never_enlarged(): void
    {
        $auction = Auction::factory()->create();
        $image = $this->upload($auction, $this->jpeg(300, 200));
        $this->actingAs($this->viewer());

        [$w, $h] = getimagesizefromstring($this->get($image->url('large'))->assertOk()->streamedContent());

        $this->assertSame([300, 200], [$w, $h]);
    }

    public function test_exif_is_stripped_from_every_response_and_the_picture_is_turned_upright_first(): void
    {
        $auction = Auction::factory()->create();
        $source = $this->jpeg(600, 400, 6, true); // "rotate 90 degrees clockwise to view", left half red
        $this->assertStringContainsString(self::MARKER, $source);
        $image = $this->upload($auction, $source);
        $this->actingAs($this->viewer());

        foreach ([null, 'thumb', 'medium', 'large'] as $size) {
            $body = $this->get($image->url($size))->assertOk()->streamedContent();

            $this->assertStringNotContainsString(self::MARKER, $body, 'location or description metadata reached a viewer');
            $this->assertStringNotContainsString('Exif', substr($body, 0, 400));
            [$w, $h] = getimagesizefromstring($body);
            $this->assertLessThan($h, $w, 'the orientation tag was applied before the tag was dropped (portrait now)');
            $this->assertEqualsWithDelta(1.5, $h / $w, 0.02);
        }

        // Rotated clockwise, the red left half is now the top half.
        $im = imagecreatefromstring($this->get($image->url('medium'))->streamedContent());
        $top = imagecolorsforindex($im, imagecolorat($im, 200, 100));
        $bottom = imagecolorsforindex($im, imagecolorat($im, 200, 500));
        $this->assertGreaterThan(180, $top['red']);
        $this->assertGreaterThan(180, $bottom['blue']);
    }

    public function test_the_copy_is_webp_when_this_php_can_write_it(): void
    {
        $auction = Auction::factory()->create();
        $image = $this->upload($auction, $this->jpeg(800, 600));
        $this->actingAs($this->viewer());

        $response = $this->get($image->url('thumb'))->assertOk();
        $body = $response->streamedContent();

        [$extension, $mime] = AuctionImageVariants::format();
        $this->assertSame($mime, $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        if ($extension === 'webp') {
            $this->assertSame('RIFF', substr($body, 0, 4));
            $this->assertSame('WEBP', substr($body, 8, 4));
        }
    }

    public function test_the_response_is_privately_cacheable_with_an_etag_and_answers_304(): void
    {
        $auction = Auction::factory()->create();
        $image = $this->upload($auction, $this->jpeg(800, 600));
        $this->actingAs($this->viewer());

        $first = $this->get($image->url('thumb'))->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('private', (string) $first->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $first->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/max-age=\d{6,}/', (string) $first->headers->get('Cache-Control'), 'cacheable for days, not an hour');

        $again = $this->get($image->url('thumb'), ['If-None-Match' => $etag]);
        $again->assertStatus(304);
        $this->assertSame('', $again->getContent());

        // A different size has a different tag.
        $this->assertNotSame($etag, $this->get($image->url('medium'))->headers->get('ETag'));
        $this->get($image->url('thumb'), ['If-None-Match' => '"not-this-one"'])->assertOk();
    }

    public function test_the_access_rule_is_unchanged_for_every_size(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $draft = Auction::factory()->draft()->create(['seller_id' => $seller->id]);
        $image = $this->upload($draft, $this->jpeg(500, 400));

        $this->actingAs($this->viewer());
        foreach ([null, 'thumb', 'medium', 'large'] as $size) {
            $this->get($image->url($size))->assertNotFound();
        }

        $this->actingAs($seller)->get($image->url('thumb'))->assertOk();
        $this->asGuestRequest($image->url('thumb'))->assertRedirect(route('login'));
    }

    private function asGuestRequest(string $url)
    {
        $this->app['auth']->forgetGuards();

        return $this->get($url);
    }

    public function test_an_unknown_size_is_refused_not_guessed(): void
    {
        $auction = Auction::factory()->create();
        $image = $this->upload($auction, $this->jpeg(500, 400));
        $this->actingAs($this->viewer());
        $base = route('auctions.images.show', [$auction, $image]);

        foreach (['huge', '../../x', '0', 'THUMB', 'thumb%00', 'original'] as $size) {
            $this->get($base.'?size='.urlencode($size))->assertNotFound();
        }
        $this->get($base.'?size[]=thumb')->assertNotFound();
        $this->get($base.'?size=')->assertOk();
    }

    public function test_a_photo_uploaded_before_this_existed_gets_its_copies_on_first_request(): void
    {
        $auction = Auction::factory()->create();
        $image = $this->upload($auction, $this->jpeg(1600, 1200));
        $disk = Storage::disk(AuctionImage::DISK);
        $disk->delete(AuctionImageVariants::allPaths($image->path));
        $this->assertFalse($disk->exists(AuctionImageVariants::path($image->path, 'thumb')));
        $this->actingAs($this->viewer());

        $this->get($image->url('thumb'))->assertOk();

        $this->assertTrue($disk->exists(AuctionImageVariants::path($image->path, 'thumb')));
    }

    public function test_when_no_copy_can_be_made_the_stored_file_is_still_served(): void
    {
        $auction = Auction::factory()->create();
        $image = $this->upload($auction, $this->jpeg(500, 400));
        $this->actingAs($this->viewer());
        // Break the generator: the source bytes are not an image GD can decode (the upload was valid when accepted).
        Storage::disk(AuctionImage::DISK)->delete(AuctionImageVariants::allPaths($image->path));
        Storage::disk(AuctionImage::DISK)->put($image->path, 'not an image any more');

        $this->get($image->url('thumb'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_removing_a_photo_or_its_draft_removes_every_copy(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $draft = Auction::factory()->draft()->create(['seller_id' => $seller->id]);
        $one = $this->upload($draft, $this->jpeg(500, 400));
        $two = $this->upload($draft, $this->jpeg(500, 400));
        $disk = Storage::disk(AuctionImage::DISK);

        $this->assertTrue($disk->exists(AuctionImageVariants::path($one->path, 'thumb')));

        app(AuctionImageManager::class)->remove($draft, $one);
        $this->assertFalse($disk->exists($one->path));
        foreach (AuctionImageVariants::allPaths($one->path) as $path) {
            $this->assertFalse($disk->exists($path), "{$path} was left behind");
        }

        $draft->delete();
        foreach ([$two->path, ...AuctionImageVariants::allPaths($two->path)] as $path) {
            $this->assertFalse($disk->exists($path), "{$path} was left behind");
        }
    }

    public function test_the_auction_helper_builds_the_address_views_should_use(): void
    {
        $auction = Auction::factory()->create();
        $image = $this->upload($auction, $this->jpeg(500, 400));

        $this->assertSame(route('auctions.images.show', [$auction, $image, 'size' => 'thumb']), $auction->imageUrl($image, 'thumb'));
        $this->assertSame(route('auctions.images.show', [$auction, $image, 'size' => 'medium']), $auction->imageUrl($image, 'medium'));
        $this->assertSame(route('auctions.images.show', [$auction, $image]), $auction->imageUrl($image));
    }

    public function test_lot_cards_ask_for_the_thumbnail(): void
    {
        $auction = Auction::factory()->create();
        $image = $this->upload($auction, $this->jpeg(500, 400));
        $this->actingAs($this->viewer());

        $html = $this->get('/auctions')->assertOk()->getContent();

        $this->assertStringContainsString(e($auction->imageUrl($image, 'thumb')), $html);
    }
}
