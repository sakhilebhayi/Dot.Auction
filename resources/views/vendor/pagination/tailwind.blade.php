{{-- Dot.Auction pager (overrides the framework's pagination::tailwind). Plain words for previous and next, the current page marked by aria-current and a filled box. --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination">
        <ul class="ds-pager">
            @if ($paginator->onFirstPage())
                <li><span class="ds-pager__link is-disabled" aria-disabled="true"><x-ds.icon name="chevron-left" :size="16" /> Previous</span></li>
            @else
                <li><a class="ds-pager__link" href="{{ $paginator->previousPageUrl() }}" rel="prev"><x-ds.icon name="chevron-left" :size="16" /> Previous</a></li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="ds-pager__gap" aria-hidden="true">{{ $element }}</span></li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li class="max-sm:hidden">
                            @if ($page == $paginator->currentPage())
                                <span class="ds-pager__link" aria-current="page"><span class="sr-only">Page </span>{{ $page }}</span>
                            @else
                                <a class="ds-pager__link" href="{{ $url }}"><span class="sr-only">Page </span>{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li><a class="ds-pager__link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next <x-ds.icon name="chevron-right" :size="16" /></a></li>
            @else
                <li><span class="ds-pager__link is-disabled" aria-disabled="true">Next <x-ds.icon name="chevron-right" :size="16" /></span></li>
            @endif
        </ul>
    </nav>
@endif
