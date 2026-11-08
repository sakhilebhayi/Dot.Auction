@props(['active'])

<a {{ $attributes->class(['ds-nav', 'ds-nav--row', 'is-active' => ($active ?? false)]) }} @if ($active ?? false) aria-current="page" @endif>{{ $slot }}</a>
