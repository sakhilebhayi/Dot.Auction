@component('mail::message')
{{-- The team name is typed by a stranger and this mail is sent in this app's name: its Markdown characters are escaped so a name such as "[Verify your account](https://...)" stays words and cannot become a link. --}}
{{ __('You have been invited to join the :team team!', ['team' => preg_replace('/([\\\\`*_{}\[\]()<>#+\-.!|~:@])/', '\\\\$1', (string) $invitation->team->name)]) }}

@if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::registration()))
{{ __('If you do not have an account, you may create one by clicking the button below. After creating an account, you may click the invitation acceptance button in this email to accept the team invitation:') }}

@component('mail::button', ['url' => route('register')])
Create account
@endcomponent

{{ __('If you already have an account, you may accept this invitation by clicking the button below:') }}

@else
{{ __('You may accept this invitation by clicking the button below:') }}
@endif


@component('mail::button', ['url' => $acceptUrl])
Accept invitation
@endcomponent

{{ __('If you did not expect to receive an invitation to this team, you may discard this email.') }}
@endcomponent
