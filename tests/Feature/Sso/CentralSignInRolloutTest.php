<?php

namespace Tests\Feature\Sso;

use App\Mail\SsoClaimCodeMail;
use App\Models\Team;
use App\Models\User;
use App\Sso\AuctionProvisioner;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Mail\TeamInvitation;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsSettlements;
use Tests\TestCase;

/** R-54 phase 2 pilot: Dot.Auction behind DOTSSO_ENABLED. Off, nothing changes; on, every entry goes to InfoDot. */
class CentralSignInRolloutTest extends TestCase
{
    use BuildsSettlements;
    use RefreshDatabase;

    private FakeIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idp = (new FakeIdp)->install();
        config([
            'dotsso.provisioner' => AuctionProvisioner::class,
            'dotsso.enabled' => false,
        ]);
        Mail::fake();
    }

    private function on(): void
    {
        config(['dotsso.enabled' => true]);
    }

    private function signInVia(array $claims = [], array $session = [])
    {
        $response = $this->withSession($session)->get('/sso/login')->assertRedirect();
        $authorize = FakeIdp::authorizeParams($response->headers->get('Location'));
        // The fake provider's redirect URI is the one this test app uses.
        $code = $this->idp->approve($authorize, $claims);

        return $this->get('/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]));
    }

    public function test_the_users_table_has_a_nullable_unique_infodot_user_uuid(): void
    {
        $a = User::factory()->create();
        $this->assertNull($a->infodot_user_uuid);
        User::factory()->create(); // two NULLs are allowed

        $a->forceFill(['infodot_user_uuid' => 'x'])->save();
        $this->expectException(QueryException::class);
        User::factory()->create()->forceFill(['infodot_user_uuid' => 'x'])->save();
    }

    public function test_with_the_flag_off_nothing_changes(): void
    {
        $this->get('/login')->assertOk()->assertSee('Keep me signed in');
        $this->get('/sso/login')->assertNotFound();
        $this->get('/')->assertOk()->assertSee(route('login'), false)->assertDontSee('/sso/login', false);
        $this->get('/sso/callback')->assertNotFound();
    }

    public function test_with_the_flag_on_the_local_pages_go_to_infodot_sign_in(): void
    {
        $this->on();

        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->get('/register')->assertRedirect(route('dotsso.login', ['screen' => 'register']));
        $this->get('/forgot-password')->assertRedirect(route('dotsso.login'));
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])->assertRedirect(route('dotsso.login'));
    }

    public function test_the_signed_out_pages_link_straight_to_the_central_sign_in(): void
    {
        $this->on();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(route('dotsso.login'), $html);
        $this->assertStringContainsString(route('dotsso.login', ['screen' => 'register']), $html);
    }

    public function test_the_break_glass_switch_brings_the_local_login_back(): void
    {
        $this->on();
        config(['dotsso.allow_local_login' => true]);

        $this->get('/login')->assertOk()->assertSee('Keep me signed in');
    }

    public function test_a_signed_out_deep_link_survives_the_round_trip(): void
    {
        $this->on();
        $this->get('/auctions')->assertRedirect(route('login'));
        $this->get('/login')->assertRedirect(route('dotsso.login'));

        $this->signInVia()->assertRedirect('/auctions');
        $this->assertAuthenticated();
    }

    public function test_a_first_sign_in_creates_the_account_with_the_infodot_id_and_a_personal_team(): void
    {
        $this->on();

        $this->signInVia(['sub' => 'infodot-uuid-9', 'email' => 'new@example.test', 'name' => 'Nia New'])->assertRedirect('/dashboard');

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('infodot-uuid-9', $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Team::where('user_id', $user->id)->where('personal_team', true)->exists());
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unverified_local_user_claims_with_a_code_and_keeps_data_and_password(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => null, 'password' => Hash::make('old-password-1')]);
        $team = $user->ownedTeams()->first();
        $hash = $user->password;

        $this->signInVia(['sub' => 'infodot-uuid-5'])->assertRedirect(route('dotsso.claim'));
        $this->assertGuest();

        $sent = null;
        Mail::assertSent(SsoClaimCodeMail::class, function ($m) use (&$sent) {
            $sent = $m->code;

            return $m->hasTo('ada@example.test');
        });

        $this->get('/sso/claim')->assertOk()->assertSee('Confirm it is you')->assertSee('a**@example.test', false)->assertDontSee($sent);
        $this->post('/sso/claim', ['code' => $sent])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame('infodot-uuid-5', $user->infodot_user_uuid);
        $this->assertSame($hash, $user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($team->fresh()->is($user->ownedTeams()->first()), 'team membership untouched');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_claim_page_is_one_form_with_labelled_code_field_and_real_buttons(): void
    {
        $this->on();
        User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);
        $this->signInVia()->assertRedirect(route('dotsso.claim'));

        $html = $this->get('/sso/claim')->assertOk()->getContent();

        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+for="code"/', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_sanctum_api_access_is_untouched_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('email', $user->email);
    }

    public function test_a_real_sanctum_bearer_token_works_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->getJson('/api/user')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')->assertOk();
    }

    public function test_the_health_and_fallback_receiver_routes_are_unaffected(): void
    {
        $this->on();

        $this->get('/up')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect(route('dotsso.login'));
    }

    public function test_an_operator_keeps_the_operator_flag_when_linked_at_first_sign_in(): void
    {
        $this->on();
        $op = User::factory()->withPersonalTeam()->create(['email' => 'op@example.test', 'email_verified_at' => now(), 'is_platform_operator' => true]);

        $this->signInVia(['sub' => 'infodot-op-1', 'email' => 'op@example.test']);

        $op->refresh();
        $this->assertSame('infodot-op-1', $op->infodot_user_uuid);
        $this->assertTrue($op->isPlatformOperator());
        $this->get('/operator/settlement-payments')->assertOk();
    }

    public function test_a_new_infodot_person_is_never_made_an_operator(): void
    {
        $this->on();
        $this->signInVia(['sub' => 'infodot-new-2', 'email' => 'fresh@example.test']);

        $this->assertFalse(User::where('email', 'fresh@example.test')->firstOrFail()->isPlatformOperator());
    }

    public function test_a_guest_on_the_operator_page_is_sent_to_the_central_sign_in_and_returns(): void
    {
        $this->on();
        $this->get('/operator/settlement-payments')->assertRedirect(route('login'));
        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->signInVia(['sub' => 'infodot-x', 'email' => 'someone@example.test'])->assertRedirect('/operator/settlement-payments');
        $this->get('/operator/settlement-payments')->assertForbidden();
    }

    public function test_the_stored_infodot_id_wins_over_a_changed_email(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'old@example.test', 'email_verified_at' => now(), 'infodot_user_uuid' => 'infodot-u-3']);

        $this->signInVia(['sub' => 'infodot-u-3', 'email' => 'new@example.test'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
    }

    public function test_the_paystack_webhook_is_not_intercepted_while_sso_is_on(): void
    {
        $this->on();
        $this->enablePaystack();
        [$settlement] = $this->settlement(123450);
        $payment = $this->attempt($settlement);

        $this->webhook($this->chargeSuccess($payment))->assertOk();
        $this->webhook(['event' => 'charge.success'], 'not-a-signature')->assertStatus(401);
    }

    public function test_the_error_page_has_one_h1_a_reference_and_a_way_back(): void
    {
        $this->on();

        $html = $this->get('/sso/callback?error=access_denied&state=x')->assertStatus(400)->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('Back to Dot.Auction', $html);
        $this->assertStringContainsString('Reference:', $html);
    }

    public function test_the_team_invitation_mail_points_new_people_at_the_central_registration(): void
    {
        $this->on();
        $team = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'Seller Co', 'personal_team' => false]);
        $invitation = $team->teamInvitations()->create(['email' => 'x@example.test', 'role' => 'editor']);
        $mail = (new TeamInvitation($invitation))->render();

        $this->assertStringContainsString(route('dotsso.login', ['screen' => 'register']), $mail);
    }

    public function test_a_back_channel_logout_ends_a_signed_in_session_even_though_the_routes_use_auth_sanctum(): void
    {
        $this->on();
        $this->signInVia(['sub' => 'infodot-bc-1', 'email' => 'bc@example.test'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();

        DB::table('dotsso_revocations')->insert(['sub' => 'infodot-bc-1', 'revoked_at_ms' => (int) floor(microtime(true) * 1000) + 5000, 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/dashboard')->assertRedirect(url('/'));
        $this->assertFalse(session()->has('dotsso.session'), 'the SSO session was invalidated');
    }
}
