<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\InfoDot\IdentityClient;
use App\Services\InfoDot\InfoDotUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class EcosystemAuthController extends Controller
{
    public function handle(Request $request, IdentityClient $infoDot, ProvisionUserFromInfoDot $provision, SyncTeamsFromInfoDot $syncTeams): RedirectResponse
    {
        $token = $request->query('token');

        abort_unless(is_string($token) && $token !== '', 403);

        $user = $this->userFromLocalToken($token);

        // Production gives each Dot app its own database, so InfoDot's token is not
        // in ours: ask InfoDot to prove it.
        if (! $user && $infoDot->enabled()) {
            try {
                $identity = $infoDot->redeem($token);
            } catch (InfoDotUnavailable) {
                abort(503, 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.');
            }

            $user = $identity ? $provision($identity) : null;

            if ($user && $identity) {
                $syncTeams($user, $identity);
            }
        }

        abort_if(! $user, 403);

        // Auth::login() also regenerates the session id (no fixation).
        Auth::login($user);

        return $this->safeRedirect($request->query('redirect')) ?? redirect()->route('dashboard');
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
