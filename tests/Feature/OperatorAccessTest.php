<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformOperator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Operator access is the `users.is_platform_operator` flag behind the `operator` middleware, as on the
 * other Dot platforms. The old AUCTION_OPERATOR_EMAILS list no longer authorises anyone.
 */
class OperatorAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = '/operator/settlement-payments';

    public function test_the_flag_column_exists_and_defaults_to_false(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'is_platform_operator'));

        $user = User::factory()->create();

        $this->assertFalse($user->fresh()->is_platform_operator);
        $this->assertFalse($user->fresh()->isPlatformOperator());
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(self::PAGE)->assertRedirect('/login');
    }

    public function test_a_signed_in_non_operator_gets_403(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get(self::PAGE)->assertForbidden();
    }

    public function test_a_platform_operator_gets_the_page(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->platformOperator()->create())->get(self::PAGE)->assertOk();
    }

    public function test_the_operator_alias_sends_a_guest_to_sign_in_and_refuses_a_non_operator_on_its_own(): void
    {
        $this->assertSame(EnsurePlatformOperator::class, app('router')->getMiddleware()['operator'] ?? null);

        Route::middleware(['web', 'operator'])->get('/__operator-only', fn () => 'ok');

        $this->get('/__operator-only')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/__operator-only')->assertForbidden();
        $this->actingAs(User::factory()->platformOperator()->create())->get('/__operator-only')->assertOk();
    }

    public function test_the_navigation_link_is_shown_to_operators_only(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get('/dashboard')
            ->assertOk()->assertDontSee(route('operator.settlement-payments.index'));

        $this->actingAs(User::factory()->withPersonalTeam()->platformOperator()->create())->get('/dashboard')
            ->assertOk()->assertSee(route('operator.settlement-payments.index'));
    }

    public function test_the_flag_is_not_mass_assignable(): void
    {
        $this->assertNotContains('is_platform_operator', (new User)->getFillable());

        $user = User::create([
            'name' => 'Mass Assign',
            'email' => 'mass@example.test',
            'password' => 'a-long-enough-password',
            'is_platform_operator' => true,
        ]);
        $this->assertFalse($user->fresh()->isPlatformOperator());

        $user->update(['is_platform_operator' => true]);
        $this->assertFalse($user->fresh()->isPlatformOperator());

        $user->fill(['is_platform_operator' => true])->save();
        $this->assertFalse($user->fresh()->isPlatformOperator());
    }

    public function test_registering_or_editing_a_profile_with_the_flag_in_the_request_does_not_grant_it(): void
    {
        $this->post('/register', [
            'name' => 'Sneaky Person',
            'email' => 'sneaky@example.test',
            'password' => 'a-long-enough-password-1',
            'password_confirmation' => 'a-long-enough-password-1',
            'terms' => true,
            'is_platform_operator' => 1,
        ]);

        $sneaky = User::firstWhere('email', 'sneaky@example.test');
        $this->assertNotNull($sneaky);
        $this->assertFalse($sneaky->isPlatformOperator());

        $this->actingAs($sneaky)->put('/user/profile-information', [
            'name' => 'Sneaky Person',
            'email' => 'sneaky@example.test',
            'is_platform_operator' => 1,
        ]);
        $this->assertFalse($sneaky->fresh()->isPlatformOperator());
    }

    public function test_the_old_email_list_alone_no_longer_grants_access(): void
    {
        config(['auction.operator_emails' => ['ops@example.test']]);
        $listed = User::factory()->withPersonalTeam()->create(['email' => 'ops@example.test']);

        $this->actingAs($listed)->get(self::PAGE)->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertDontSee(route('operator.settlement-payments.index'));
    }

    public function test_the_flag_alone_grants_access_whatever_the_old_list_says(): void
    {
        config(['auction.operator_emails' => ['someone-else@example.test']]);

        $this->actingAs(User::factory()->withPersonalTeam()->platformOperator()->create(['email' => 'ops@example.test']))
            ->get(self::PAGE)->assertOk();
    }

    public function test_the_gate_that_read_the_email_list_is_gone(): void
    {
        $user = User::factory()->platformOperator()->create();

        $this->assertFalse(Gate::forUser($user)->has('view-settlement-payments'));
    }
}
