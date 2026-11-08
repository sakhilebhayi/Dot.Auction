@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="jm__head">
        <h2 class="jm__title">{{ $title }}</h2>
    </div>
    <div class="jm__content">{{ $content }}</div>
    <div class="jm__foot">{{ $footer }}</div>
</x-modal>
