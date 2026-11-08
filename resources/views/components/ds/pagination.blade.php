{{--
  ds.pagination: the page links for a paginator, with the plain-language summary. Props: paginator (required), noun='lots' (for "Showing 1 to 12 of 40 lots").
  Links keep the current query string (call ->withQueryString() on the paginator): state lives in the URL.
--}}
@props(['paginator', 'noun' => 'items'])
@if ($paginator->hasPages())
    <div {{ $attributes->class('ds-paging') }}>
        <p class="ds-paging__summary">Showing {{ number_format($paginator->firstItem()) }} to {{ number_format($paginator->lastItem()) }} of {{ number_format($paginator->total()) }} {{ $noun }}</p>
        {{ $paginator->links('pagination::tailwind') }}
    </div>
@endif
