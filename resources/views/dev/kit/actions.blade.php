<div class="kit-stack">
    <div class="kit-row">
        <x-ds.button>Place bid</x-ds.button>
        <x-ds.button variant="quiet">Watch lot</x-ds.button>
        <x-ds.button variant="danger" icon="trash">Cancel lot</x-ds.button>
        <x-ds.button variant="link">View history</x-ds.button>
        <x-ds.button :loading="true">Placing bid</x-ds.button>
        <x-ds.button :disabled="true" variant="quiet">Unavailable</x-ds.button>
    </div>
    <div class="kit-row">
        <x-ds.button size="sm" icon="plus">Small</x-ds.button>
        <x-ds.button size="lg" icon-right="arrow-right">Large</x-ds.button>
        <x-ds.icon-button icon="bell" label="Notifications" badge="3" badge-label="3 unread" />
        <x-ds.icon-button icon="search" label="Search" />
        <x-ds.theme-toggle />
    </div>
    <div class="kit-row">
        <x-ds.dropdown label="Account menu" align="start">
            <x-slot name="trigger"><x-ds.avatar name="Sipho Nkosi" decorative /> Sipho Nkosi <x-ds.icon name="chevron-down" :size="16" /></x-slot>
            <x-ds.dropdown-item href="#" icon="user">Profile and security</x-ds.dropdown-item>
            <x-ds.dropdown-item href="#" icon="users">Team settings</x-ds.dropdown-item>
            <hr class="ds-menu__rule">
            <x-ds.dropdown-item href="#" icon="logout" danger>Log out</x-ds.dropdown-item>
        </x-ds.dropdown>
        <x-ds.chip href="#" :current="true" :count="12">Live</x-ds.chip>
        <x-ds.chip href="#" :count="3">Scheduled</x-ds.chip>
        <x-ds.chip href="#">Closing soon</x-ds.chip>
    </div>
    <x-ds.tabs label="Lot sections" :tabs="[['label' => 'Bids', 'href' => '#', 'current' => true, 'count' => 14], ['label' => 'Details', 'href' => '#'], ['label' => 'Shipping', 'href' => '#']]" />
    <x-ds.tabset label="In-page tabs" :tabs="['one' => 'Description', 'two' => 'Condition']">
        <x-ds.tab-panel name="one" heading="Description">A rolled-top oak writing desk, 1.4 m wide. Sample copy for the kit.</x-ds.tab-panel>
        <x-ds.tab-panel name="two" heading="Condition">Light wear on the top. Sample copy for the kit.</x-ds.tab-panel>
    </x-ds.tabset>
</div>
