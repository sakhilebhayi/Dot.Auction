@if(session('status'))
    <div role="status" class="dot-alert dot-alert-ok">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div role="alert" id="error-summary" tabindex="-1" class="dot-alert dot-alert-error">
        <strong>{{ $errors->count() === 1 ? 'There is 1 problem to fix' : 'There are '.$errors->count().' problems to fix' }}</strong>
        <ul>
            @foreach($errors->messages() as $field => $messages)
                @foreach($messages as $message)
                    <li>
                        @if(in_array($field, ['title','description','category_id','starting_price','reserve_price','buy_now_price','bid_increment','starts_at','ends_at','images'], true))
                            <a href="#{{ $field }}">{{ $message }}</a>
                        @else
                            {{ $message }}
                        @endif
                    </li>
                @endforeach
            @endforeach
        </ul>
    </div>
@endif
@if($errors->any())
    <script>document.getElementById('error-summary')?.focus();</script>
@endif
