<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Http\Livewire\DeleteUserForm;
use Livewire\Livewire;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_accounts_can_be_deleted(): void
    {
        if (! Features::hasAccountDeletionFeatures()) {
            $this->markTestSkipped('Account deletion is not enabled.');
        }

        $this->actingAs($user = User::factory()->create());

        $component = Livewire::test(DeleteUserForm::class)
            ->set('password', 'password')
            ->call('deleteUser');

        // Anonymised, not removed: bids, sales and settlements belong to other people too (R-42 F-02).
        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Former member', $fresh->name);
        $this->assertTrue($fresh->isAnonymised());
    }

    public function test_correct_password_must_be_provided_before_account_can_be_deleted(): void
    {
        if (! Features::hasAccountDeletionFeatures()) {
            $this->markTestSkipped('Account deletion is not enabled.');
        }

        $this->actingAs($user = User::factory()->create());

        Livewire::test(DeleteUserForm::class)
            ->set('password', 'wrong-password')
            ->call('deleteUser')
            ->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }

    public function test_the_delete_copy_tells_the_truth_about_what_is_kept(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/user/profile')->assertOk()->getContent();

        // The account is anonymised, not wiped (R-42 F-02): the page must not promise a permanent deletion of everything.
        $this->assertStringNotContainsString('permanently deleted', $html);
        $this->assertStringNotContainsString('download any data', $html);
        $this->assertStringContainsString('Former member', $html);
    }

    public function test_every_dialog_on_the_profile_page_has_an_accessible_name(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/user/profile')->assertOk()->getContent();

        preg_match_all('/role="dialog"[^>]*aria-labelledby="([^"]+)"/s', $html, $named);
        $this->assertSame(substr_count($html, 'role="dialog"'), count($named[1]), 'A dialog has no aria-labelledby.');
        foreach ($named[1] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, 'aria-labelledby points at nothing: '.$id);
        }
    }
}
