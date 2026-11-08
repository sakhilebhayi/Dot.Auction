<x-app-layout title="Notifications" description="Everything Dot.Auction has told you, newest first." width="narrow">
    <x-slot name="header">Notifications</x-slot>
    @if ($unread > 0)
        <x-slot name="actions">
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <x-ds.button type="submit" variant="quiet" icon="check">Mark all as read</x-ds.button>
            </form>
        </x-slot>
    @endif

    @if (session('status'))
        <x-ds.alert tone="success" class="dc-gap">{{ session('status') }}</x-ds.alert>
    @endif

    @if ($notifications->isEmpty())
        <x-ds.empty-state :level="2" title="No notifications yet" icon="bell-off" body="When you are outbid, win a lot, sell one or owe a payment, it shows up here and in the bell.">
            <x-ds.button :href="route('auctions.index')" icon="browse">Browse lots</x-ds.button>
        </x-ds.empty-state>
    @else
        <ul class="nf-page" aria-label="Notifications, newest first">
            @foreach ($notifications as $notification)
                <li>
                    @if (\App\Support\Notifications\Destination::safe($notification->data['url'] ?? null))
                        <form method="POST" action="{{ route('notifications.open', $notification->id) }}">
                            @csrf
                            <button type="submit" @class(['nf-item', 'nf-item--page', 'is-unread' => $notification->read_at === null])>
                                @include('notifications._item', ['notification' => $notification])
                                <x-ds.icon name="chevron-right" :size="18" class="nf-item__go" />
                            </button>
                        </form>
                    @else
                        <div @class(['nf-item', 'nf-item--page', 'is-unread' => $notification->read_at === null])>
                            @include('notifications._item', ['notification' => $notification])
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        <x-ds.pagination :paginator="$notifications" noun="notifications" />
    @endif
</x-app-layout>
