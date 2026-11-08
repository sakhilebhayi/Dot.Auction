@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- The logo's transparent PNG sits on a white band so it reads in clients that invert colours. $slot is the app name; it is the alt text. --}}
<img src="{{ asset('images/logo.png') }}" class="logo" alt="{{ trim(strip_tags($slot)) ?: 'Dot.Auction' }}" width="108" height="72">
</a>
</td>
</tr>
