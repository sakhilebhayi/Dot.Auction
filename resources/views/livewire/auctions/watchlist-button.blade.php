<button
    type="button"
    wire:click="toggle"
    class="dot-btn {{ $watching ? 'dot-btn-primary' : 'dot-btn-ghost' }}"
    title="{{ $watching ? 'Remove from watchlist' : 'Add to watchlist' }}"
>
    <span class="material-symbols-rounded" style="font-size:16px;">{{ $watching ? 'bookmark' : 'bookmark_border' }}</span>
    {{ $watching ? 'Watching' : 'Watch' }}
</button>
