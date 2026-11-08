<?php

namespace App\Livewire;

use App\Support\Notifications\Destination;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The bell in the top bar. Opens a short list (everything unread, plus the last few that were read); pressing one marks it
 * read and goes where it points. The full list is /notifications.
 *
 * Fresh notifications arrive one of two ways, decided on the server so the page never asks for a socket that is not there:
 * over Reverb through Echo when real-time is on and configured, otherwise a light poll every 30 seconds while the tab is
 * visible (Livewire pauses polling in a hidden tab).
 */
class NotificationBell extends Component
{
    /** Unread notifications shown at most, and read ones kept for context. */
    private const UNREAD_LIMIT = 20;

    private const READ_LIMIT = 5;

    public bool $open = false;

    /**
     * The id interpolated into the channel name of the Echo listener below. Locked: a browser must not be able to
     * change it to subscribe to somebody else's channel name.
     */
    #[Locked]
    public int $userId;

    public function mount(): void
    {
        $this->userId = (int) auth()->id();
    }

    /**
     * Whether Echo (Reverb) is expected to be present in the page. The same two settings feed the JavaScript bundle, so
     * when either is off the listener is not registered and Livewire never warns that Echo cannot be found.
     */
    public function usesEcho(): bool
    {
        return (bool) config('auction.realtime.enabled')
            && config('broadcasting.default') === 'reverb'
            && filled(config('broadcasting.connections.reverb.key'));
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return $this->usesEcho()
            ? ['echo-notification:App.Models.User.'.$this->userId.',notification' => 'refresh']
            : [];
    }

    /**
     * All unread (newest first, up to a limit) and then the most recent read ones.
     *
     * @return Collection<int, \Illuminate\Notifications\DatabaseNotification>
     */
    #[Computed]
    public function notifications(): Collection
    {
        $user = auth()->user();
        $unread = $user->unreadNotifications()->latest()->latest('id')->limit(self::UNREAD_LIMIT)->get();
        $read = $user->readNotifications()->latest('read_at')->latest('id')->limit(self::READ_LIMIT)->get();

        return $unread->concat($read)->values();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function close(): void
    {
        $this->open = false;
    }

    /**
     * Mark one notification read and follow the address stored with it. A notification without a usable address is only
     * marked read and the panel stays open.
     */
    public function openNotification(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->whereKey($notificationId)->first();

        if ($notification === null) {
            return;
        }

        $notification->markAsRead();
        unset($this->notifications, $this->unreadCount);

        $url = Destination::safe($notification->data['url'] ?? null);

        if ($url !== null) {
            $this->open = false;
            $this->redirect($url);
        }
    }

    public function markAsRead(string $notificationId): void
    {
        auth()->user()->notifications()->whereKey($notificationId)->first()?->markAsRead();
        unset($this->notifications, $this->unreadCount);
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        unset($this->notifications, $this->unreadCount);
    }

    /**
     * A new notification arrived (Echo) or the poll fired: drop the cached lists so the next render reads them again.
     * Deliberately separate from markAllAsRead(): receiving a notification must not read the others.
     */
    public function refresh(): void
    {
        unset($this->notifications, $this->unreadCount);
    }

    public function render()
    {
        return view('livewire.notification-bell');
    }
}
