<div class="kit-stack">
    <x-ds.alert tone="info" title="Times are in South African time">Closing times follow your sale room, not your device.</x-ds.alert>
    <x-ds.alert tone="success" title="Bid placed" :dismissible="true">You lead at R1,300.00.</x-ds.alert>
    <x-ds.alert tone="notice" title="Reserve not met">The seller will decide what happens next.</x-ds.alert>
    <x-ds.alert tone="danger" title="Your bid was too low">The next valid bid is R1,300.00.</x-ds.alert>
    <div class="kit-row">
        <x-ds.button variant="quiet" data-dialog-open="kit-dialog-{{ $scheme }}">Open dialog</x-ds.button>
        <x-ds.confirm id="kit-confirm-{{ $scheme }}" action="#" method="DELETE" title="Delete this draft?" body="The photos go with it. This cannot be undone." confirm="Delete draft" icon="trash" variant="danger">Delete draft</x-ds.confirm>
        <x-ds.button variant="quiet" data-ds-toast='{"title":"Bid placed","body":"You lead at R1,300.00.","tone":"success"}'>Show a toast</x-ds.button>
        <x-ds.button variant="quiet" data-ds-toast='{"title":"Payment failed","body":"Nothing was charged.","tone":"danger"}'>Show an error toast</x-ds.button>
    </div>
    <x-ds.dialog id="kit-dialog-{{ $scheme }}" title="Confirm your bid" description="A bid is a promise to buy if you win.">
        <x-ds.input label="Your bid" name="dialog-bid-{{ $scheme }}" money value="1300.00" />
        <x-slot name="footer"><x-ds.button type="button">Place bid</x-ds.button><x-ds.button variant="quiet" data-dialog-close>Cancel</x-ds.button></x-slot>
    </x-ds.dialog>
</div>
