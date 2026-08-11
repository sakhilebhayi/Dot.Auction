<?php

namespace App\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationBell extends Component
{
    public bool $open = false;

    /**
     * Livewire's native Echo integration interpolates {userId} from this
     * public property to build the channel name -- see the
     * echo-notification listener below. It calls Echo's dedicated
     * Channel.notification() method under the hood (vendor/livewire/livewire,
     * supportLaravelEcho.js), which listens for Laravel's own
     * Illuminate\Notifications\Events\BroadcastNotificationCreated --
     * there's no broadcastAs()-name/leading-dot mismatch to get wrong here
     * the way there is with a custom domain event.
     */
    public int $userId;

    public function mount(): void
    {
        $this->userId = auth()->id();
    }

    #[Computed]
    public function notifications(): Collection
    {
        return auth()->user()->notifications()->latest()->limit(10)->get();
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

    public function markAsRead(string $notificationId): void
    {
        auth()->user()->notifications()->where('id', $notificationId)->first()?->markAsRead();
        unset($this->notifications, $this->unreadCount);
    }

    #[On('notifications-refresh')]
    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
        unset($this->notifications, $this->unreadCount);
    }

    /**
     * A new notification arrived over the WebSocket -- bust the cached
     * computed properties so the next render picks it up. Deliberately a
     * separate listener from markAllAsRead() above: receiving a new
     * notification must not mark existing ones as read.
     */
    #[On('echo-notification:App.Models.User.{userId},notification')]
    public function refresh(): void
    {
        unset($this->notifications, $this->unreadCount);
    }

    public function render()
    {
        return view('livewire.notification-bell');
    }
}
