{{-- The markdown source starts with its own h1; the layout supplies the page's h1, so that first heading is dropped. --}}
<x-guest-layout variant="document" title="Cookie policy" description="The cookies Dot.Auction sets and why.">
    {!! preg_replace('/^\s*<h1[^>]*>.*?<\/h1>/s', '', $cookies, 1) !!}
</x-guest-layout>
