<div style="position:relative;" x-data @click.outside="$wire.open = false">
    {{-- The trigger is shell chrome (design system): an icon button whose accessible name carries the unread count. The panel below is the discover workstream's. --}}
    <button wire:click="toggle" type="button" class="ds-iconbtn" title="Notifications" aria-expanded="{{ $open ? 'true' : 'false' }}">
        <x-ds.icon name="bell" :size="20" />
        <span class="sr-only">Notifications{{ $this->unreadCount > 0 ? ', '.$this->unreadCount.' unread' : '' }}</span>
        @if($this->unreadCount > 0)
            <span class="ds-iconbtn__badge" aria-hidden="true">{{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}</span>
        @endif
    </button>

    @if($open)
    <div class="bell-panel" style="position:absolute;top:calc(100% + 8px);right:0;width:320px;max-height:420px;overflow-y:auto;background:var(--c-surface);border:1px solid var(--c-line);border-radius:10px;box-shadow:var(--shadow-float);z-index:50;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:0.85rem 1rem;border-bottom:1px solid var(--c-line);">
            <span style="font-family:'Syne',sans-serif;font-size:12.5px;font-weight:700;color:var(--c-ink);">Notifications</span>
            @if($this->unreadCount > 0)
                <button wire:click="markAllAsRead" style="background:none;border:none;color:var(--c-notice);font-size:11px;font-weight:600;cursor:pointer;padding:0;">Mark all read</button>
            @endif
        </div>

        @if($this->notifications->isEmpty())
            <div style="text-align:center;padding:2rem 1rem;">
                <x-ds.icon name="bell-off" :size="30" />
                <p style="font-size:0.75rem;color:var(--c-ink-3);margin:0;">No notifications yet.</p>
            </div>
        @else
            <div>
                @foreach($this->notifications as $notification)
                <div role="button" tabindex="0" class="notif-row" wire:click="markAsRead('{{ $notification->id }}')" wire:keydown.enter="markAsRead('{{ $notification->id }}')" wire:keydown.space.prevent="markAsRead('{{ $notification->id }}')" style="padding:0.7rem 1rem;border-bottom:1px solid var(--c-line);cursor:pointer;{{ $notification->read_at ? 'opacity:0.55;' : '' }}">
                    <div style="display:flex;align-items:center;gap:6px;">
                        @if(!$notification->read_at)
                            <span style="width:6px;height:6px;border-radius:50%;background:var(--c-notice-bg);flex-shrink:0;"></span>
                        @endif
                        <span style="font-size:12px;font-weight:600;color:var(--c-ink);">{{ $notification->data['title'] ?? 'Notification' }}</span>
                    </div>
                    <div style="font-size:11px;color:var(--c-ink-3);margin-top:3px;line-height:1.4;">{{ $notification->data['message'] ?? '' }}</div>
                </div>
                @endforeach
            </div>
        @endif
    </div>
    @endif
</div>
