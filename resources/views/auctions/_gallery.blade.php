{{--
  The photo gallery of a lot page. A scroll-snap strip, so a swipe works with no script and the first photo is in the markup; resources/js/auction-live.js
  adds the previous and next buttons, the thumbnails, the arrow keys and the "Photo 2 of 4" count. Photos come through the private, policy-checked image route.
  Every photo carries its real width and height (no layout shift); only the first is eager.
--}}
@php($count = $images->count())
@if ($count > 0)
    <section class="lot-gallery" data-lot-gallery aria-label="Photos of {{ $auction->title }}">
        <div class="lot-gallery__stage">
            <ul class="lot-gallery__track" tabindex="0" data-gallery-track aria-label="{{ $count > 1 ? 'Photos, swipe or use the left and right arrow keys' : 'Photo' }}">
                @foreach ($images as $photo)
                    <li class="lot-gallery__slide" data-gallery-slide>
                        <img src="{{ $photo->url() }}" alt="{{ $auction->title }}, photo {{ $loop->iteration }} of {{ $count }}"
                             width="{{ $photo->width ?: 1200 }}" height="{{ $photo->height ?: 900 }}"
                             @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif decoding="async" draggable="false">
                    </li>
                @endforeach
            </ul>
            @if ($count > 1)
                <button type="button" class="lot-gallery__nav lot-gallery__nav--prev" data-gallery-prev hidden><x-ds.icon name="chevron-left" :size="22" /><span class="sr-only">Previous photo</span></button>
                <button type="button" class="lot-gallery__nav lot-gallery__nav--next" data-gallery-next hidden><x-ds.icon name="chevron-right" :size="22" /><span class="sr-only">Next photo</span></button>
                <p class="lot-gallery__count" aria-live="polite" aria-atomic="true">Photo <span data-gallery-current>1</span> of {{ $count }}</p>
            @endif
        </div>
        @if ($count > 1)
            <ul class="lot-gallery__thumbs" data-gallery-thumbs hidden>
                @foreach ($images as $photo)
                    <li>
                        <button type="button" data-gallery-thumb="{{ $loop->index }}" @if ($loop->first) aria-current="true" @endif aria-label="Show photo {{ $loop->iteration }} of {{ $count }}">
                            <img src="{{ $photo->url() }}" alt="" width="72" height="54" loading="lazy" decoding="async">
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@else
    <section class="lot-gallery lot-gallery--empty" aria-label="Photos of {{ $auction->title }}">
        <div class="lot-gallery__none">
            <x-ds.icon name="image" :size="32" />
            <p>The seller has not added photos to this lot.</p>
        </div>
    </section>
@endif
