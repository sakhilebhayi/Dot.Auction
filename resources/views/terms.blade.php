{{-- The markdown source starts with its own h1; the layout supplies the page's h1, so that first heading is dropped. --}}
<x-guest-layout variant="document" title="Terms of service" description="The terms for using Dot.Auction.">
    {!! preg_replace('/^\s*<h1[^>]*>.*?<\/h1>/s', '', $terms, 1) !!}
</x-guest-layout>
