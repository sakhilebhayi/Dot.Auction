<x-app-layout title="Team settings" width="narrow" description="Your team's name, members and roles.">
    <x-slot name="header">Team settings</x-slot>

    <div class="jf-stack">
        @livewire('teams.update-team-name-form', ['team' => $team])

        @livewire('teams.team-member-manager', ['team' => $team])

        @if (Gate::check('delete', $team) && ! $team->personal_team)
            @livewire('teams.delete-team-form', ['team' => $team])
        @endif
    </div>
</x-app-layout>
