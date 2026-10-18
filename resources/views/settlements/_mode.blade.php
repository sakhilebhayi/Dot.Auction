{{-- Mode badge. Test keys: TEST MODE. A live key that was deliberately allowed: LIVE MODE. Nothing otherwise. --}}
@if($payments->isTestMode())
    <span class="dot-mode" title="Paystack test keys are in use">TEST MODE</span>
@elseif($payments->mode() === 'live')
    <span class="dot-mode dot-mode-live" title="Real money moves">LIVE MODE</span>
@endif
