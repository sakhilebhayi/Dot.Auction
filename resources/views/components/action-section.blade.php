{{-- Jetstream's action section (security, danger zones) as a ruled two-column band. --}}
<section {{ $attributes->merge(['class' => 'jf-section']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <div class="jf-body">
        <div class="jf-fields">{{ $content }}</div>
    </div>
</section>
