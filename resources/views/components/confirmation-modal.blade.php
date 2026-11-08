@props(['id' => null, 'maxWidth' => null])
@php $id = $id ?? md5($attributes->wire('model')); @endphp

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="jm__head">
        <x-ds.icon name="alert" :size="24" class="jm__warn" />
        <h2 class="jm__title" id="{{ $id }}-title">{{ $title }}</h2>
    </div>
    <div class="jm__content">{{ $content }}</div>
    <div class="jm__foot">{{ $footer }}</div>
</x-modal>
