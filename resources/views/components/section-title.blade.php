<div class="jf-intro">
    <h2 class="jf-intro__title">{{ $title }}</h2>
    <p class="jf-intro__text">{{ $description }}</p>
    @if (! empty($aside ?? ''))<div class="jf-intro__aside">{{ $aside }}</div>@endif
</div>
