{{--
  ds.section: a ruled region of a page with its own heading. Sections are bands separated by rules and tone, never floating cards.
  Props: title=null (h2), description=null, id=null, tone='plain' (plain | surface | sunken), level=2, flush=false (no inner padding, for tables and lists).
  Slots: actions (right of the heading), default (the body).
--}}
@props(['title' => null, 'description' => null, 'id' => null, 'tone' => 'plain', 'level' => 2, 'flush' => false])
@php $hid = $id ? $id.'-heading' : null; @endphp
<section @if ($id) id="{{ $id }}" @endif @if ($title && $hid) aria-labelledby="{{ $hid }}" @endif {{ $attributes->class(['ds-section', 'ds-section--'.$tone, 'is-flush' => $flush]) }}>
    @if ($title || isset($actions))
        <div class="ds-section__head">
            <div>
                @if ($title)<h{{ $level }} class="ds-section__title" @if ($hid) id="{{ $hid }}" @endif>{{ $title }}</h{{ $level }}>@endif
                @if ($description)<p class="ds-section__desc">{{ $description }}</p>@endif
            </div>
            @isset($actions)<div class="ds-section__actions">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div class="ds-section__body">{{ $slot }}</div>
</section>
