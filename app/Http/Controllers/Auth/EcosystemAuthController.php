<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\User;
use App\Services\InfoDot\CompanyEntitlement;
use App\Services\InfoDot\IdentityClient;
use App\Services\InfoDot\InfoDotUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EcosystemAuthController extends Controller
{
    /** A GET (an old link with the token in the URL) is not honoured: the token is never read. */
    public function legacy(): RedirectResponse
    {
        return redirect()->to('/login');
    }

    /**
     * Sign-in from InfoDot. The token arrives in the POST body of a form InfoDot's launch page posts here, so it
     * is never in a URL (address bar, history, access logs, Referer). A refused sign-in lands on the plain login
     * page: the credential is not shown, echoed, flashed or logged. A GET with ?token= is no longer honoured
     * (the route sends it to the login page), and a token in the query string of a POST is ignored.
     */
    public function handle(Request $request): Response
    {
        if ($request->isMethod('post') && ! $this->postedFromInfoDot($request)) {
            return $this->refused();
        }

        try {
            return app()->call([$this, 'redeem'], ['request' => $request]);
        } catch (HttpExceptionInterface $e) {
            return $this->refused($e->getStatusCode() === 503);
        }
    }

    /**
     * Login CSRF: a form on another site could sign a visitor in as someone else (the attacker's own valid
     * token). A browser names the page that posted a cross-site form in Origin, so only InfoDot's launch page,
     * on this app's parent domain (tasks.infodot.co.za -> infodot.co.za), is accepted.
     */
    private function postedFromInfoDot(Request $request): bool
    {
        $origin = (string) $request->headers->get('Origin', '');
        $host = $origin !== '' && $origin !== 'null'
            ? parse_url($origin, PHP_URL_HOST)
            : parse_url((string) $request->headers->get('Referer', ''), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return in_array($request->headers->get('Sec-Fetch-Site'), ['same-origin', 'same-site'], true);
        }

        $host = strtolower($host);

        // When this app knows InfoDot's address, exactly that origin is accepted, not a sibling subdomain.
        $infodot = parse_url((string) config('ecosystem.infodot.redeem_url', ''), PHP_URL_HOST);

        if (is_string($infodot) && $infodot !== '') {
            return $host === strtolower($infodot);
        }

        // An app without that setting falls back to its own parent domain.
        $self = strtolower($request->getHost());
        $parent = str_contains($self, '.') ? substr($self, strpos($self, '.') + 1) : $self;

        return $host === $self || $host === $parent || str_ends_with($host, '.'.$parent);
    }

    private function refused(bool $unavailable = false): RedirectResponse
    {
        return redirect('/login', 303)->with('status', $unavailable
            ? 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.'
            : 'We could not sign you in through InfoDot. Please open this app again from InfoDot.');
    }

    public function redeem(Request $request, IdentityClient $infoDot, ProvisionUserFromInfoDot $provision, SyncTeamsFromInfoDot $syncTeams, CompanyEntitlement $entitlement): Response
    {
        $token = $request->post('token');

        abort_unless(is_string($token) && $token !== '', 403);

        $user = $this->userFromLocalToken($token);
        $said = null;

        // Production gives each Dot app its own database, so InfoDot's token is not
        // in ours: ask InfoDot to prove it.
        if (! $user && $infoDot->enabled()) {
            try {
                $identity = $infoDot->redeem($token);
            } catch (InfoDotUnavailable) {
                abort(503, 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.');
            }

            $said = $identity ? $this->entitlementSaid($identity) : null;

            // InfoDot answered about entitlement and no company (or personal workspace) holds this platform: nobody is
            // signed in and no account or company is created. A plain page, not a redirect, so there is no loop.
            if ($said !== null && $said['companies'] === [] && ! $said['personal']) {
                return $entitlement->refusal($request, $said['reason'], $said['name']);
            }

            $user = $identity ? $provision($identity) : null;

            if ($user && $identity) {
                $syncTeams($user, $identity);
            }
        }

        abort_if(! $user, 403);

        // Auth::login() also regenerates the session id (no fixation).
        Auth::login($user);

        // What InfoDot said about the person's personal workspace for this platform, for the session check
        // (EnsureCompanyEntitled). Nothing is remembered when it said nothing.
        if ($said !== null && $said['personal_answer'] !== null) {
            $request->session()->put(EnsureCompanyEntitled::SESSION_KEY, ['personal' => $said['personal_answer']]);
        } else {
            $request->session()->forget(EnsureCompanyEntitled::SESSION_KEY);
        }

        return $this->safeRedirect($request->post('redirect')) ?? redirect()->route('dashboard');
    }

    /**
     * What the redeem answer says about entitlement (R-50), or null when it says nothing (an older InfoDot: the
     * `entitled` key is absent everywhere, and sign-in behaves as it always has).
     *
     * @param  array<string, mixed>  $identity
     * @return array{personal: bool, personal_answer: ?bool, companies: list<string>, reason: ?string, name: ?string}|null
     */
    private function entitlementSaid(array $identity): ?array
    {
        $teams = collect($identity['teams'] ?? [])->filter(fn ($t) => is_array($t) && array_key_exists('entitled', $t))->values();

        if ($teams->isEmpty()) {
            return null;
        }

        $held = $teams->filter(fn ($t) => $t['entitled'] === true);
        // The wording of a refusal follows the company the person was acting for, else the first company listed.
        $about = $teams->firstWhere('uuid', $identity['current_team_uuid'] ?? null)
            ?? $teams->first(fn ($t) => ! ($t['personal'] ?? false))
            ?? $teams->first();

        $personal = $teams->first(fn ($t) => (bool) ($t['personal'] ?? false));

        return [
            // Exactly what was said about the personal workspace: true, false, or null when it was not mentioned.
            'personal_answer' => $personal === null ? null : $personal['entitled'] === true,
            'personal' => $held->contains(fn ($t) => (bool) ($t['personal'] ?? false)),
            'companies' => $held->reject(fn ($t) => (bool) ($t['personal'] ?? false))->pluck('uuid')->filter(fn ($u) => is_string($u) && $u !== '')->values()->all(),
            'reason' => is_string($about['access'] ?? null) ? $about['access'] : null,
            'name' => ! ($about['personal'] ?? false) && is_string($about['name'] ?? null) ? $about['name'] : null,
        ];
    }

    /**
     * A deep link may name an in-app path to open. Only a plain absolute path on this
     * site is honoured, so this can never be turned into an open redirect: it must start
     * with a single "/", and may not contain "//", a backslash, "..", a control character
     * or a scheme, either as written or once percent-decoded.
     */
    private function safeRedirect(mixed $redirect): ?RedirectResponse
    {
        if (! is_string($redirect) || $redirect === '' || strlen($redirect) > 2048) {
            return null;
        }

        foreach ([$redirect, rawurldecode($redirect)] as $candidate) {
            if (
                ! str_starts_with($candidate, '/')
                || str_starts_with($candidate, '//')
                || str_contains($candidate, '\\')
                || str_contains($candidate, '..')
                || str_contains($candidate, '//')
                || preg_match('/[\x00-\x20\x7f]/', $candidate)
            ) {
                return null;
            }
        }

        return redirect($redirect);
    }

    /** A token minted in a database we share (the original design): found, checked, spent. */
    private function userFromLocalToken(string $token): ?User
    {
        $accessToken = PersonalAccessToken::findToken($token);

        if (
            ! $accessToken
            || ! $accessToken->can('ecosystem:read')
            || ($accessToken->expires_at && $accessToken->expires_at->isPast())
        ) {
            return null;
        }

        /** @var User|null $user */
        $user = $accessToken->tokenable;
        $accessToken->delete();

        return $user;
    }
}
