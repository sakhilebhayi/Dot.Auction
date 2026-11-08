{{-- Kept for Jetstream compatibility: the guest layout (layouts/guest.blade.php) now provides the card, so this only passes its slot through. --}}
<div {{ $attributes->class('gx-card__inner') }}>{{ $slot }}</div>
