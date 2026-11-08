{{-- Watch or stop watching a lot. The accessible name carries the lot ("Watch Signed guitar"), the state is a word on the button, and a change is announced politely. --}}
<div class="lot-watch">
    <button type="button" class="ds-btn ds-btn--quiet" wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle">
        <x-ds.icon name="bookmark" :size="18" :filled="$watching" />
        <span class="ds-btn__label">{{ $watching ? 'Watching' : 'Watch' }}<span class="sr-only"> {{ $auction->title }}{{ $watching ? ', press to stop watching' : '' }}</span></span>
    </button>
    <p class="sr-only" role="status" aria-live="polite" aria-atomic="true">{{ $message }}</p>
</div>
