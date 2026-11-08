<button
    type="button"
    wire:click="toggle"
    class="dot-btn {{ $watching ? 'dot-btn-primary' : 'dot-btn-ghost' }}"
    title="{{ $watching ? 'Remove from watchlist' : 'Add to watchlist' }}"
>
    <x-ds.icon :name="$watching ? 'bookmark' : 'bookmark'" :size="16" :filled="$watching" />
    {{ $watching ? 'Watching' : 'Watch' }}
</button>
