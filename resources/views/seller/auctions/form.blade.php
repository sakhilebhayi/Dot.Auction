{{--
  The listing form: create and edit. One field vocabulary (ds.input and friends), the error summary with jump links, the locked-terms explanation,
  times in the display zone. Behaviour lives in resources/js/seller.js and every part works without it (photos upload with a plain form post).
--}}
@php
    use App\Models\Auction;
    use App\Services\DisplayTime;
    use App\Support\Display;

    $editing = $auction->exists;
    $locked = $editing && $termsLocked;
    $lot = $editing ? Display::lotState($auction) : 'draft';
    $symbol = config('auction.currency_symbol');
    $minHours = max(1, intdiv((int) config('auction.listing.min_duration_minutes'), 60));
    $maxDays = (int) config('auction.listing.max_duration_days');
    $snipeWindow = (int) config('auction.snipe_window_minutes');
    $snipeExtension = (int) config('auction.snipe_extension_minutes');
    $snipeMax = (int) config('auction.snipe_max_extensions');
    $zone = DisplayTime::zone() === DisplayTime::DEFAULT_ZONE ? 'South Africa time ('.DisplayTime::abbreviation().')' : DisplayTime::label();
    $opensEditable = ! $auction->exists || $auction->status === 'draft' || $auction->isScheduled();
    $photoCount = $editing ? $auction->images->count() : 0;
    $photoRoom = max(0, $maxImages - $photoCount);
    $megabytes = number_format(config('auction.images.max_kilobytes') / 1024, 0);
    $imageError = $errors->first('images') ?: collect($errors->keys())->filter(fn ($k) => str_starts_with($k, 'images.'))->map(fn ($k) => $errors->first($k))->unique()->first();
    $value = fn (string $name) => old($name, $values[$name] ?? '');

    // What the card in the preview shows: the saved lot, or a stand-in for a lot that is not saved yet.
    if ($editing) {
        $preview = $auction;
    } else {
        $preview = new Auction;
        $preview->forceFill(['status' => 'draft', 'title' => 'Your lot title', 'starting_price' => '0.00', 'current_price' => '0.00', 'starts_at' => now(), 'ends_at' => now()->addDays(7), 'snipe_extensions' => 0]);
        $preview->setRelation('images', collect());
        $preview->setRelation('winningBid', null);
    }
@endphp
<x-app-layout :title="$editing ? 'Edit '.$auction->title : 'List a lot'" width="wide" :back="route('seller.auctions.index')" back-label="My lots">
    <x-slot name="header">{{ $editing ? 'Edit lot' : 'List a lot' }}</x-slot>
    @if ($editing)
        <x-slot name="meta"><x-ds.status :state="$lot" /></x-slot>
    @endif

    @include('seller.auctions._notices')

    <div class="sl-form">
        <div class="sl-form__main">
            @if ($locked)
                <x-ds.alert tone="info" title="The terms are locked" class="sl-gap">
                    A bid has been placed, so the price, reserve, closing time and other terms cannot change, for the buyers' sake. You can still update the description and manage photos.
                </x-ds.alert>

                <x-ds.section title="Locked terms" class="sl-section">
                    <x-ds.dl>
                        <div class="ds-dl__row"><dt>Title</dt><dd>{{ $auction->title }}</dd></div>
                        <div class="ds-dl__row"><dt>Category</dt><dd>{{ $auction->category->name ?? 'None' }}</dd></div>
                        <div class="ds-dl__row"><dt>Starting price</dt><dd><x-ds.money :amount="$auction->starting_price" /></dd></div>
                        <div class="ds-dl__row"><dt>Bid increment</dt><dd><x-ds.money :amount="$auction->bid_increment" /></dd></div>
                        <div class="ds-dl__row"><dt>Reserve price</dt><dd>@if ($auction->reserve_price)<x-ds.money :amount="$auction->reserve_price" />@else None @endif</dd></div>
                        <div class="ds-dl__row"><dt>Buy now price</dt><dd>@if ($auction->buy_now_price)<x-ds.money :amount="$auction->buy_now_price" />@else None @endif</dd></div>
                        <div class="ds-dl__row"><dt>Closes</dt><dd>{{ Display::time($auction->ends_at, 'j M Y, H:i', true) }}</dd></div>
                    </x-ds.dl>
                </x-ds.section>
            @else
                <p class="sl-lead">
                    @if (! $editing)
                        This saves a private draft. Nothing is visible to buyers until you publish it.
                    @elseif ($auction->status === 'draft')
                        This is a draft. Buyers cannot see it until you publish it.
                    @elseif ($auction->isScheduled())
                        Published, and opens {{ Display::time($auction->starts_at, 'j M Y, H:i', true) }}. Until it opens you can still change the terms.
                    @else
                        Live now. Until the first bid arrives you can still change the terms.
                    @endif
                </p>
            @endif

            <form id="listing-form" method="POST" action="{{ $editing ? route('seller.auctions.update', $auction) : route('seller.auctions.store') }}" novalidate data-listing-form>
                @csrf
                @if ($editing) @method('PUT') @endif

                @unless ($locked)
                    <x-ds.section title="The lot" class="sl-section">
                        <x-ds.input name="title" label="Title" required maxlength="120" autocomplete="off" data-count :value="$value('title')"
                            hint="A short, specific name that buyers would search for." />
                        <x-ds.select name="category_id" label="Category" required placeholder="Choose a category" :options="$categories" :value="(string) $value('category_id')" />
                    </x-ds.section>
                @endunless

                <x-ds.section :title="$locked ? 'Description' : null" class="sl-section">
                    <x-ds.textarea name="description" label="Description" optional rows="8" maxlength="5000" data-count :value="$value('description')"
                        hint="What it is, its condition and what is included. Keep it factual: buyers rely on it." />
                </x-ds.section>

                @unless ($locked)
                    <x-ds.section title="Price" description="Fixed once the first bid arrives." class="sl-section">
                        <div class="sl-pair">
                            <x-ds.input name="starting_price" label="Starting price" required money autocomplete="off" :value="$value('starting_price')"
                                hint="Bidding starts here. The first bid must beat it by one increment." />
                            <x-ds.input name="bid_increment" label="Bid increment" required money autocomplete="off" :value="$value('bid_increment')"
                                hint="How much higher than the current price each new bid must be." data-increment />
                        </div>
                        <p class="sl-suggest" data-suggest hidden>A common step is about 5%: <button type="button" class="ds-btn ds-btn--link" data-suggest-apply></button></p>
                        <div class="sl-pair">
                            <x-ds.input name="reserve_price" label="Reserve price" optional money autocomplete="off" :value="$value('reserve_price')"
                                hint="Confidential. Buyers see only whether it has been met, never the amount. If bidding ends below it, you decide whether to sell to the top bidder. At least the starting price." />
                            <x-ds.input name="buy_now_price" label="Buy now price" optional money autocomplete="off" :value="$value('buy_now_price')"
                                hint="Lets a buyer end the auction at once at this price. It goes away once bidding reaches it. At least one increment above the starting price and not below the reserve." />
                        </div>
                    </x-ds.section>

                    <x-ds.section title="When" :description="'Times are in '.$zone.'.'" class="sl-section">
                        <div class="sl-pair">
                            @if ($opensEditable)
                                <x-ds.input name="starts_at" label="Opens" type="datetime-local" optional :value="$value('starts_at')"
                                    hint="Leave blank to open as soon as you publish." />
                            @else
                                <div class="ds-field">
                                    <span class="ds-label">Opened</span>
                                    <p class="sl-static">{{ Display::time($auction->starts_at, 'j M Y, H:i', true) }}</p>
                                    <p class="ds-hint">An auction that has opened keeps its opening time.</p>
                                </div>
                            @endif
                            <x-ds.input name="ends_at" label="Closes" type="datetime-local" required :value="$value('ends_at')" data-closes
                                :hint="'At least '.$minHours.' hour'.($minHours === 1 ? '' : 's').' and at most '.$maxDays.' days after it opens.'" />
                        </div>
                        <div class="sl-picks" data-picks hidden data-now="{{ DisplayTime::inputValue(now()) }}">
                            <span class="sl-picks__label" id="picks-label">Close it after</span>
                            <div role="group" aria-labelledby="picks-label" class="sl-picks__row">
                                @foreach ([1 => '1 day', 3 => '3 days', 7 => '7 days', 14 => '14 days'] as $days => $label)
                                    @if ($days <= $maxDays)<button type="button" class="ds-chip" data-pick-days="{{ $days }}">{{ $label }}</button>@endif
                                @endforeach
                            </div>
                        </div>
                        @if ($snipeWindow > 0 && $snipeMax > 0)
                            <p class="sl-note"><x-ds.icon name="extend" :size="18" /> <span>A bid in the final {{ $snipeWindow }} {{ $snipeWindow === 1 ? 'minute' : 'minutes' }} moves the close {{ $snipeExtension }} {{ $snipeExtension === 1 ? 'minute' : 'minutes' }} later, up to {{ $snipeMax }} {{ $snipeMax === 1 ? 'time' : 'times' }}, so nobody is outbid in the last second.</span></p>
                        @endif
                    </x-ds.section>
                @endunless

                @unless ($editing)
                    <x-ds.section title="Photos" class="sl-section">
                        <p class="sl-lead">Save the draft first, then add up to {{ $maxImages }} photos on the next screen.</p>
                    </x-ds.section>
                @endunless

                <div class="sl-savebar">
                    <x-ds.button type="submit" :icon="$editing ? 'check' : 'arrow-right'">{{ $editing ? 'Save changes' : 'Save draft and add photos' }}</x-ds.button>
                    <x-ds.button :href="route('seller.auctions.index')" variant="quiet">{{ $editing ? 'Back to my lots' : 'Cancel' }}</x-ds.button>
                </div>
            </form>

            @if ($editing)
                <x-ds.section id="photos" title="Photos" :description="'Up to '.$maxImages.' photos. The first one is the cover, and they appear in the order they were added.'" class="sl-section sl-photos" data-photos>
                    <x-slot name="actions"><span class="sl-photos__count" data-photo-count>{{ $photoCount }} of {{ $maxImages }}</span></x-slot>

                    @if ($photoCount > 0)
                        <ul class="sl-grid">
                            @foreach ($auction->images as $image)
                                <li class="sl-grid__item">
                                    <figure>
                                        <img src="{{ $image->url() }}" width="{{ $image->width }}" height="{{ $image->height }}" loading="lazy" decoding="async"
                                             alt="{{ $auction->title }}, photo {{ $loop->iteration }} of {{ $loop->count }}">
                                        @if ($loop->first)<figcaption>Cover</figcaption>@endif
                                    </figure>
                                    <x-ds.confirm :action="route('seller.auctions.images.destroy', [$auction, $image])" method="DELETE" size="sm" icon="trash"
                                        title="Remove this photo?" body="It is taken off the lot and deleted. This cannot be undone." confirm="Remove photo" cancel="Keep photo">Remove<span class="sr-only"> photo {{ $loop->iteration }}</span></x-ds.confirm>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="sl-photos__none">No photos yet. Lots with photos get far more attention, so add at least one before you publish.</p>
                    @endif

                    @if ($photoRoom > 0)
                        <form method="POST" action="{{ route('seller.auctions.images.store', $auction) }}" enctype="multipart/form-data" novalidate class="sl-upload"
                              data-upload data-room="{{ $photoRoom }}" data-max-bytes="{{ (int) config('auction.images.max_kilobytes') * 1024 }}" data-max-mb="{{ $megabytes }}">
                            @csrf
                            <x-ds.field name="images" id="images" label="Add photos" :error="$imageError"
                                :hint="'JPG, PNG or WebP, up to '.$megabytes.' MB each. You can add '.$photoRoom.' more.'">
                                <div class="sl-drop" data-drop>
                                    <input id="images" name="images[]" type="file" multiple required accept="image/jpeg,image/png,image/webp" class="sl-drop__input"
                                        aria-describedby="images-hint @if ($imageError) images-error @endif" @if ($imageError) aria-invalid="true" @endif>
                                    <label for="images" class="sl-drop__face">
                                        <x-ds.icon name="upload" :size="24" />
                                        <span class="sl-drop__title">Choose photos</span>
                                        <span class="sl-drop__hint" data-drop-hint>or drop them here</span>
                                    </label>
                                </div>
                            </x-ds.field>
                            <ul class="sl-queue" data-queue aria-label="Photos ready to upload"></ul>
                            <div class="sl-progress" data-progress hidden>
                                <div class="sl-progress__bar" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-progress-fill></span></div>
                                <p class="sl-progress__text" data-progress-text aria-live="polite"></p>
                            </div>
                            <p class="sl-upload__problem ds-error" data-upload-error hidden role="alert"><x-ds.icon name="alert" :size="14" /> <span></span></p>
                            <x-ds.button type="submit" variant="quiet" icon="upload" data-upload-submit>Upload photos</x-ds.button>
                        </form>
                    @else
                        <p class="sl-note"><x-ds.icon name="info" :size="18" /> <span>This lot has the maximum of {{ $maxImages }} photos. Remove one to add another.</span></p>
                    @endif
                </x-ds.section>

                <x-ds.section id="status" title="Publishing" class="sl-section">
                    @if ($auction->status === 'draft')
                        <p class="sl-lead">This draft is private. Publishing makes it visible to buyers and opens bidding at the opening time{{ $opensEditable ? ' (right away if none is set)' : '' }}. Publishing uses what is saved, so save your changes first.</p>
                        <p class="sl-unsaved" data-unsaved hidden role="status"><x-ds.icon name="alert" :size="16" /> You have unsaved changes. Save them before you publish.</p>
                        <div class="sl-actionrow">
                            <x-ds.confirm :action="route('seller.auctions.publish', $auction)" variant="primary" tone="primary" icon="play"
                                :title="'Publish “'.$auction->title.'”?'"
                                body="Buyers will be able to see it and bidding opens at the opening time you set, or right away if you set none. Once someone bids, the price, reserve and closing time are locked."
                                confirm="Publish lot" cancel="Not yet">Publish lot</x-ds.confirm>
                            <x-ds.confirm :action="route('seller.auctions.destroy', $auction)" method="DELETE" variant="danger" icon="trash"
                                title="Delete this draft?" body="The draft and its photos are removed for good. This cannot be undone." confirm="Delete draft" cancel="Keep draft">Delete draft</x-ds.confirm>
                        </div>
                    @else
                        <p class="sl-lead">
                            This lot is {{ $auction->isScheduled() ? 'scheduled to open '.Display::time($auction->starts_at, 'j M Y, H:i', true) : 'live' }}.
                            <a class="sl-link" href="{{ route('auctions.show', $auction) }}">View the public page</a>.
                        </p>
                        @if ($locked)
                            <p class="ds-hint">It has bids, so it can no longer be cancelled.</p>
                        @else
                            <div class="sl-actionrow">
                                <x-ds.confirm :action="route('seller.auctions.cancel', $auction)" variant="danger" icon="ban"
                                    title="Cancel this lot?" body="Buyers will no longer be able to see it or bid. You can cancel only while nobody has bid, and it cannot be reopened." confirm="Cancel lot" cancel="Keep lot">Cancel lot</x-ds.confirm>
                            </div>
                            <p class="ds-hint">Possible only while it has no bids.</p>
                        @endif
                    @endif
                </x-ds.section>
            @endif
        </div>

        <aside class="sl-form__aside" aria-label="Preview">
            <h2 class="sl-aside__title">How buyers will see it</h2>
            <div class="sl-preview @unless ($editing) is-new @endunless" data-preview inert>
                <div class="ds-catalogue">
                    <x-ds.lot-card :auction="$preview" :compact="true" href="#" />
                </div>
            </div>
            <p class="ds-hint">{{ $editing ? 'The saved lot, as it appears in browse.' : 'The card updates as you type. The cover is the first photo you add.' }}</p>
        </aside>
    </div>
</x-app-layout>
