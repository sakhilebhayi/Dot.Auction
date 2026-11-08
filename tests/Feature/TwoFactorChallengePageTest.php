<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sign-in pages load no Alpine, so "Use a recovery code" has to work as a plain link: an x-data toggle there was a dead
 * button, and a person who lost their phone could not reach the recovery-code field at all (found by the browser e2e suite).
 */
class TwoFactorChallengePageTest extends TestCase
{
    use RefreshDatabase;

    private function challenged(): User
    {
        return User::factory()->create([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['aaaaaaaaaa-bbbbbbbbbb'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_the_challenge_asks_for_an_authentication_code_and_links_to_the_recovery_form(): void
    {
        $user = $this->challenged();

        $html = $this->withSession(['login.id' => $user->id])->get('/two-factor-challenge')->assertOk()
            ->assertSee('Authentication code')->assertDontSee('Recovery code')->getContent();

        $this->assertStringContainsString('/two-factor-challenge?recovery=1', $html);
        $this->assertStringNotContainsString('x-data', $html);
    }

    public function test_the_recovery_form_is_reachable_without_javascript(): void
    {
        $user = $this->challenged();

        $this->withSession(['login.id' => $user->id])->get('/two-factor-challenge?recovery=1')->assertOk()
            ->assertSee('name="recovery_code"', false)->assertDontSee('name="code"', false)->assertSee('Use an authentication code');
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        $user = $this->challenged();

        $this->withSession(['login.id' => $user->id])->post('/two-factor-challenge', ['recovery_code' => 'aaaaaaaaaa-bbbbbbbbbb'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }
}
