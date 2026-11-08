<div class="kit-stack">
    <p class="kit-note">Each state has a word, a silhouette and a hue. Lot states first, then sale (settlement) states.</p>
    <div class="kit-row">
        @foreach (['live', 'ending', 'scheduled', 'sold', 'unsold', 'pending', 'cancelled', 'draft'] as $s)<x-ds.status :state="$s" />@endforeach
        <x-ds.status state="extended" :count="2" />
    </div>
    <div class="kit-row">
        @foreach (['awaiting_payment', 'initiated', 'paid', 'payment_overdue', 'failed', 'unmatched', 'expired'] as $s)<x-ds.status :state="$s" />@endforeach
    </div>
    <div class="kit-row">
        @foreach (['live', 'ending', 'scheduled', 'sold', 'unsold', 'pending', 'cancelled'] as $s)<x-ds.status :state="$s" size="sm" />@endforeach
    </div>
    <div class="kit-row"><x-ds.status state="pending" :long="true" /></div>
</div>
