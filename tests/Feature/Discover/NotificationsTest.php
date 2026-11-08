<?php

namespace Tests\Feature\Discover;

use App\Livewire\NotificationBell;
use App\Models\User;
use App\Support\Notifications\Destination;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/** The bell and the inbox: a notification marks itself read and goes where it says, nothing else's, and Echo is never assumed. */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function give(User $user, string $title, ?string $url, bool $read = false, string $message = 'Something happened.'): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => ['type' => 'outbid', 'title' => $title, 'message' => $message, 'url' => $url],
            'read_at' => $read ? now() : null,
            'created_at' => now()->subMinutes(random_int(1, 500)),
        ]);
    }

    private function user(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    public function test_pressing_a_notification_in_the_bell_marks_it_read_and_follows_its_url(): void
    {
        $me = $this->user();
        $n = $this->give($me, 'Outbid on a guitar', '/auctions/42');

        Livewire::actingAs($me)->test(NotificationBell::class)
            ->call('toggle')
            ->call('openNotification', $n->id)
            ->assertRedirect('/auctions/42');

        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_a_stored_absolute_url_on_our_host_is_followed_as_a_path_and_a_foreign_one_never_is(): void
    {
        config(['app.url' => 'https://auction.example.test']);
        $me = $this->user();
        $own = $this->give($me, 'Own host', 'https://auction.example.test/auctions/7?x=1#bids');
        $foreign = $this->give($me, 'Foreign host', 'https://evil.example.org/phish');
        $protocolRelative = $this->give($me, 'Protocol relative', '//evil.example.org/phish');

        Livewire::actingAs($me)->test(NotificationBell::class)->call('openNotification', $own->id)->assertRedirect('/auctions/7?x=1#bids');
        Livewire::actingAs($me)->test(NotificationBell::class)->call('openNotification', $foreign->id)->assertNoRedirect();
        Livewire::actingAs($me)->test(NotificationBell::class)->call('openNotification', $protocolRelative->id)->assertNoRedirect();

        $this->assertNull(Destination::safe('javascript:alert(1)'));
        $this->assertNull(Destination::safe("/ok\r\nLocation: https://evil.example.org"));
        $this->assertNull(Destination::safe('/\\evil.example.org'));
        $this->assertNull(Destination::safe(null));
        $this->assertSame('/auctions/1', Destination::safe('/auctions/1'));
    }

    public function test_a_notification_without_an_address_is_only_marked_read(): void
    {
        $me = $this->user();
        $n = $this->give($me, 'No address', null);

        Livewire::actingAs($me)->test(NotificationBell::class)->call('openNotification', $n->id)->assertNoRedirect();

        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_nobody_can_open_or_read_someone_elses_notification(): void
    {
        $me = $this->user();
        $other = $this->user();
        $theirs = $this->give($other, 'Private to them', '/auctions/9');

        Livewire::actingAs($me)->test(NotificationBell::class)->call('openNotification', $theirs->id)->assertNoRedirect();
        $this->actingAs($me)->post(route('notifications.open', $theirs->id))->assertNotFound();

        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_the_bell_lists_every_unread_and_only_the_recent_read_ones_with_a_see_all_link(): void
    {
        $me = $this->user();
        foreach (range(1, 12) as $i) {
            $this->give($me, "Unread number $i", '/dashboard');
        }
        foreach (range(1, 8) as $i) {
            $this->give($me, "Read number $i", '/dashboard', true);
        }

        $component = Livewire::actingAs($me)->test(NotificationBell::class)->call('toggle');

        $component->assertSee('Unread number 12')
            ->assertSee('href="'.route('notifications.index').'"', false)
            ->assertSee('See all notifications');
        $this->assertSame(12 + 5, $component->instance()->notifications()->count(), 'all unread plus five recent read');
        $this->assertSame(12, $component->instance()->unreadCount());
    }

    public function test_the_bell_has_an_empty_state_and_a_name_that_carries_the_unread_count(): void
    {
        $me = $this->user();

        Livewire::actingAs($me)->test(NotificationBell::class)->call('toggle')->assertSee('Nothing yet')->assertSee('Notifications')->assertDontSee('unread');

        $this->give($me, 'One', '/dashboard');
        Livewire::actingAs($me)->test(NotificationBell::class)->assertSee('Notifications, 1 unread');
    }

    public function test_the_bell_asks_for_echo_only_when_real_time_is_configured_otherwise_it_polls(): void
    {
        $me = $this->user();
        config(['auction.realtime.enabled' => false, 'broadcasting.default' => 'log']);
        $off = Livewire::actingAs($me)->test(NotificationBell::class);
        $this->assertFalse($off->instance()->usesEcho());
        $off->assertSeeHtml('wire:poll.30s.visible="refresh"');
        $this->assertSame([], (fn () => $this->getListeners())->call($off->instance()));

        config(['auction.realtime.enabled' => true, 'broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'abc']);
        $on = Livewire::actingAs($me)->test(NotificationBell::class);
        $this->assertTrue($on->instance()->usesEcho());
        $on->assertDontSeeHtml('wire:poll');
        $this->assertArrayHasKey('echo-notification:App.Models.User.'.$me->id.',notification', (fn () => $this->getListeners())->call($on->instance()));
    }

    public function test_the_user_id_the_echo_channel_is_built_from_cannot_be_changed_from_the_browser(): void
    {
        $me = $this->user();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($me)->test(NotificationBell::class)->set('userId', $me->id + 1);
    }

    public function test_mark_all_read_in_the_bell_and_on_the_inbox_page_touch_only_my_notifications(): void
    {
        $me = $this->user();
        $other = $this->user();
        $this->give($me, 'Mine', '/dashboard');
        $theirs = $this->give($other, 'Theirs', '/dashboard');

        Livewire::actingAs($me)->test(NotificationBell::class)->call('markAllAsRead');
        $this->assertSame(0, $me->unreadNotifications()->count());
        $this->assertNull($theirs->fresh()->read_at);

        $this->give($me, 'Mine again', '/dashboard');
        $this->actingAs($me)->post(route('notifications.read-all'))->assertRedirect(route('notifications.index'));
        $this->assertSame(0, $me->unreadNotifications()->count());
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_the_inbox_lists_everything_newest_first_with_times_in_the_display_zone(): void
    {
        $me = $this->user();
        $other = $this->user();
        $this->give($me, 'Read long ago', '/dashboard', true);
        $new = $this->give($me, 'Fresh news', '/auctions/3', false, 'You were outbid.');
        $new->forceFill(['created_at' => Carbon::parse('2026-11-14 16:30:00', 'UTC')])->save();
        $this->give($other, 'Not mine at all', '/dashboard');

        $response = $this->actingAs($me)->get(route('notifications.index'))->assertOk();

        $response->assertSee('Fresh news')->assertSee('Read long ago')->assertDontSee('Not mine at all')
            ->assertSee('Sat 14 Nov, 18:30')   // 16:30 UTC is 18:30 in South Africa
            ->assertSee('New')
            ->assertSee('Mark all as read')
            ->assertSee('action="'.route('notifications.open', $new->id).'"', false);
        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
    }

    public function test_the_inbox_open_action_marks_read_and_redirects(): void
    {
        $me = $this->user();
        $n = $this->give($me, 'Go there', '/auctions/5');

        $this->actingAs($me)->post(route('notifications.open', $n->id))->assertRedirect('/auctions/5');

        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_the_inbox_has_an_empty_state_and_paginates(): void
    {
        $me = $this->user();
        $this->actingAs($me)->get(route('notifications.index'))->assertOk()->assertSee('No notifications yet')->assertDontSee('Mark all');

        foreach (range(1, 25) as $i) {
            $this->give($me, "Item $i", '/dashboard');
        }
        $this->actingAs($me)->get(route('notifications.index'))->assertOk()->assertSee('Showing 1 to 20 of 25 notifications');
    }

    public function test_the_bell_does_not_depend_on_echo_in_the_page(): void
    {
        $me = $this->user();
        config(['auction.realtime.enabled' => false]);

        $html = $this->actingAs($me)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('echo-notification', $html);
    }
}
