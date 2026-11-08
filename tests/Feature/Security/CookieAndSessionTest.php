<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The session cookie's flags, and the settings the production host relies on resolving the way the deployment guide says.
 */
class CookieAndSessionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $env  variables set for the fresh process
     * @param  array<int, string>  $unset  variables removed from it
     * @return mixed the config value that process would see
     */
    private function configIn(string $key, array $env, array $unset = []): mixed
    {
        $prefix = 'env';
        foreach ($unset as $name) {
            $prefix .= ' -u '.$name;
        }
        foreach ($env as $name => $value) {
            $prefix .= ' '.$name.'='.escapeshellarg($value);
        }

        return json_decode(trim((string) shell_exec($prefix.' php '.escapeshellarg(base_path('tests/Support/config-value.php')).' '.escapeshellarg($key).' 2>/dev/null')), true);
    }

    public function test_the_session_cookie_is_http_only_same_site_and_secure_over_https(): void
    {
        config(['session.secure' => true]);
        $response = $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);

        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertNotNull($cookie, 'a session cookie is issued');
        $this->assertTrue($cookie->isHttpOnly(), 'script cannot read the session cookie');
        $this->assertTrue($cookie->isSecure(), 'it travels over HTTPS only');
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_the_secure_flag_follows_the_apps_https_url_unless_the_environment_says_otherwise(): void
    {
        $this->assertTrue($this->configIn('session.secure', ['APP_URL' => 'https://auction.example.test'], ['SESSION_SECURE_COOKIE']), 'an https APP_URL turns Secure on by itself');
        $this->assertNotTrue($this->configIn('session.secure', ['APP_URL' => 'http://auction.example.test'], ['SESSION_SECURE_COOKIE']));
        $this->assertFalse($this->configIn('session.secure', ['APP_URL' => 'https://auction.example.test', 'SESSION_SECURE_COOKIE' => 'false']));
        $this->assertTrue($this->configIn('session.http_only', [], ['SESSION_HTTP_ONLY']));
        $this->assertSame('lax', $this->configIn('session.same_site', [], ['SESSION_SAME_SITE']));
    }

    public function test_the_content_security_policy_is_enforced_unless_someone_asks_for_report_only(): void
    {
        $this->assertStringContainsString("env('SECURITY_CSP_REPORT_ONLY', false)", (string) file_get_contents(config_path('security.php')), 'the shipped default is enforced');
        $this->assertSame(false, $this->configIn('security.csp_report_only', ['SECURITY_CSP_REPORT_ONLY' => 'false']));
        $this->assertSame(true, $this->configIn('security.csp_report_only', ['SECURITY_CSP_REPORT_ONLY' => 'true']));
        $this->assertStringNotContainsString('SECURITY_CSP_REPORT_ONLY=true', (string) file_get_contents(base_path('.env.example')), '.env.example must not ship the policy as report-only');
    }

    public function test_an_unknown_origin_gets_no_session_authenticated_api_access(): void
    {
        $this->getJson('/api/user', ['Origin' => 'https://evil.example'])->assertUnauthorized();
    }
}
