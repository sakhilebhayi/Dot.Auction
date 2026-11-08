{{--
  ds.chip: a filter or sort option as a link (state in the URL). Props: href (required), current=false (aria-current + a filled style), count=null.
  Use for category, status and sort filters on browse. Not a decoration: every chip must change the list.
--}}
@props(['href', 'current' => false, 'count' => null])
<a href="{{ $href }}" {{ $attributes->class(['ds-chip', 'is-current' => $current]) }} @if ($current) aria-current="true" @endif><span>{{ $slot }}</span>@if (isset($count))<span class="ds-chip__count">{{ $count }}</span>@endif</a>
