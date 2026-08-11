<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * routes/channels.php only registers the default private notification
 * channel (App.Models.User.{id}) -- BidPlaced deliberately broadcasts on a
 * public "auction.{id}" channel that needs no authorization callback (see
 * the docblock there). This test exercises the real /broadcasting/auth
 * endpoint to prove a user can only authorize their own notification
 * channel, and that malformed identifiers fail closed rather than erroring.
 */
class BroadcastChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml forces BROADCAST_CONNECTION=null in the test env so
        // event-broadcasting tests never attempt a real network call -- but
        // the null driver's auth() is a no-op that authorizes everything
        // unconditionally. Channel-authorization callbacks are only
        // enforced by a real Pusher-protocol broadcaster (reverb/pusher),
        // so this test class opts back into that for its own requests.
        //
        // Switching the config alone isn't enough: Broadcast::channel()
        // registers callbacks on whichever driver instance is current at
        // call time, and routes/channels.php already ran against the
        // "null" driver during app bootstrap (before this setUp() runs).
        // Re-requiring it now, after switching the default, registers the
        // same callback on a fresh "reverb" driver instance instead.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
        ]);
        require base_path('routes/channels.php');
    }

    private function authRequest(User $user, string $channelName): TestResponse
    {
        return $this->actingAs($user)->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channelName,
        ]);
    }

    public function test_user_can_authorize_their_own_notification_channel(): void
    {
        $user = User::factory()->create();

        $this->authRequest($user, "private-App.Models.User.{$user->id}")
            ->assertOk();
    }

    public function test_user_cannot_authorize_another_users_notification_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->authRequest($user, "private-App.Models.User.{$other->id}")
            ->assertForbidden();
    }

    public function test_non_numeric_user_identifier_fails_closed_rather_than_erroring(): void
    {
        $user = User::factory()->create();

        $this->authRequest($user, 'private-App.Models.User.not-a-number')
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_authorize_any_private_channel(): void
    {
        $user = User::factory()->create();

        $this->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-App.Models.User.{$user->id}",
        ])->assertForbidden();
    }
}
