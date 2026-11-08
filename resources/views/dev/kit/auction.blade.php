@php use App\Support\Display; @endphp
<div class="kit-stack">
    <div class="kit-row kit-row--top">
        <x-ds.countdown :ends="$lots['live']->ends_at" :now="$now" size="xl" stacked />
        <x-ds.countdown :ends="$lots['ending']->ends_at" :now="$now" size="lg" stacked />
        <x-ds.countdown :ends="$lots['scheduled']->ends_at" :starts="$lots['scheduled']->starts_at" :now="$now" size="md" stacked />
        <x-ds.countdown :ends="$now->copy()->subMinute()" :now="$now" size="md" stacked />
    </div>
    <div class="kit-stack kit-stack--tight">
        <x-ds.rail :starts="$lots['live']->starts_at" :ends="$lots['live']->ends_at" :now="$now" thick />
        <x-ds.rail :starts="$lots['ending']->starts_at" :ends="$lots['ending']->ends_at" :now="$now" thick />
        <x-ds.rail :starts="$lots['extended']->starts_at" :ends="$lots['extended']->ends_at" :extensions="2" :now="$now" thick />
    </div>
    <div class="kit-cols">
        <div>
            <p class="kit-note">Live, you lead, reserve not met</p>
            <x-ds.bid-ladder :auction="$lots['live']" your-bid="1250" :leading="true" />
        </div>
        <div>
            <p class="kit-note">Live, outbid, buy now available</p>
            <x-ds.bid-ladder :auction="$lots['buynow']" your-bid="400" />
        </div>
        <div>
            <p class="kit-note">Sold (the record of the close)</p>
            <x-ds.bid-ladder :auction="$lots['sold']" :interactive="false" />
        </div>
    </div>
    <div class="kit-row kit-row--top">
        <dl class="kit-statrow">
            <x-ds.stat label="Current bid" size="lg"><x-ds.money amount="1250" size="money" /></x-ds.stat>
            <x-ds.stat label="Bids" value="14" note="since 8 Nov, 09:00" />
            <x-ds.stat label="Time left" value="2h 14m" tone="urgent" />
        </dl>
        <x-ds.dl>
            <div class="ds-dl__row"><dt>Start price</dt><dd><x-ds.money amount="1000" size="sm" /></dd></div>
            <div class="ds-dl__row"><dt>Bid step</dt><dd><x-ds.money amount="50" size="sm" /></dd></div>
            <div class="ds-dl__row"><dt>Closes</dt><dd>{{ Display::time($lots['live']->ends_at, null, true) }}</dd></div>
        </x-ds.dl>
    </div>
</div>
