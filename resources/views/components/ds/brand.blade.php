{{--
  ds.brand: the Dot.Auction mark from public/images (logo.png on light, logo-light.png on dark), cropped to its drawing.
  Props: variant='lockup' (disc, chevron and wordmark) | 'mark' (the gavel disc and chevron, without the lettering), width=null (px; the height follows), href=null.
  The two files are swapped by theme with .theme-light-only / .theme-dark-only, so the red lettering never sits on graphite.
--}}
@props(['variant' => 'lockup', 'width' => null, 'href' => null])
@php
    $w = $width ?? ($variant === 'mark' ? 56 : 112);
    $tag = $href ? 'a' : 'span';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class(['ds-brand', 'ds-brand--'.$variant]) }} style="--brand-w: {{ $w }}px">
    <span class="ds-brand__crop theme-light-only" aria-hidden="true"><img src="{{ asset('images/logo.png') }}" alt="" width="1181" height="886" decoding="async"></span>
    <span class="ds-brand__crop theme-dark-only" aria-hidden="true"><img src="{{ asset('images/logo-light.png') }}" alt="" width="1181" height="886" decoding="async"></span>
    <span class="sr-only">Dot.Auction</span>
</{{ $tag }}>
