<div class="kit-stack">
    <div class="kit-swatches">
        @foreach (['ground', 'surface', 'raised', 'sunken'] as $s)
            <div class="kit-swatch" style="background: var(--c-{{ $s }})"><span>{{ $s }}</span></div>
        @endforeach
        <div class="kit-swatch" style="background: var(--c-action); color: var(--c-on-action)"><span>action</span></div>
        <div class="kit-swatch" style="background: var(--c-live-bg); color: var(--c-live)"><span>live</span></div>
        <div class="kit-swatch" style="background: var(--c-urgent-bg); color: var(--c-urgent)"><span>urgent</span></div>
        <div class="kit-swatch" style="background: var(--c-notice-bg); color: var(--c-notice)"><span>notice</span></div>
        <div class="kit-swatch" style="background: var(--c-scheduled-bg); color: var(--c-scheduled)"><span>scheduled</span></div>
    </div>
    <h3>Heading three in Archivo</h3>
    <p>Body text in Archivo at sixteen pixels. A lot closes at 18:30 South African time and a late bid moves the close out by five minutes. <a href="#">A link looks like this</a>.</p>
    <p class="num" style="font-size: var(--t-xl); font-weight: 650">0 1 2 3 4 5 6 7 8 9 &nbsp; 1,000,000.00 &nbsp; 0O 0O</p>
    <p style="font-family: var(--f-mono); font-size: var(--t-lg); font-weight: 600">Lot 0407 &nbsp; 12:08:45 &nbsp; 0O 0O</p>
    <div class="kit-row">
        <x-ds.money amount="1250" size="money" />
        <x-ds.money amount="1250" size="xl" />
        <x-ds.money amount="1250.5" size="lg" />
        <x-ds.money amount="1250" />
        <x-ds.money amount="1250" size="sm" muted />
        <x-ds.money amount="1250" struck />
    </div>
    <div class="kit-row">
        <x-ds.brand :width="120" /> <x-ds.brand variant="mark" :width="64" />
        <x-ds.avatar name="Thandi Mokoena" /> <x-ds.avatar name="Pieter" :size="44" />
        <x-ds.badge>Neutral</x-ds.badge> <x-ds.badge tone="ink">Ink</x-ds.badge> <x-ds.badge tone="notice" icon="info">Test mode</x-ds.badge> <x-ds.badge tone="live">12</x-ds.badge>
    </div>
</div>
