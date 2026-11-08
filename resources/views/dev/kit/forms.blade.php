<form class="kit-stack" data-ds-noop>
    <x-ds.input label="Your bid" name="bid-{{ $scheme }}" money hint="The next valid bid is R1,300.00." value="1300.00" data-ds-bid-input />
    <x-ds.input label="Title" name="title-{{ $scheme }}" placeholder="Rolled-top oak desk" hint="Buyers see this first." />
    <x-ds.input label="Reserve price" name="reserve-{{ $scheme }}" money optional error="The reserve must be higher than the start price." value="500" />
    <x-ds.textarea label="Description" name="desc-{{ $scheme }}" :rows="3" optional />
    <x-ds.select label="Category" name="cat-{{ $scheme }}" placeholder="Choose one" :options="['1' => 'Furniture', '2' => 'Art', '3' => 'Electronics']" value="2" />
    <x-ds.checkbox label="Notify me when I am outbid" name="outbid-{{ $scheme }}" :checked="true" hint="By email and in the bell." />
    <x-ds.checkbox label="I accept the terms" name="terms-{{ $scheme }}" error="You need to accept the terms to continue." />
    <x-ds.radio-group legend="Closing time" name="close-{{ $scheme }}" value="3" :options="['1' => 'Tonight at 20:00', '3' => ['In 3 days', 'Ends on Wednesday at 18:00'], '7' => 'In 7 days']" />
    <x-ds.toggle label="Email me about closing lots" name="mail-{{ $scheme }}" :checked="true" />
    <div class="kit-row"><x-ds.button type="button">Save lot</x-ds.button><x-ds.button type="button" variant="quiet">Cancel</x-ds.button></div>
</form>
