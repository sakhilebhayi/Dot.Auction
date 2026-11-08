@props(['active'])

<a {{ $attributes->class(['ds-tabs__tab', 'is-current' => ($active ?? false)]) }} @if ($active ?? false) aria-current="page" @endif>{{ $slot }}</a>
