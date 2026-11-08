{{--
  ds.icon: one inline SVG from App\Support\Icons (24 grid, 1.75 stroke, square caps).
  Props: name (required, see DESIGN.md icon list), size=20 (px), label=null (accessible name; omit when the icon only decorates a visible word),
         filled=false (fill the shape with currentColor, for bookmark and play), spin=false.
--}}
@props(['name', 'size' => 20, 'label' => null, 'filled' => false, 'spin' => false])
<svg {{ $attributes->class(['ds-icon', 'ds-icon--spin' => $spin]) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="{{ $filled ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" stroke-linejoin="miter" @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif>{!! \App\Support\Icons::markup($name) !!}</svg>
