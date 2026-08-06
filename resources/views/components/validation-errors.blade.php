@if ($errors->any())
    <div {{ $attributes }}>
        <div class="font-display font-medium text-[var(--red-soft)]">{{ __('Whoops! Something went wrong.') }}</div>

        <ul class="mt-3 list-disc list-inside text-sm text-[var(--red-soft)]">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
