{{--
  One notification's content, shared by the bell panel and the inbox page.
  In: $notification (Illuminate\Notifications\DatabaseNotification). Words come from the notification's stored title and message;
  the time is the display-zone time the notification was created (Display::time), never a relative phrase that goes stale.
--}}
@php
    $unread = $notification->read_at === null;
    $icon = \App\Support\Notifications\Presenter::icon($notification->data['type'] ?? null);
@endphp
<span class="nf-item__icon" aria-hidden="true"><x-ds.icon :name="$icon" :size="20" /></span>
<span class="nf-item__text">
    <span class="nf-item__title">
        {{ $notification->data['title'] ?? 'Notification' }}
        @if ($unread)<span class="nf-item__new">New</span>@endif
    </span>
    @if (! empty($notification->data['message']))<span class="nf-item__body">{{ $notification->data['message'] }}</span>@endif
    <time class="nf-item__time" datetime="{{ $notification->created_at->toIso8601ZuluString() }}">{{ \App\Support\Display::time($notification->created_at, 'D j M, H:i') }}</time>
</span>
