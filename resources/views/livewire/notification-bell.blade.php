{{--
  The bell, on the shared header's panel bell (<x-dot.bell>, dot-ui 0.6.0). Trigger: a 44px button whose name is "Notifications, N unread" (dot-ui builds it from the count).
  Panel: unread first, then the last few read, each a button that marks it read and goes where it points; "See all" opens the inbox. dot-ui owns opening, closing
  (Escape, outside click, another menu opening), focus and placement, so this component keeps no open state in the markup. The list is always rendered (at most 25 rows).
  Live updates: Echo when real-time is configured (a listener registered by the component), otherwise a 30 second poll while the tab is visible.
  The wrapper is the one Livewire root; `.nf` is display: contents so the bell sits in the header's tools row.
--}}
<div class="nf" @unless ($this->usesEcho()) wire:poll.30s.visible="refresh" @endunless>
    <x-dot.bell :count="$this->unreadCount" :max="9" id="nf-panel" panel-label="Notifications">
        <div class="dh-panel__head">
            <span class="dh-panel__title" id="nf-heading">Notifications</span>
            @if ($this->unreadCount > 0)
                <button type="button" wire:click="markAllAsRead" class="dh-mark">Mark all read</button>
            @endif
        </div>

        @if ($this->notifications->isEmpty())
            <div class="nf-empty">
                <x-ds.icon name="bell-off" :size="28" />
                <p class="nf-empty__title">Nothing yet</p>
                <p class="nf-empty__body">Notices about your bids, wins and payments will show up here.</p>
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

        <div class="dh-panel__foot">
            <a href="{{ route('notifications.index') }}" class="dh-mark">See all notifications</a>
        </div>
    </x-dot.bell>
</div>
