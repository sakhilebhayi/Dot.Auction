<div class="kit-stack">
    <x-ds.page-header title="Seller payments" description="Sales you made and money still to come." back="#" back-label="Back to my lots">
        <x-slot name="meta"><x-ds.status state="awaiting_payment" size="sm" /></x-slot>
        <x-slot name="actions"><x-ds.button size="sm" icon="plus">List a lot</x-ds.button></x-slot>
    </x-ds.page-header>
    <x-ds.section title="Sales" description="Most recent first." id="sales-{{ $scheme }}">
        <x-slot name="actions"><x-ds.button size="sm" variant="quiet">Export</x-ds.button></x-slot>
        <x-ds.table caption="Recent sales">
            <thead><tr><th scope="col">Lot</th><th scope="col">Status</th><th scope="col" class="num">Sold for</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
            <tbody>
                <tr><td data-label="Lot">0407 Rolled-top oak desk</td><td data-label="Status"><x-ds.status state="awaiting_payment" size="sm" /></td><td data-label="Sold for" class="num"><x-ds.money amount="4200" size="sm" /></td><td class="actions"><a href="#">Open</a></td></tr>
                <tr><td data-label="Lot">0412 Karoo landscape, oil</td><td data-label="Status"><x-ds.status state="paid" size="sm" /></td><td data-label="Sold for" class="num"><x-ds.money amount="12800" size="sm" /></td><td class="actions"><a href="#">Open</a></td></tr>
                <tr><td data-label="Lot">0419 Stand mixer</td><td data-label="Status"><x-ds.status state="payment_overdue" size="sm" /></td><td data-label="Sold for" class="num"><x-ds.money amount="2150.5" size="sm" /></td><td class="actions"><a href="#">Open</a></td></tr>
            </tbody>
        </x-ds.table>
    </x-ds.section>
    <x-ds.section tone="sunken" title="Nothing waiting on you">
        <x-ds.empty-state title="No sales yet" icon="receipt" body="When one of your lots sells, the sale appears here with its payment status.">
            <x-ds.button variant="quiet" size="sm">List a lot</x-ds.button>
        </x-ds.empty-state>
    </x-ds.section>
    <div aria-busy="true"><x-ds.skeleton shape="row" /><x-ds.skeleton shape="line" :lines="3" /></div>
</div>
