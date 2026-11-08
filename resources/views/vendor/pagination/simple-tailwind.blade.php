@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination">
        <ul class="ds-pager">
            <li>@if ($paginator->onFirstPage())<span class="ds-pager__link is-disabled" aria-disabled="true">Previous</span>@else<a class="ds-pager__link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>@endif</li>
            <li>@if ($paginator->hasMorePages())<a class="ds-pager__link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>@else<span class="ds-pager__link is-disabled" aria-disabled="true">Next</span>@endif</li>
        </ul>
    </nav>
@endif
