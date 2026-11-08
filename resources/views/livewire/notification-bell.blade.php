{{--
  The bell. Trigger: an icon button whose name is "Notifications, N unread" (the number on the badge is part of the name).
  Panel: unread first, then the last few read, each a button that marks it read and goes where it points; "See all" opens the inbox.
  Live updates: Echo when real-time is configured (a listener registered by the component), otherwise a 30 second poll while the tab is visible.
--}}
<div class="nf" x-data @click.outside="if ($wire.open) { $wire.close() }" @keydown.escape.window="if ($wire.open) { $wire.close(); $nextTick(() => $refs.trigger.focus()) }" @unless ($this->usesEcho()) wire:poll.30s.visible="refresh" @endunless>
    <button x-ref="trigger" wire:click="toggle" type="button" class="ds-iconbtn" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="nf-panel">
        <x-ds.icon name="bell" :size="20" />
        <span class="sr-only">Notifications{{ $this->unreadCount > 0 ? ', '.$this->unreadCount.' unread' : '' }}</span>
        @if ($this->unreadCount > 0)
            <span class="ds-iconbtn__badge" aria-hidden="true">{{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}</span>
        @endif
    </button>

    @if ($open)
        <section class="nf-panel" id="nf-panel" aria-labelledby="nf-heading">
            <header class="nf-panel__head">
                <h2 class="nf-panel__title" id="nf-heading">Notifications</h2>
                @if ($this->unreadCount > 0)
                    <button type="button" wire:click="markAllAsRead" class="ds-btn ds-btn--link ds-btn--sm"><span class="ds-btn__label">Mark all read</span></button>
                @endif
            </header>

            @if ($this->notifications->isEmpty())
                <div class="nf-empty">
                    <x-ds.icon name="bell-off" :size="28" />
                    <p class="nf-empty__title">Nothing yet</p>
                    <p class="nf-empty__body">Outbid warnings, wins and payment reminders will show up here.</p>
                </div>
            @else
                <ul class="nf-list">
                    @foreach ($this->notifications as $notification)
                        <li wire:key="nf-{{ $notification->id }}">
                            <button type="button" wire:click="openNotification('{{ $notification->id }}')" @class(['nf-item', 'is-unread' => $notification->read_at === null])>
                                @include('notifications._item', ['notification' => $notification])
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <footer class="nf-panel__foot">
                <a href="{{ route('notifications.index') }}" class="ds-btn ds-btn--quiet ds-btn--sm ds-btn--block"><span class="ds-btn__label">See all notifications</span></a>
            </footer>
        </section>
    @endif
</div>
