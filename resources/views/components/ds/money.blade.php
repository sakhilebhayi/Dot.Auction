{{--
  ds.money: an amount in the configured currency, tabular lining numerals, symbol and cents set smaller so the whole rand reads first.
  Props: amount (string|int|float, major units as stored), size='md' (sm|md|lg|xl|money: money is the 44px price), muted=false, struck=false, cents=true.
  Never print a price any other way: this component and Auction::money() are the only formatters.
--}}
@props(['amount', 'size' => 'md', 'muted' => false, 'struck' => false, 'cents' => true])
@php $p = \App\Support\Display::moneyParts($amount); @endphp
<span {{ $attributes->class(['ds-money', 'ds-money--'.$size, 'is-muted' => $muted, 'is-struck' => $struck]) }}>@if ($p['negative'])<span class="ds-money__sign">&minus;</span>@endif<span class="ds-money__sym">{{ $p['symbol'] }}</span><span class="ds-money__whole">{{ $p['whole'] }}</span>@if ($cents)<span class="ds-money__cents">.{{ $p['cents'] }}</span>@endif</span>
