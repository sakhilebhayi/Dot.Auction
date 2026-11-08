<button type="button" wire:click="toggle" wire:loading.attr="disabled" class="ds-lot__watch" @class(['is-on' => $watching])>
    <x-ds.icon name="bookmark" :size="16" :filled="$watching" />
    <span>{{ $watching ? 'Stop watching' : 'Watch' }}</span><span class="sr-only"> {{ $title }}</span>
</button>
