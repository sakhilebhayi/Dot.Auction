<?php

namespace Tests\Feature\R42;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Jetstream\CreateTeam;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Http\Livewire\CreateTeamForm;
use Laravel\Jetstream\Http\Livewire\DeleteUserForm;
use Laravel\Jetstream\Http\Livewire\LogoutOtherBrowserSessionsForm;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Laravel\Jetstream\Http\Livewire\TwoFactorAuthenticationForm;
use Laravel\Jetstream\Http\Livewire\UpdatePasswordForm;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm;
use Laravel\Jetstream\Http\Livewire\UpdateTeamNameForm;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Design jury 2, item 15: the framework wording ("The title field is required.") reached people. Every form is submitted empty (or, where
 * empty is not the mistake, with the mistake people make) and every message that comes back must read like a sentence to a person:
 * no "field", no field name written in code (category_id, current_password, state.name), no dotted keys.
 */
class ValidationWordingTest extends TestCase
{
    use RefreshDatabase;

    /** @param iterable<int, string> $messages */
    private function assertHuman(iterable $messages, string $form): void
    {
        $messages = collect($messages)->flatten()->values();
        $this->assertNotEmpty($messages, "{$form}: submitting it empty produced no message at all");

        foreach ($messages as $message) {
            $this->assertDoesNotMatchRegularExpression('/\bfield\b/i', $message, "{$form}: \"{$message}\" reads like the framework");
            $this->assertDoesNotMatchRegularExpression('/\b[a-z]+_[a-z_]+\b/', $message, "{$form}: \"{$message}\" names a field as code");
            $this->assertDoesNotMatchRegularExpression('/\b[a-zA-Z]+\.[a-z]+\b/', $message, "{$form}: \"{$message}\" names a dotted key");
            $this->assertDoesNotMatchRegularExpression('/^validation\./', $message, "{$form}: \"{$message}\" is an untranslated key");
        }
    }

    public function test_the_listing_form_empty(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();

        $errors = $this->actingAs($seller)->post(route('seller.auctions.store'), [])->assertSessionHasErrors()->baseResponse->getSession()->get('errors')->getBag('default')->all();

        $this->assertHuman($errors, 'list a lot');
        $this->assertContains('Enter a title for the lot.', $errors);
        $this->assertContains('Choose a category.', $errors);
        $this->assertContains('Enter the starting price.', $errors);
        $this->assertContains('Enter when the lot closes.', $errors);
    }

    public function test_the_listing_form_on_edit_with_the_title_removed(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $lot = Auction::factory()->create(['seller_id' => $seller->id, 'status' => 'draft']);

        $errors = $this->actingAs($seller)->put(route('seller.auctions.update', $lot), ['title' => '', 'category_id' => '', 'starting_price' => '', 'bid_increment' => '', 'ends_at' => ''])
            ->assertSessionHasErrors()->baseResponse->getSession()->get('errors')->getBag('default')->all();

        $this->assertHuman($errors, 'edit a lot');
    }

    public function test_the_photo_upload_empty(): void
    {
        Storage::fake('auction-images');
        $seller = User::factory()->withPersonalTeam()->create();
        $lot = Auction::factory()->create(['seller_id' => $seller->id, 'status' => 'draft']);

        $response = $this->actingAs($seller)->postJson(route('seller.auctions.images.store', $lot), []);

        $response->assertStatus(422);
        $this->assertHuman(collect($response->json('errors') ?? [])->flatten()->all() ?: [$response->json('message')], 'upload photos');
    }

    public function test_sign_in_and_the_password_reset_pages_empty(): void
    {
        $login = $this->post('/login', [])->assertSessionHasErrors()->baseResponse->getSession()->get('errors')->all();
        $this->assertHuman($login, 'sign in');

        $forgot = $this->post('/forgot-password', [])->assertSessionHasErrors()->baseResponse->getSession()->get('errors')->all();
        $this->assertHuman($forgot, 'forgot password');

        $reset = $this->post('/reset-password', [])->assertSessionHasErrors()->baseResponse->getSession()->get('errors')->all();
        $this->assertHuman($reset, 'reset password');
    }

    public function test_confirm_password_and_the_two_step_challenge_empty(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $confirm = $this->actingAs($user)->post('/user/confirm-password', [])->assertSessionHasErrors()->baseResponse->getSession()->get('errors')->all();
        $this->assertHuman($confirm, 'confirm password');
    }

    public function test_create_account_empty(): void
    {
        try {
            (new CreateNewUser)->create([]);
            $this->fail('an empty account should not be created');
        } catch (ValidationException $e) {
            $this->assertHuman($e->validator->errors()->all(), 'create account');
        }
    }

    public function test_the_profile_form_empty(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $component = Livewire::test(UpdateProfileInformationForm::class)->set('state.name', '')->set('state.email', '')->call('updateProfileInformation');
        $this->assertHuman($component->errors()->all(), 'your details');
    }

    public function test_the_password_form_empty_and_wrong(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $empty = Livewire::test(UpdatePasswordForm::class)->call('updatePassword');
        $this->assertHuman($empty->errors()->all(), 'change password (empty)');

        $mismatch = Livewire::test(UpdatePasswordForm::class)
            ->set('state', ['current_password' => 'not-the-password', 'password' => 'a-long-new-password-9', 'password_confirmation' => 'different-9'])
            ->call('updatePassword');
        $messages = $mismatch->errors()->all();
        $this->assertHuman($messages, 'change password (wrong)');
        $this->assertContains('That is not your current password.', $messages);
        $this->assertContains('The two passwords do not match.', $messages);
    }

    public function test_the_delete_account_and_other_devices_forms_without_a_password(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());
        config(['session.driver' => 'database']); // the form does nothing on any other driver

        $this->assertHuman(Livewire::test(DeleteUserForm::class)->set('password', '')->call('deleteUser')->errors()->all(), 'delete account');
        $this->assertHuman(Livewire::test(LogoutOtherBrowserSessionsForm::class)->set('password', '')->call('logoutOtherBrowserSessions')->errors()->all(), 'sign out other devices');
    }

    public function test_the_two_step_code_form_empty(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());
        $this->withSession(['auth.password_confirmed_at' => time()]);

        $component = Livewire::test(TwoFactorAuthenticationForm::class)->call('enableTwoFactorAuthentication')->set('code', '')->call('confirmTwoFactorAuthentication');
        $this->assertHuman($component->errors()->all(), 'two-step code');
    }

    public function test_the_team_forms_empty(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $this->assertHuman(Livewire::test(CreateTeamForm::class)->set('state.name', '')->call('createTeam')->errors()->all(), 'create team');
        $this->assertHuman(Livewire::test(UpdateTeamNameForm::class, ['team' => $user->currentTeam])->set('state.name', '')->call('updateTeamName')->errors()->all(), 'team name');

        $member = Livewire::test(TeamMemberManager::class, ['team' => $user->currentTeam])->set('addTeamMemberForm', ['email' => '', 'role' => null])->call('addTeamMember');
        $this->assertHuman($member->errors()->all(), 'add a team member');
        $this->assertContains('Enter your email address.', $member->errors()->all());

        try {
            (new CreateTeam)->create($user, []);
            $this->fail('an empty team should not be created');
        } catch (ValidationException $e) {
            $this->assertHuman($e->validator->errors()->all(), 'create team action');
        }
    }

    public function test_the_bid_form_empty(): void
    {
        $lot = Auction::factory()->create();
        $bidder = User::factory()->withPersonalTeam()->create();

        $component = Livewire::actingAs($bidder)->test(\App\Livewire\Auctions\BidPanel::class, ['auction' => $lot])->set('bidAmount', '')->call('review');

        $this->assertStringNotContainsString('field', strip_tags($component->html()));
        $component->assertSee('Enter the amount you want to bid.');
    }
}
