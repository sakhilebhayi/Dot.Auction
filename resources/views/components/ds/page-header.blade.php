{{--
  ds.page-header: the h1 of a page. The app layout renders it from the `header` slot, so a page never writes its own h1 inside the shell.
  Props: title (the h1 text), description=null (one plain sentence), back=null (URL of the parent page), backLabel='Back', 
         level=1 (use 2 only when a ds.section needs the same look).
  Slots: meta (status chips, lot number: shown above the title), actions (the page's primary and secondary buttons, right aligned on wide screens).
  hidden=true keeps the h1 for assistive tech and hides it visually (pages that still draw their own heading).
--}}
@props(['title', 'description' => null, 'back' => null, 'backLabel' => 'Back', 'level' => 1, 'meta' => null, 'actions' => null, 'hidden' => false])
<header {{ $attributes->class(['ds-pagehead', 'sr-only' => $hidden]) }}>
    @if ($back)<a href="{{ $back }}" class="ds-pagehead__back"><x-ds.icon name="arrow-left" :size="16" /> {{ $backLabel }}</a>@endif
    <div class="ds-pagehead__row">
        <div class="ds-pagehead__text">
            @if ($meta)<div class="ds-pagehead__meta">{{ $meta }}</div>@endif
            <h{{ $level }} class="ds-pagehead__title" id="page-title">{{ $title }}</h{{ $level }}>
            @if ($description)<p class="ds-pagehead__desc">{{ $description }}</p>@endif
        </div>
        @if ($actions)<div class="ds-pagehead__actions">{{ $actions }}</div>@endif
    </div>
</header>
