@php
    use Laravel\Jetstream\Jetstream;
    $roleOptions = collect($this->roles)->mapWithKeys(fn ($role) => [$role->key => [$role->name, $role->description]])->all();
@endphp
<div class="tm">
    @if (Gate::check('addTeamMember', $team))
        <x-form-section submit="addTeamMember">
            <x-slot name="title">Add a team member</x-slot>
            <x-slot name="description">Enter their email and choose what they can do. They are sent an invitation and join when they accept it.</x-slot>

            <x-slot name="form">
                <div class="col-span-6 sm:col-span-4">
                    <x-ds.input name="email" id="member-email" label="Email" type="email" wire:model="addTeamMemberForm.email" autocomplete="off" :error="$errors->first('email')" />
                </div>

                @if (count($roleOptions) > 0)
                    <div class="col-span-6 lg:col-span-5">
                        <x-ds.radio-group legend="Role" name="addTeamMemberForm.role" :options="$roleOptions" :value="$addTeamMemberForm['role'] ?? null" wire:model="addTeamMemberForm.role" class="tm-roles" :error="$errors->first('role')" />
                    </div>
                @endif
            </x-slot>

            <x-slot name="actions">
                <x-action-message class="me-3" on="saved">Invitation sent.</x-action-message>
                <x-ds.button type="submit">Add</x-ds.button>
            </x-slot>
        </x-form-section>
    @endif

    @if ($team->teamInvitations->isNotEmpty() && Gate::check('addTeamMember', $team))
        <x-action-section>
            <x-slot name="title">Pending invitations</x-slot>
            <x-slot name="description">They have been emailed and join by accepting it.</x-slot>
            <x-slot name="content">
                <ul class="tm-list" aria-label="Pending invitations">
                    @foreach ($team->teamInvitations as $invitation)
                        <li class="tm-row">
                            <span class="tm-row__who">{{ $invitation->email }}</span>
                            @if (Gate::check('removeTeamMember', $team))
                                <x-ds.button variant="quiet" size="sm" wire:click="cancelTeamInvitation({{ $invitation->id }})">Cancel invitation<span class="sr-only"> for {{ $invitation->email }}</span></x-ds.button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-slot>
        </x-action-section>
    @endif

    <x-action-section>
        <x-slot name="title">People on this team</x-slot>
        <x-slot name="description">The owner and everyone added to the team, with what each can do.</x-slot>
        <x-slot name="content">
            <ul class="tm-list" aria-label="People on this team">
                <li class="tm-row">
                    <x-ds.avatar :name="$team->owner->name" :size="32" />
                    <span class="tm-row__who">{{ $team->owner->name }}<span class="tm-row__sub">{{ $team->owner->email }}</span></span>
                    <span class="tm-row__role">Owner</span>
                </li>
                @foreach ($team->users->sortBy('name') as $user)
                    @php $roleName = Jetstream::hasRoles() ? (Jetstream::findRole($user->membership->role)->name ?? '') : ''; @endphp
                    <li class="tm-row">
                        <x-ds.avatar :name="$user->name" :size="32" />
                        <span class="tm-row__who">{{ $user->name }}<span class="tm-row__sub">{{ $user->email }}</span></span>
                        @if ($roleName !== '')<span class="tm-row__role">{{ $roleName }}</span>@endif
                        <span class="tm-row__act">
                            @if (Gate::check('updateTeamMember', $team) && Jetstream::hasRoles())
                                <x-ds.button variant="quiet" size="sm" wire:click="manageRole('{{ $user->id }}')">Change role<span class="sr-only"> for {{ $user->name }}</span></x-ds.button>
                            @endif
                            @if ($this->user->id === $user->id)
                                <x-ds.button variant="quiet" size="sm" wire:click="$toggle('confirmingLeavingTeam')">Leave team</x-ds.button>
                            @elseif (Gate::check('removeTeamMember', $team))
                                <x-ds.button variant="quiet" size="sm" wire:click="confirmTeamMemberRemoval('{{ $user->id }}')">Remove<span class="sr-only"> {{ $user->name }}</span></x-ds.button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            @if ($team->users->isEmpty())
                <p class="tm-empty">Nobody else is on this team yet.@if (Gate::check('addTeamMember', $team)) Add someone above.@endif</p>
            @endif
        </x-slot>
    </x-action-section>

    <!-- Role Management Modal -->
    <x-dialog-modal wire:model.live="currentlyManagingRole">
        <x-slot name="title">
            Change role
        </x-slot>

        <x-slot name="content">
            <x-ds.radio-group legend="Role" name="currentRole" :options="$roleOptions" :value="$currentRole" wire:model="currentRole" class="tm-roles" />
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="stopManagingRole" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-button class="ms-3" wire:click="updateRole" wire:loading.attr="disabled">
                {{ __('Save') }}
            </x-button>
        </x-slot>
    </x-dialog-modal>

    <!-- Leave Team Confirmation Modal -->
    <x-confirmation-modal wire:model.live="confirmingLeavingTeam">
        <x-slot name="title">
            {{ __('Leave Team') }}
        </x-slot>

        <x-slot name="content">
            {{ __('Are you sure you would like to leave this team?') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingLeavingTeam')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button class="ms-3" wire:click="leaveTeam" wire:loading.attr="disabled">
                {{ __('Leave') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>

    <!-- Remove Team Member Confirmation Modal -->
    <x-confirmation-modal wire:model.live="confirmingTeamMemberRemoval">
        <x-slot name="title">
            {{ __('Remove Team Member') }}
        </x-slot>

        <x-slot name="content">
            {{ __('Are you sure you would like to remove this person from the team?') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingTeamMemberRemoval')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button class="ms-3" wire:click="removeTeamMember" wire:loading.attr="disabled">
                {{ __('Remove') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>
</div>
