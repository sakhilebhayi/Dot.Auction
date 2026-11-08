{{--
  ds.dropdown-item: one entry of ds.dropdown. Props: href=null (a link), icon=null, danger=false, method=null ('POST' renders a form button, for log out and similar), action=null (the form URL), fields=[] (name => value hidden inputs for that form).
  A button without href or method is a plain button (wire:click works).
--}}
@props(['href' => null, 'icon' => null, 'danger' => false, 'method' => null, 'action' => null, 'fields' => []])
@php $cls = ['ds-menu__item', 'is-danger' => $danger]; @endphp
@if ($method && $action)
    <form method="POST" action="{{ $action }}" role="none">@csrf @if (strtoupper($method) !== 'POST') @method($method) @endif @foreach ($fields as $fName => $fValue)<input type="hidden" name="{{ $fName }}" value="{{ $fValue }}">@endforeach
        <button type="submit" role="menuitem" {{ $attributes->class($cls) }}>@if ($icon)<x-ds.icon :name="$icon" :size="18" />@endif<span>{{ $slot }}</span></button>
    </form>
@elseif ($href)
    <a href="{{ $href }}" role="menuitem" {{ $attributes->class($cls) }}>@if ($icon)<x-ds.icon :name="$icon" :size="18" />@endif<span>{{ $slot }}</span></a>
@else
    <button type="button" role="menuitem" {{ $attributes->class($cls) }}>@if ($icon)<x-ds.icon :name="$icon" :size="18" />@endif<span>{{ $slot }}</span></button>
@endif
