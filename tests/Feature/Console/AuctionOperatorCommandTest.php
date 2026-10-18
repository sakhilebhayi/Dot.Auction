<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuctionOperatorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_grants_the_flag(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.test']);

        $this->artisan('auction:operator', ['email' => 'ops@example.test'])
            ->expectsOutputToContain('Granted platform-operator status for ops@example.test')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->isPlatformOperator());
    }

    public function test_the_email_is_matched_ignoring_case_and_surrounding_space(): void
    {
        $user = User::factory()->create(['email' => 'Ops@Example.Test']);

        $this->artisan('auction:operator', ['email' => ' ops@EXAMPLE.test '])->assertSuccessful();

        $this->assertTrue($user->fresh()->isPlatformOperator());
    }

    public function test_granting_twice_is_harmless(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.test']);

        $this->artisan('auction:operator', ['email' => 'ops@example.test'])->assertSuccessful();
        $this->artisan('auction:operator', ['email' => 'ops@example.test'])
            ->expectsOutputToContain('already')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->isPlatformOperator());
    }

    public function test_revoke_removes_the_flag_and_is_idempotent(): void
    {
        $user = User::factory()->platformOperator()->create(['email' => 'ops@example.test']);

        $this->artisan('auction:operator', ['email' => 'ops@example.test', '--revoke' => true])
            ->expectsOutputToContain('Revoked platform-operator status for ops@example.test')
            ->assertSuccessful();
        $this->assertFalse($user->fresh()->isPlatformOperator());

        $this->artisan('auction:operator', ['email' => 'ops@example.test', '--revoke' => true])
            ->expectsOutputToContain('not an operator')
            ->assertSuccessful();
        $this->assertFalse($user->fresh()->isPlatformOperator());
    }

    public function test_a_revoked_operator_is_locked_out_of_the_page(): void
    {
        $user = User::factory()->withPersonalTeam()->platformOperator()->create(['email' => 'ops@example.test']);
        $this->actingAs($user)->get('/operator/settlement-payments')->assertOk();

        $this->artisan('auction:operator', ['email' => 'ops@example.test', '--revoke' => true])->assertSuccessful();

        $this->actingAs($user->fresh())->get('/operator/settlement-payments')->assertForbidden();
    }

    public function test_it_refuses_an_unknown_email_for_grant_and_revoke_and_creates_nothing(): void
    {
        $before = User::count();

        $this->artisan('auction:operator', ['email' => 'nobody@example.test'])
            ->expectsOutputToContain('No user with the email nobody@example.test')
            ->assertFailed();
        $this->artisan('auction:operator', ['email' => 'nobody@example.test', '--revoke' => true])
            ->expectsOutputToContain('No user with the email nobody@example.test')
            ->assertFailed();

        $this->assertSame($before, User::count());
    }

    public function test_it_will_not_grant_an_unverified_account(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'ops@example.test']);

        $this->artisan('auction:operator', ['email' => 'ops@example.test'])
            ->expectsOutputToContain('not verified')
            ->assertFailed();

        $this->assertFalse($user->fresh()->isPlatformOperator());
    }

    public function test_an_unverified_operator_can_still_be_revoked(): void
    {
        $user = User::factory()->unverified()->platformOperator()->create(['email' => 'ops@example.test']);

        $this->artisan('auction:operator', ['email' => 'ops@example.test', '--revoke' => true])->assertSuccessful();

        $this->assertFalse($user->fresh()->isPlatformOperator());
    }

    public function test_it_needs_an_email_or_import_env_but_not_both(): void
    {
        $this->artisan('auction:operator')->assertFailed();
        $this->artisan('auction:operator', ['email' => 'ops@example.test', '--import-env' => true])->assertFailed();
        $this->artisan('auction:operator', ['--import-env' => true, '--revoke' => true])->assertFailed();
    }

    public function test_it_writes_an_audit_line_without_secrets(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.test']);
        $lines = [];
        Log::listen(function ($message) use (&$lines): void {
            $lines[] = [$message->message, $message->context];
        });

        $this->artisan('auction:operator', ['email' => 'ops@example.test'])->assertSuccessful();
        $this->artisan('auction:operator', ['email' => 'ops@example.test', '--revoke' => true])->assertSuccessful();

        $audit = array_values(array_filter($lines, fn ($l) => stripos($l[0], 'platform-operator') !== false));
        $this->assertCount(2, $audit);
        $this->assertStringContainsString('granted', strtolower($audit[0][0]));
        $this->assertStringContainsString('revoked', strtolower($audit[1][0]));
        $this->assertSame($user->id, $audit[0][1]['user_id']);

        $dump = json_encode($audit);
        $this->assertStringNotContainsString($user->password, $dump);
        $this->assertStringNotContainsString('password', strtolower($dump));
        $this->assertStringNotContainsString('token', strtolower($dump));
    }

    public function test_import_env_grants_existing_verified_users_only(): void
    {
        config(['auction.operator_emails' => ['listed@example.test', 'UNVERIFIED@example.test', 'ghost@example.test']]);
        $listed = User::factory()->create(['email' => 'Listed@Example.test']);
        $unverified = User::factory()->unverified()->create(['email' => 'unverified@example.test']);
        $bystander = User::factory()->create(['email' => 'bystander@example.test']);
        $before = User::count();

        $this->artisan('auction:operator', ['--import-env' => true])
            ->expectsOutputToContain('Granted platform-operator status to 1 of 3')
            ->expectsOutputToContain('ghost@example.test')
            ->expectsOutputToContain('unverified@example.test')
            ->assertSuccessful();

        $this->assertTrue($listed->fresh()->isPlatformOperator());
        $this->assertFalse($unverified->fresh()->isPlatformOperator());
        $this->assertFalse($bystander->fresh()->isPlatformOperator());
        $this->assertSame($before, User::count());
    }

    public function test_import_env_is_repeatable_and_does_not_revoke_anyone(): void
    {
        config(['auction.operator_emails' => ['listed@example.test']]);
        $listed = User::factory()->create(['email' => 'listed@example.test']);
        $already = User::factory()->platformOperator()->create(['email' => 'granted-by-hand@example.test']);

        $this->artisan('auction:operator', ['--import-env' => true])->assertSuccessful();
        $this->artisan('auction:operator', ['--import-env' => true])->assertSuccessful();

        $this->assertTrue($listed->fresh()->isPlatformOperator());
        $this->assertTrue($already->fresh()->isPlatformOperator());
    }

    public function test_import_env_with_nothing_configured_says_so_and_changes_nothing(): void
    {
        config(['auction.operator_emails' => []]);
        $user = User::factory()->create();

        $this->artisan('auction:operator', ['--import-env' => true])
            ->expectsOutputToContain('AUCTION_OPERATOR_EMAILS is empty')
            ->assertSuccessful();

        $this->assertFalse($user->fresh()->isPlatformOperator());
    }
}
