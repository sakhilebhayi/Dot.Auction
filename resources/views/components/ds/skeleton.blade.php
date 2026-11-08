{{--
  ds.skeleton: a loading placeholder in the shape of what is coming. Props: shape='line' (line | block | row | card), lines=3 (for shape line), width=null (CSS width of one line), height=null.
  It pulses softly; reduced motion makes it static. Always give the region aria-busy="true" while it shows.
--}}
@props(['shape' => 'line', 'lines' => 3, 'width' => null, 'height' => null])
@if ($shape === 'line')
    <div {{ $attributes->class('ds-skel-lines') }} aria-hidden="true">@for ($i = 0; $i < $lines; $i++)<span class="ds-skel" @if ($width) style="width: {{ $width }}" @elseif ($i === $lines - 1 && $lines > 1) style="width: 62%" @endif></span>@endfor</div>
@elseif ($shape === 'row')
    <div {{ $attributes->class('ds-skel-row') }} aria-hidden="true"><span class="ds-skel ds-skel--block" style="width: 3rem; height: 3rem"></span><div class="ds-skel-lines"><span class="ds-skel"></span><span class="ds-skel" style="width: 55%"></span></div></div>
@elseif ($shape === 'card')
    <div {{ $attributes->class('ds-skel-card') }} aria-hidden="true"><span class="ds-skel ds-skel--block" style="aspect-ratio: 4 / 3"></span><div class="ds-skel-lines"><span class="ds-skel"></span><span class="ds-skel" style="width: 70%"></span><span class="ds-skel" style="width: 40%"></span></div></div>
@else
    <span {{ $attributes->class('ds-skel ds-skel--block') }} aria-hidden="true" @if ($width || $height) style="@if ($width)width: {{ $width }};@endif @if ($height)height: {{ $height }};@endif" @endif></span>
@endif
