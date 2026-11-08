{{-- The account dropdown: profile, teams, theme, log out. Used in the desktop top bar. --}}
@php
    $teams = Laravel\Jetstream\Jetstream::hasTeamFeatures() && auth()->user()->currentTeam;
@endphp
<x-ds.dropdown label="Account menu" align="end">
    <x-slot name="trigger">
        <x-ds.avatar :name="auth()->user()->name" :size="32" decorative />
        <span class="sh-user__name">{{ auth()->user()->name }}</span><span class="sr-only">, account menu</span>
        <x-ds.icon name="chevron-down" :size="16" />
    </x-slot>
    <p class="ds-menu__label">Signed in as {{ auth()->user()->email }}</p>
    <x-ds.dropdown-item :href="route('profile.show')" icon="user">Profile and security</x-ds.dropdown-item>
    @if ($teams)
        <hr class="ds-menu__rule">
        <p class="ds-menu__label">Team: {{ auth()->user()->currentTeam->name }}</p>
        <x-ds.dropdown-item :href="route('teams.show', auth()->user()->currentTeam->id)" icon="users">Team settings</x-ds.dropdown-item>
        @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
            <x-ds.dropdown-item :href="route('teams.create')" icon="plus">Create team</x-ds.dropdown-item>
        @endcan
        @if (auth()->user()->allTeams()->count() > 1)
            @foreach (auth()->user()->allTeams() as $switchTeam)
                <x-ds.dropdown-item method="PUT" :action="route('current-team.update')" :fields="['team_id' => $switchTeam->id]" :icon="auth()->user()->isCurrentTeam($switchTeam) ? 'check' : 'users'">
                    {{ $switchTeam->name }}@if (auth()->user()->isCurrentTeam($switchTeam)) <span class="sr-only">(current)</span>@endif
                </x-ds.dropdown-item>
            @endforeach
        @endif
    @endif
    <hr class="ds-menu__rule">
    <x-ds.dropdown-item method="POST" :action="route('logout')" icon="logout">Log out</x-ds.dropdown-item>
</x-ds.dropdown>
