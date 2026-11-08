{{-- The markdown source starts with its own h1; the layout supplies the page's h1, so that first heading is dropped. --}}
<x-guest-layout variant="document" title="Privacy policy" description="How Dot.Auction handles your personal information.">
    {!! preg_replace('/^\s*<h1[^>]*>.*?<\/h1>/s', '', $policy, 1) !!}
</x-guest-layout>
