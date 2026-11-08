<div class="jf-intro">
    <h3 class="jf-intro__title">{{ $title }}</h3>
    <p class="jf-intro__text">{{ $description }}</p>
    @if (! empty($aside ?? ''))<div class="jf-intro__aside">{{ $aside }}</div>@endif
</div>
