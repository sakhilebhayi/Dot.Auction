{{--
  ds.countdown: the sale-room clock. Server time first, JavaScript ticks it afterwards (resources/js/ds.js), so nothing jumps when scripts load.
  Props: ends (Carbon, required), starts=null (when it is still in the future the clock counts to the opening: "Opens in"),
         size='md' (sm|md|lg|xl), label=null (default "Closes in" / "Opens in"), showAt=true (the absolute time in the display zone beneath),
         now=null (Carbon, for tests; default now()), stacked=false (label above the figures).
  The figures are Chivo Mono so the width never changes while it ticks. role=timer with aria-live off: it is never announced every second.
  When the target passes, the text becomes "Closed" and the element fires the ds:countdown-end event (Livewire pages can refresh on it).
--}}
@props(['ends', 'starts' => null, 'size' => 'md', 'label' => null, 'showAt' => true, 'now' => null, 'stacked' => false])
@php
    use App\Support\Display;
    $nowAt = $now ?? now();
    $opening = $starts !== null && $starts->gt($nowAt);
    $target = $opening ? $starts : $ends;
    $remaining = max(0, (int) $nowAt->diffInSeconds($target, false));
    $over = $target->lte($nowAt);
    $urgentAfter = (int) config('auction.ending_soon_window_minutes', 60) * 60;
    $urgent = ! $opening && ! $over && $remaining <= $urgentAfter;
    $text = $label ?? ($opening ? 'Opens in' : 'Closes in');
@endphp
<span {{ $attributes->class(['ds-countdown', 'ds-countdown--'.$size, 'is-urgent' => $urgent, 'is-over' => $over, 'is-stacked' => $stacked]) }}
    data-ds-countdown data-target="{{ $target->getTimestampMs() }}" data-server="{{ $nowAt->getTimestampMs() }}" data-mode="{{ $opening ? 'opening' : 'closing' }}" data-urgent="{{ $urgentAfter }}" data-label="{{ $text }}">
    <span class="ds-countdown__label" data-cd-label>{{ $over ? '' : $text }}</span>
    <time class="ds-countdown__value" datetime="{{ $target->toIso8601ZuluString() }}" role="timer" aria-live="off" aria-atomic="true" data-cd-value>@if ($over)<span class="ds-cd-word">{{ $opening ? 'Open now' : 'Closed' }}</span>@else @foreach (Display::remainingParts($remaining) as [$n, $u])<span class="ds-cd-n">{{ $n }}</span><span class="ds-cd-u">{{ $u }}</span>@endforeach @endif</time>
    @if ($showAt)<span class="ds-countdown__at">{{ Display::time($target, null, true) }}</span>@endif
</span>
