<?php

namespace Tests\Feature\R42;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** R-42 shell: one design system, no third parties, accessible landmarks, every kit component renders. */
class DesignSystemTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /** @return array<string, array{0: string}> */
    public static function guestPages(): array
    {
        return [
            'welcome' => ['/'],
            'sign in' => ['/login'],
            'forgot password' => ['/forgot-password'],
            'terms' => ['/terms-of-service'],
            'privacy' => ['/privacy-policy'],
            'cookies' => ['/cookies'],
        ];
    }

    #[DataProvider('guestPages')]
    public function test_guest_pages_have_one_h1_a_skip_link_a_main_landmark_and_no_third_party_assets(string $url): void
    {
        $html = $this->get($url)->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $this->assertSame(1, $xpath->query('//h1')->length, "$url has exactly one h1");
        $this->assertSame(1, $xpath->query('//main')->length);
        $this->assertSame(1, $xpath->query('//a[contains(@class,"skip-link")][@href="#main"]')->length);
        $this->assertSame(1, $xpath->query('//button[@data-theme-toggle]')->length, 'the theme toggle works on guest pages too');
        foreach (['cdn.tailwindcss.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'material-symbols', 'unpkg.com', 'images.unsplash.com'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
    }

    public function test_page_titles_are_unique_per_page(): void
    {
        $titles = [];
        foreach (self::guestPages() as [$url]) {
            $dom = $this->xpath($this->get($url)->assertOk()->getContent());
            $titles[$url] = trim($dom->query('//title')->item(0)->textContent);
        }
        $this->assertSame($titles, array_unique($titles), 'no two pages share a title: '.json_encode($titles));

        $user = User::factory()->withPersonalTeam()->create();
        $signed = [];
        foreach (['/dashboard', '/auctions', '/seller/auctions', '/settlements', '/user/profile'] as $url) {
            $dom = $this->xpath($this->actingAs($user)->get($url)->assertOk()->getContent());
            $signed[$url] = trim($dom->query('//title')->item(0)->textContent);
        }
        $this->assertSame($signed, array_unique($signed), 'no two signed-in pages share a title: '.json_encode($signed));
    }

    public function test_the_welcome_page_makes_no_claim_the_product_cannot_back(): void
    {
        $text = strip_tags($this->get('/')->assertOk()->getContent());

        foreach (['LIVE REVERB', 'no polling', 'no refreshing', 'Live Reverb', 'broadcast'] as $claim) {
            $this->assertStringNotContainsStringIgnoringCase($claim, $text, "the welcome page does not claim '$claim'");
        }
        $this->assertStringContainsString((string) config('auction.snipe_max_extensions'), $text, 'the anti-snipe figures come from config');
        $this->assertDoesNotMatchRegularExpression('/\d[\d,]*\+? (lots|bidders|users|sellers)/i', $text, 'no invented counts');
        $this->assertStringNotContainsString('—', $text);
        $this->assertStringNotContainsString('–', $text);
    }

    public function test_the_kit_gallery_renders_every_component_in_both_themes(): void
    {
        $html = $this->get('/dev/kit')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertGreaterThanOrEqual(8, $xpath->query('//*[@data-scheme="dark"]')->length, 'a dark pane for each group');

        foreach ([
            'ds-btn--primary', 'ds-btn--quiet', 'ds-btn--danger', 'ds-iconbtn', 'ds-money', 'ds-status', 'ds-countdown', 'ds-rail', 'ds-lot', 'ds-ladder',
            'ds-stat', 'ds-dl', 'ds-pagehead', 'ds-section', 'ds-table', 'ds-empty', 'ds-alert', 'ds-dialog', 'ds-dropdown', 'ds-tabs', 'ds-tabset',
            'ds-chip', 'ds-field', 'ds-check', 'ds-toggle', 'ds-radios', 'ds-avatar', 'ds-badge', 'ds-skel', 'ds-brand',
        ] as $class) {
            $this->assertStringContainsString($class, $html, "the gallery shows $class");
        }
        foreach (['Live', 'Closing soon', 'Scheduled', 'Sold', 'Not sold', 'Awaiting seller decision', 'Cancelled', 'Draft', 'Extended 2 times'] as $word) {
            $this->assertStringContainsString($word, $html, "status word: $word");
        }
        $this->assertSame(0, $xpath->query('//dialog[not(@aria-labelledby)]')->length, 'every dialog is named');
        $this->assertSame(0, $xpath->query('//button[not(@aria-label) and not(normalize-space()) and not(.//*[contains(@class,"sr-only")])]')->length, 'no unnamed icon button');
    }

    public function test_the_ladder_never_prints_the_reserve_amount(): void
    {
        $html = $this->get('/dev/kit')->assertOk()->getContent();

        // KitData gives the first live lot a private reserve of 1500.00 that is not met; only the word may appear.
        $this->assertStringContainsString('Reserve not met', $html);
        $this->assertStringNotContainsString('1,500.00', explode('Closes in', $html)[0] ?? '');
        $this->assertStringNotContainsString('reserve_price', $html);
    }

    public function test_the_shell_links_buyer_pages_through_the_safe_helper_and_lights_them_up_once_registered(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-pending-route') > 0 ? 1 : 0, 'unregistered buyer routes are marked pending');

        Route::middleware('web')->get('/__my-bids', fn () => 'ok')->name('buyer.bids');
        app('router')->getRoutes()->refreshNameLookups();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('href="'.url('/__my-bids').'"', $html, 'a registered route is linked');
    }

    public function test_only_operators_see_payment_operations_in_the_navigation(): void
    {
        $buyer = User::factory()->withPersonalTeam()->create();
        $operator = User::factory()->withPersonalTeam()->platformOperator()->create();

        $this->actingAs($buyer)->get('/dashboard')->assertOk()->assertDontSee('Payment operations');
        $this->actingAs($operator)->get('/dashboard')->assertOk()->assertSee('Payment operations');
    }

    public function test_error_pages_render_standalone_with_one_h1_and_a_way_out(): void
    {
        $response = $this->get('/definitely-not-a-page')->assertNotFound();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertSame(1, $xpath->query('//a[contains(@class,"skip-link")]')->length);
        $this->assertGreaterThanOrEqual(1, $xpath->query('//main//a[contains(@class,"ds-btn")]')->length, 'the page offers a way out');
        $this->assertStringNotContainsString('fonts.googleapis.com', $response->getContent());
    }

    public function test_fonts_are_self_hosted_with_their_licences_and_the_deploy_checks_them(): void
    {
        foreach (['archivo-var-latin.woff2', 'chivo-mono-var-latin.woff2', 'LICENSE-Archivo-OFL.txt', 'LICENSE-ChivoMono-OFL.txt'] as $file) {
            $this->assertFileExists(public_path('fonts/'.$file));
        }
        $this->assertGreaterThan(10000, filesize(public_path('fonts/archivo-var-latin.woff2')));
        $this->assertStringContainsString('SIL OPEN FONT LICENSE', (string) file_get_contents(public_path('fonts/LICENSE-Archivo-OFL.txt')));

        $deploy = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
        $this->assertStringContainsString('fonts/archivo-var-latin.woff2', $deploy, 'the smoke check requests the font');
        $this->assertStringContainsString('"$DOCROOT/fonts"', $deploy, 'the deploy mirrors or links public/fonts into the docroot');
    }

    public function test_notification_mail_uses_the_brand_theme(): void
    {
        $html = (string) (new MailMessage)->subject('Test')->line('Hello')->action('Open the lot', 'https://example.test')->render();

        $this->assertStringContainsString('images/logo.png', $html);
        $this->assertStringContainsString('#f1c62e', $html, 'the Gavel gold button');
        $this->assertStringNotContainsString('#f4ece0', $html, 'the old warm paper colour is gone');
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
    }

    /**
     * The guard rail for every UI agent: views carry no literal colours in style attributes or style blocks, no third-party hosts, no
     * icon font, and no inline event handlers (a nonce-based CSP forbids them). Email themes are the one place colours are literals.
     */
    public function test_no_view_writes_a_colour_loads_a_third_party_or_uses_an_inline_handler(): void
    {
        $root = resource_path('views');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $problems = [];

        foreach ($files as $file) {
            $path = $file->getPathname();
            if (! str_ends_with($path, '.blade.php') || str_contains($path, '/vendor/mail/')) {
                continue;
            }
            $rel = str_replace($root.'/', '', $path);
            $src = (string) file_get_contents($path);
            $noComments = preg_replace('/\{\{--.*?--\}\}/s', '', $src) ?? $src;

            if (preg_match_all('/style="[^"]*#[0-9a-fA-F]{3,8}\b[^"]*"/', $noComments, $m)) {
                $problems[] = "$rel: hex colour in a style attribute";
            }
            if (preg_match('/<style\b[^>]*>[^<]*#[0-9a-fA-F]{3,8}\b/s', $noComments)) {
                $problems[] = "$rel: hex colour in a <style> block";
            }
            foreach (['cdn.tailwindcss.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'material-symbols', 'unpkg.com', 'images.unsplash.com', 'ui-avatars.com'] as $host) {
                if (str_contains($noComments, $host)) {
                    $problems[] = "$rel: $host";
                }
            }
            if (preg_match('/\son(click|change|submit|mouseover|mouseout|input|focus|blur|keydown|keyup|load)\s*=\s*"/i', $noComments)) {
                $problems[] = "$rel: inline event handler (use data-confirm, data-autosubmit, data-ds-toast or a delegated listener)";
            }
            if (preg_match('/<script\b(?![^>]*\bsrc=)(?![^>]*\bnonce=)(?![^>]*type="application\/(ld\+)?json")[^>]*>/i', $noComments)) {
                $problems[] = "$rel: <script> without a nonce";
            }
            if (preg_match('/<style\b(?![^>]*\bnonce=)[^>]*>/i', $noComments)) {
                $problems[] = "$rel: <style> without a nonce";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
