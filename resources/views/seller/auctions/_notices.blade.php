{{-- Flash message and error summary for the seller pages. The summary takes focus when it appears and each message jumps to its field. --}}
@php
    $fieldNames = ['title', 'description', 'category_id', 'starting_price', 'reserve_price', 'buy_now_price', 'bid_increment', 'starts_at', 'ends_at'];
@endphp
@if (session('status'))
    <x-ds.alert tone="success" role="status" class="sl-gap" dismissible>{{ session('status') }}</x-ds.alert>
@endif

@if ($errors->any())
    <x-ds.alert tone="danger" id="error-summary" tabindex="-1" data-autofocus class="sl-gap" :title="$errors->count() === 1 ? 'There is 1 problem to fix' : 'There are '.$errors->count().' problems to fix'">
        <ul class="sl-errors">
            @foreach ($errors->messages() as $field => $messages)
                @foreach ($messages as $message)
                    <li>
                        @if (in_array($field, $fieldNames, true) || str_starts_with($field, 'images'))
                            <a href="#{{ str_starts_with($field, 'images') ? 'images' : $field }}" data-jump>{{ $message }}</a>
                        @else
                            {{ $message }}
                        @endif
                    </li>
                @endforeach
            @endforeach
        </ul>
    </x-ds.alert>
@endif
