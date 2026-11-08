@if ($errors->any())
    <x-ds.alert tone="danger" title="This could not be saved." role="alert" {{ $attributes }}>
        <ul class="ds-alert__list">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-ds.alert>
@endif
