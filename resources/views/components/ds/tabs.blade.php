{{--
  ds.tabs: navigation tabs made of links, so the current tab lives in the URL (filters, sections). Prefer this to ds.tabset for anything a person may share or reload.
  Props: tabs (required): list of ['label' => 'Live', 'href' => url, 'current' => bool, 'count' => 4|null], label (required; the aria-label of the nav).
  The current tab carries aria-current="page" and a heavier underline plus bold text, not colour alone.
--}}
@props(['tabs', 'label'])
<nav {{ $attributes->class('ds-tabs') }} aria-label="{{ $label }}">
    <ul>
        @foreach ($tabs as $tab)
            <li><a href="{{ $tab['href'] }}" @if (! empty($tab['current'])) aria-current="page" @endif class="ds-tabs__tab {{ ! empty($tab['current']) ? 'is-current' : '' }}">{{ $tab['label'] }}@if (isset($tab['count']))<span class="ds-tabs__count">{{ $tab['count'] }}</span>@endif</a></li>
        @endforeach
    </ul>
</nav>
