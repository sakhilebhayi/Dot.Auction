@props(['submit'])

{{-- Jetstream's form section as a ruled two-column band: words on the left, the fields on the right. No floating card. --}}
<section {{ $attributes->merge(['class' => 'jf-section']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <form wire:submit="{{ $submit }}" class="jf-body">
        <div class="jf-fields">
            <div class="grid grid-cols-6 gap-6">
                {{ $form }}
            </div>
        </div>
        @if (isset($actions))
            <div class="jf-actions">{{ $actions }}</div>
        @endif
    </form>
</section>
