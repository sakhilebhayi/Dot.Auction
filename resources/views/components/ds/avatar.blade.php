{{--
  ds.avatar: initials in a square-cornered block (the paddle shape). Props: name (required), size=32 (px), src=null (an image URL).
  The name is the accessible text unless decorative=true (when the name is printed next to it).
--}}
@props(['name', 'size' => 32, 'src' => null, 'decorative' => false])
<span {{ $attributes->class('ds-avatar') }} style="--av: {{ $size }}px" @if ($decorative) aria-hidden="true" @else role="img" aria-label="{{ $name }}" @endif>
    @if ($src)<img src="{{ $src }}" alt="" width="{{ $size }}" height="{{ $size }}">@else<span>{{ \App\Support\Display::initials($name) }}</span>@endif
</span>
