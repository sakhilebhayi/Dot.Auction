<?php

namespace Tests\Feature\Seller;

use App\Models\Auction;
use App\Models\AuctionImage;
use App\Models\Bid;
use App\Models\User;
use App\Services\Auctions\BidPlacer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuctionImageTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'auction-images';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(self::DISK);
        Storage::fake('public');
    }

    private function seller(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    private function auctionFor(User $seller, array $overrides = []): Auction
    {
        return Auction::factory()->create(array_merge(['seller_id' => $seller->id], $overrides));
    }

    private function upload(User $user, Auction $auction, array $files)
    {
        return $this->actingAs($user)
            ->from(route('seller.auctions.edit', $auction))
            ->post(route('seller.auctions.images.store', $auction), ['images' => $files]);
    }

    private function attach(Auction $auction, int $count = 1): array
    {
        $images = [];
        for ($i = 0; $i < $count; $i++) {
            $path = $auction->id.'/'.uniqid('img', true).'.jpg';
            Storage::disk(self::DISK)->put($path, UploadedFile::fake()->image('x.jpg', 40, 30)->getContent());
            $images[] = AuctionImage::create([
                'auction_id' => $auction->id, 'path' => $path, 'original_name' => 'x.jpg',
                'mime' => 'image/jpeg', 'size_bytes' => 100, 'width' => 40, 'height' => 30, 'position' => $i,
            ]);
        }

        return $images;
    }

    // ----------------------------------------------------------- configuration

    public function test_images_live_on_a_private_disk_that_is_not_web_served(): void
    {
        $disk = config('filesystems.disks.auction-images');

        $this->assertNotNull($disk, 'the auction-images disk must exist');
        $this->assertSame('local', $disk['driver']);
        $this->assertSame('private', $disk['visibility']);
        $this->assertFalse($disk['serve']);
        $this->assertStringStartsNotWith(public_path(), $disk['root']);
        $this->assertStringStartsNotWith(storage_path('app/public'), $disk['root']);
        $this->assertSame(6, config('auction.images.max_per_auction'));
        $this->assertSame(4096, config('auction.images.max_kilobytes'));
    }

    // --------------------------------------------------------------- uploading

    public function test_the_seller_uploads_jpg_png_and_webp_images(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);

        $this->upload($seller, $auction, [
            UploadedFile::fake()->image('front.jpg', 640, 480),
            UploadedFile::fake()->image('side.png', 300, 200),
            UploadedFile::fake()->image('back.webp', 200, 200),
        ])->assertSessionHasNoErrors();

        $images = $auction->images()->get();
        $this->assertCount(3, $images);
        $this->assertSame(['image/jpeg', 'image/png', 'image/webp'], $images->pluck('mime')->all());
        $this->assertSame([640, 300, 200], $images->pluck('width')->all());
        $this->assertSame([480, 200, 200], $images->pluck('height')->all());

        foreach ($images as $image) {
            Storage::disk(self::DISK)->assertExists($image->path);
            $this->assertStringStartsWith($auction->id.'/', $image->path);
            $this->assertSame('front.jpg', $images->first()->original_name);
        }
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing may land on the public disk');
    }

    public function test_the_stored_name_and_extension_come_from_the_content_not_the_client(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);
        $png = UploadedFile::fake()->image('actually-a-png.jpg', 50, 50); // GD writes jpeg for .jpg

        $this->upload($seller, $auction, [$png])->assertSessionHasNoErrors();

        $image = $auction->images()->sole();
        $this->assertMatchesRegularExpression('#^\d+/[0-9a-f-]{36}\.(jpg|png|webp)$#', $image->path);
        $this->assertStringNotContainsString('actually-a-png', $image->path);
    }

    public function test_six_images_are_allowed_and_a_seventh_is_refused(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);

        $this->upload($seller, $auction, array_map(fn ($i) => UploadedFile::fake()->image("a{$i}.jpg"), range(1, 6)))
            ->assertSessionHasNoErrors();
        $this->assertSame(6, $auction->images()->count());

        $this->upload($seller, $auction, [UploadedFile::fake()->image('seventh.jpg')])
            ->assertSessionHasErrors('images');

        $this->assertSame(6, $auction->images()->count());
        $this->assertCount(6, Storage::disk(self::DISK)->allFiles());
    }

    public function test_an_upload_that_would_pass_the_limit_is_refused_whole(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);
        $this->attach($auction, 4);

        $this->upload($seller, $auction, [
            UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg'),
        ])->assertSessionHasErrors('images');

        $this->assertSame(4, $auction->images()->count(), 'all-or-nothing: no partial save');
        $this->assertCount(4, Storage::disk(self::DISK)->allFiles());
    }

    public function test_seven_files_in_one_request_are_refused(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);

        $this->upload($seller, $auction, array_map(fn ($i) => UploadedFile::fake()->image("a{$i}.jpg"), range(1, 7)))
            ->assertSessionHasErrors('images');

        $this->assertSame(0, $auction->images()->count());
    }

    public function test_an_empty_submission_is_refused_with_a_message(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);

        $this->actingAs($seller)
            ->from(route('seller.auctions.edit', $auction))
            ->post(route('seller.auctions.images.store', $auction), [])
            ->assertSessionHasErrors('images');
    }

    /**
     * @return array<string, array{0: \Closure(): UploadedFile}>
     */
    public static function badFiles(): array
    {
        return [
            'a gif' => [fn () => UploadedFile::fake()->image('a.gif', 20, 20)],
            'a bmp' => [fn () => UploadedFile::fake()->image('a.bmp', 20, 20)],
            'an svg' => [fn () => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
            'a pdf' => [fn () => UploadedFile::fake()->create('a.pdf', 20, 'application/pdf')],
            'php source named .jpg' => [fn () => UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]);')],
            'jpeg header followed by junk' => [fn () => UploadedFile::fake()->createWithContent('junk.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF".str_repeat('A', 400))],
            'a truncated jpeg' => [function () {
                $whole = UploadedFile::fake()->image('whole.jpg', 400, 300)->getContent();

                return UploadedFile::fake()->createWithContent('cut.jpg', substr($whole, 0, intdiv(strlen($whole), 2)));
            }],
            'a truncated webp' => [function () {
                $whole = UploadedFile::fake()->image('whole.webp', 400, 300)->getContent();

                return UploadedFile::fake()->createWithContent('cut.webp', substr($whole, 0, intdiv(strlen($whole), 2)));
            }],
            'a truncated png' => [function () {
                $whole = UploadedFile::fake()->image('whole.png', 400, 300)->getContent();

                return UploadedFile::fake()->createWithContent('cut.png', substr($whole, 0, intdiv(strlen($whole), 2)));
            }],
            'a file over 4 MB' => [fn () => UploadedFile::fake()->image('big.jpg', 50, 50)->size(4097)],
        ];
    }

    /**
     * @param  \Closure(): UploadedFile  $make
     */
    #[DataProvider('badFiles')]
    public function test_files_that_are_not_real_jpg_png_or_webp_images_are_refused(\Closure $make): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);

        $this->upload($seller, $auction, [$make()])->assertSessionHasErrors('images.0');

        $this->assertSame(0, $auction->images()->count());
        $this->assertSame([], Storage::disk(self::DISK)->allFiles());
    }

    public function test_the_content_decides_not_the_file_name(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);
        // A real upload (not the fake File, which guesses its MIME type from the name).
        $tmp = tempnam(sys_get_temp_dir(), 'auc');
        file_put_contents($tmp, UploadedFile::fake()->image('p.png', 20, 20)->getContent());
        $png = new UploadedFile($tmp, 'holiday.txt', 'text/plain', null, true);

        $this->upload($seller, $auction, [$png])->assertSessionHasNoErrors();

        $image = $auction->images()->sole();
        $this->assertSame('image/png', $image->mime);
        $this->assertStringEndsWith('.png', $image->path);
    }

    public function test_a_real_non_image_with_an_image_name_is_refused_on_content(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);
        $tmp = tempnam(sys_get_temp_dir(), 'auc');
        file_put_contents($tmp, '<?php echo "owned";');
        $script = new UploadedFile($tmp, 'photo.jpg', 'image/jpeg', null, true); // client lies about type and name

        $this->upload($seller, $auction, [$script])->assertSessionHasErrors('images.0');

        $this->assertSame(0, $auction->images()->count());
    }

    public function test_a_jpeg_with_data_appended_after_its_end_marker_is_still_accepted(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);
        // Motion-photo style: a complete JPEG followed by an appended payload.
        $content = UploadedFile::fake()->image('p.jpg', 60, 40)->getContent().'ftypmp42'.str_repeat("\x00", 64);
        $file = UploadedFile::fake()->createWithContent('motion.jpg', $content);

        $this->upload($seller, $auction, [$file])->assertSessionHasNoErrors();

        $this->assertSame(1, $auction->images()->count());
    }

    public function test_a_file_of_exactly_the_size_limit_is_accepted(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);

        $this->upload($seller, $auction, [UploadedFile::fake()->image('edge.jpg', 50, 50)->size(4096)])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $auction->images()->count());
    }

    public function test_an_image_that_would_decode_to_too_many_pixels_is_refused(): void
    {
        config(['auction.images.max_pixels' => 1000]);
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);

        $this->upload($seller, $auction, [UploadedFile::fake()->image('wide.png', 100, 100)])
            ->assertSessionHasErrors('images.0');

        $this->assertSame(0, $auction->images()->count());
    }

    public function test_a_valid_file_alongside_an_invalid_one_saves_nothing(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);

        $this->upload($seller, $auction, [
            UploadedFile::fake()->image('ok.jpg'),
            UploadedFile::fake()->createWithContent('bad.jpg', '<?php'),
        ])->assertSessionHasErrors('images.1');

        $this->assertSame(0, $auction->images()->count());
        $this->assertSame([], Storage::disk(self::DISK)->allFiles());
    }

    public function test_photos_can_still_be_added_after_the_first_bid(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);
        app(BidPlacer::class)->place($auction, User::factory()->create(), '220.00');
        $this->assertSame(1, Bid::count());

        $this->upload($seller, $auction, [UploadedFile::fake()->image('more.jpg')])->assertSessionHasNoErrors();

        $this->assertSame(1, $auction->images()->count());
    }

    // ----------------------------------------------------------- authorization

    public function test_only_the_seller_can_add_or_remove_photos(): void
    {
        $owner = $this->seller();
        $stranger = $this->seller();
        $auction = $this->auctionFor($owner);
        [$image] = $this->attach($auction);

        $this->upload($stranger, $auction, [UploadedFile::fake()->image('x.jpg')])->assertForbidden();
        $this->actingAs($stranger)->delete(route('seller.auctions.images.destroy', [$auction, $image]))->assertForbidden();

        $this->assertSame(1, $auction->images()->count());
        Storage::disk(self::DISK)->assertExists($image->path);
    }

    public function test_guests_cannot_upload_or_view(): void
    {
        $auction = $this->auctionFor($this->seller());
        [$image] = $this->attach($auction);

        $this->post(route('seller.auctions.images.store', $auction), [])->assertRedirect('/login');
        $this->get(route('auctions.images.show', [$auction, $image]))->assertRedirect('/login');
    }

    public function test_photos_cannot_be_changed_on_a_finished_auction(): void
    {
        $seller = $this->seller();

        foreach (['ended', 'cancelled'] as $status) {
            $auction = $this->auctionFor($seller, ['status' => $status]);
            $this->upload($seller, $auction, [UploadedFile::fake()->image('x.jpg')])->assertForbidden();
        }
    }

    // ---------------------------------------------------------------- deleting

    public function test_removing_a_photo_deletes_the_row_and_the_file(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);
        [$keep, $drop] = $this->attach($auction, 2);

        $this->actingAs($seller)
            ->delete(route('seller.auctions.images.destroy', [$auction, $drop]))
            ->assertRedirect();

        $this->assertNull(AuctionImage::find($drop->id));
        Storage::disk(self::DISK)->assertMissing($drop->path);
        Storage::disk(self::DISK)->assertExists($keep->path);
    }

    public function test_a_photo_cannot_be_removed_through_someone_elses_auction_url(): void
    {
        $seller = $this->seller();
        $mine = $this->auctionFor($seller);
        $other = $this->auctionFor($this->seller());
        [$foreign] = $this->attach($other);

        $this->actingAs($seller)
            ->delete(route('seller.auctions.images.destroy', [$mine, $foreign]))
            ->assertNotFound();

        Storage::disk(self::DISK)->assertExists($foreign->path);
    }

    public function test_deleting_a_draft_removes_all_its_files(): void
    {
        $seller = $this->seller();
        $draft = Auction::factory()->draft()->create(['seller_id' => $seller->id]);
        $paths = collect($this->attach($draft, 3))->pluck('path');
        $keep = $this->attach($this->auctionFor($this->seller()))[0];

        $this->actingAs($seller)->delete(route('seller.auctions.destroy', $draft))->assertRedirect();

        foreach ($paths as $path) {
            Storage::disk(self::DISK)->assertMissing($path);
        }
        $this->assertSame(0, AuctionImage::where('auction_id', $draft->id)->count());
        Storage::disk(self::DISK)->assertExists($keep->path);
    }

    public function test_deleting_the_seller_account_removes_their_files(): void
    {
        $seller = $this->seller();
        $mine = $this->auctionFor($seller);
        $path = $this->attach($mine)[0]->path;
        $other = $this->attach($this->auctionFor($this->seller()))[0];

        $seller->delete();

        Storage::disk(self::DISK)->assertMissing($path);
        Storage::disk(self::DISK)->assertExists($other->path);
    }

    // ----------------------------------------------------------------- serving

    public function test_the_seller_and_other_signed_in_users_can_fetch_a_published_auctions_image(): void
    {
        $seller = $this->seller();
        $buyer = $this->seller();
        $auction = $this->auctionFor($seller);
        [$image] = $this->attach($auction);

        foreach ([$seller, $buyer] as $viewer) {
            $response = $this->actingAs($viewer)->get(route('auctions.images.show', [$auction, $image]))->assertOk();
            $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        }
    }

    public function test_a_drafts_images_are_visible_to_its_seller_only(): void
    {
        $seller = $this->seller();
        $draft = Auction::factory()->draft()->create(['seller_id' => $seller->id]);
        [$image] = $this->attach($draft);

        $this->actingAs($seller)->get(route('auctions.images.show', [$draft, $image]))->assertOk();
        $this->actingAs($this->seller())->get(route('auctions.images.show', [$draft, $image]))->assertForbidden();
    }

    public function test_an_image_is_only_served_under_its_own_auction(): void
    {
        $viewer = $this->seller();
        $a = $this->auctionFor($this->seller());
        $b = $this->auctionFor($this->seller());
        [$imageOfB] = $this->attach($b);

        $this->actingAs($viewer)->get(route('auctions.images.show', [$a, $imageOfB]))->assertNotFound();
    }

    public function test_a_missing_file_is_a_404_not_a_500(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);
        [$image] = $this->attach($auction);
        Storage::disk(self::DISK)->delete($image->path);

        $this->actingAs($seller)->get(route('auctions.images.show', [$auction, $image]))->assertNotFound();
    }

    // ---------------------------------------------------------------------- UI

    public function test_the_auction_page_shows_the_photos_through_the_policy_route_with_alt_text(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller, ['title' => 'Brass Telescope']);
        $images = $this->attach($auction, 2);

        $html = $this->actingAs($this->seller())->get(route('auctions.show', $auction))->assertOk()->getContent();

        foreach ($images as $image) {
            $this->assertStringContainsString(route('auctions.images.show', [$auction, $image]), $html);
        }
        $this->assertStringContainsString('alt="Brass Telescope, photo 1 of 2"', $html);
        $this->assertStringNotContainsString('/storage/', $html);
        $this->assertStringNotContainsString($images[0]->path, $html);
    }

    public function test_an_auction_without_photos_shows_no_broken_image(): void
    {
        $auction = $this->auctionFor($this->seller());

        $html = $this->actingAs($this->seller())->get(route('auctions.show', $auction))->assertOk()->getContent();

        $this->assertStringNotContainsString('<img class="dot-gallery-main"', $html);
    }

    public function test_the_edit_page_has_a_labelled_upload_control_and_removable_photos(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller, ['title' => 'Brass Telescope']);
        [$image] = $this->attach($auction);

        $html = $this->actingAs($seller)->get(route('seller.auctions.edit', $auction))->assertOk()->getContent();

        $this->assertStringContainsString('for="images"', $html);
        $this->assertStringContainsString('id="images"', $html);
        $this->assertStringContainsString('multiple', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('1 of 6', $html);
        $this->assertStringContainsString(route('seller.auctions.images.destroy', [$auction, $image]), $html);
        $this->assertStringContainsString('Remove photo 1', $html);
    }

    public function test_the_upload_control_disappears_at_the_limit(): void
    {
        $seller = $this->seller();
        $auction = $this->auctionFor($seller);
        $this->attach($auction, 6);

        $html = $this->actingAs($seller)->get(route('seller.auctions.edit', $auction))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="images"', $html);
        $this->assertStringContainsString('6 of 6', $html);
    }

    public function test_a_new_listing_page_says_photos_come_after_the_first_save(): void
    {
        $html = $this->actingAs($this->seller())->get(route('seller.auctions.create'))->assertOk()->getContent();

        $this->assertStringContainsString('after you save the draft', strtolower($html));
        $this->assertStringNotContainsString('id="images"', $html);
    }
}
