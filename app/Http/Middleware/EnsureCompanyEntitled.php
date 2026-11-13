<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\CompanyEntitlement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in area is closed to a person whose current company no longer holds Dot.Auction in InfoDot (R-50).
 *
 * It sits in the `web` group and acts only on a signed-in person asking for a page that needs sign-in (or a Livewire
 * call, which is how a bid is placed). Everything a signed-out visitor can reach is untouched: the public pages, sign-in
 * and sign-out, the InfoDot hand-off, the health checks, the Paystack webhook, the CSP report endpoint, and /api.
 * Switching company stays open so a person can move to a company that does hold the platform.
 *
 * It only stops requests. It never touches an auction, a bid or a settlement: lots still close on time, standing bids
 * still count, and the scheduled commands (settling, overdue marking, notices) run exactly as before, because console
 * commands and queued jobs do not pass through HTTP middleware.
 *
 * Off with AUCTION_ENTITLEMENT_CHECK=false. When InfoDot cannot give a clear answer the request carries on.
 */
class EnsureCompanyEntitled
{
    /** Signed-in routes that stay open: leaving, and moving to another company. */
    private const OPEN = ['logout', 'current-team.update'];

    /** Where the sign-in hand-off records what InfoDot said (see EcosystemAuthController). */
    public const SESSION_KEY = 'entitlement';

    public function __construct(private readonly CompanyEntitlement $entitlement) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->entitlement->enabled() || ! $this->covers($request)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $team = $user->currentTeam;
        $uuid = $team instanceof Team ? $team->infodot_team_uuid : null;

        if ($team instanceof Team && filled($uuid)) {
            $answer = $this->entitlement->check((string) $uuid);

            return $answer !== null && $answer['entitled'] === false
                ? $this->refuse($request, $user, $answer['status'], $team->name)
                : $next($request);
        }

        // A personal workspace or a team InfoDot does not know: follow what InfoDot said at sign-in. If it admitted the
        // person through the personal workspace, or said nothing about entitlement, there is nothing to ask. If it
        // admitted them through companies only, the person stays in while any one of those still holds the platform.
        $said = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (! is_array($said) || ($said['personal'] ?? false) === true || empty($said['companies']) || ! is_array($said['companies'])) {
            return $next($request);
        }

        $status = null;

        foreach ($said['companies'] as $company) {
            $answer = is_string($company) ? $this->entitlement->check($company) : null;

            if ($answer === null || $answer['entitled'] === true) {
                return $next($request);
            }

            $status ??= $answer['status'];
        }

        return $this->refuse($request, $user, $status, null);
    }

    private function covers(Request $request): bool
    {
        if ($request->is('livewire/*')) {
            return ! $request->isMethod('GET');
        }

        $route = $request->route();

        if ($route === null || in_array($route->getName(), self::OPEN, true)) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:'))) {
                return true;
            }
        }

        return false;
    }

    private function refuse(Request $request, User $user, ?string $status, ?string $company): Response
    {
        // Only the person's own memberships, by name; no other company's data is read to decide anything.
        $others = $user->allTeams()->reject(fn ($team) => $team->id === $user->current_team_id)->values();

        return $this->entitlement->refusal($request, $status, $company, $others);
    }
}
