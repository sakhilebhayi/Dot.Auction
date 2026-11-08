{{--
  ds.rail: the closing rail. A thin bar that fills from the opening to the hammer, so the share of the sale already gone is readable at a glance.
  Real data only: starts_at, ends_at and the stored extension count. It never animates for effect and carries no text of its own (pair it with ds.countdown).
  Props: starts (Carbon), ends (Carbon), extensions=0 (snipe_extensions; draws a tick where the close was before late bids moved it), thick=false, now=null.
  The original close is ends minus extensions x config auction.snipe_extension_minutes (the extension length is a constant).
--}}
@props(['starts', 'ends', 'extensions' => 0, 'thick' => false, 'now' => null])
@php
    $nowAt = $now ?? now();
    $total = max(1, (int) $starts->diffInSeconds($ends, false));
    $elapsed = min($total, max(0, (int) $starts->diffInSeconds($nowAt, false)));
    $p = round($elapsed / $total, 4);
    $orig = null;
    if ($extensions > 0) {
        $origEnd = $ends->copy()->subMinutes($extensions * (int) config('auction.snipe_extension_minutes', 5));
        $orig = round(min(1, max(0, (int) $starts->diffInSeconds($origEnd, false) / $total)), 4);
    }
    $urgent = ($total - $elapsed) <= (int) config('auction.ending_soon_window_minutes', 60) * 60 && $elapsed < $total;
@endphp
<span {{ $attributes->class(['ds-rail', 'is-thick' => $thick, 'is-urgent' => $urgent]) }} aria-hidden="true" data-ds-rail data-start="{{ $starts->getTimestampMs() }}" data-end="{{ $ends->getTimestampMs() }}" data-server="{{ $nowAt->getTimestampMs() }}" data-urgent="{{ (int) config('auction.ending_soon_window_minutes', 60) * 60 }}">
    <span class="ds-rail__fill" data-rail-fill style="--p: {{ $p }}"></span>
    @if ($orig !== null)<span class="ds-rail__orig" style="--o: {{ $orig }}"></span>@endif
    <span class="ds-rail__hammer"></span>
</span>
