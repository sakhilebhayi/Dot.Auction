{{-- A lot's first photo as a small thumbnail in a table row, or a quiet blank of the same size. In: $lot (images loaded). Decorative: the lot's name sits beside it. --}}
@php $photo = $lot->images->first(); @endphp
<span class="dc-thumb" aria-hidden="true">
    @if ($photo)<img src="{{ $lot->imageUrl($photo, 'thumb') }}" alt="" width="48" height="36" loading="lazy" decoding="async">@endif
</span>
