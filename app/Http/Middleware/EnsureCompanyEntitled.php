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
 * Settlement pages stay open (see SETTLEMENT). Switching company stays open so a person can move to a company that does hold the platform.
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

    /**
     * Money already owed stays reachable. On deploy no company is switched on yet, so without this a winner could not
     * open a won lot or pay before the payment window runs out, and a seller could not see a sale awaiting payment.
     * Only pages and actions that settle a lot already won or sold are listed; each stays behind sign-in and its own
     * policy (parties to the sale only). Nothing that starts new auction activity is here: no bidding (a Livewire
     * action), no new lot, no editing, publishing or cancelling a listing, no watchlist, no reserve decision.
     */
    public const SETTLEMENT = [
        'settlements.index',            // what I owe and what is owed to me (winner and seller)
        'settlements.show',             // the won-lot / sold-lot payment page
        'settlements.pay',              // winner starts payment
        'settlements.payment.callback', // winner returns from Paystack and the payment is confirmed
        'settlements.cancel',           // seller handles an overdue, unpaid sale
        'buyer.bids',                   // my bids (read only)
        'buyer.won',                    // my won lots (read only)
        'seller.auctions.index',        // my listings, including sold lots (read only)
    ];

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

        // A personal team, or a team InfoDot does not know (owner decision 2026-11-14: individuals hold the free core
        // only). Follow what InfoDot said about the person's personal workspace at sign-in: exactly false closes the
        // signed-in area until they are in a company that has switched this platform on; true (the free core) or nothing
        // remembered (an older InfoDot, a session from before this release, a local sign-in) carries on as before.
        // The read endpoint is never asked about a team that has no InfoDot uuid.
        if ($this->personalAnswer($request) === false) {
            return $this->refuse($request, $user, CompanyEntitlement::NEEDS_COMPANY, null);
        }

        return $next($request);
    }

    private function personalAnswer(Request $request): ?bool
    {
        $said = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return is_array($said) && is_bool($said['personal'] ?? null) ? $said['personal'] : null;
    }

    private function covers(Request $request): bool
    {
        if ($request->is('livewire/*')) {
            return ! $request->isMethod('GET');
        }

        $route = $request->route();

        if ($route === null || in_array($route->getName(), [...self::OPEN, ...self::SETTLEMENT], true)) {
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
        // Only the person's own memberships, by name; no other company's data is read to decide anything. A team with no
        // InfoDot uuid is offered only when the personal workspace is not itself closed.
        $personalClosed = $this->personalAnswer($request) === false;
        $others = $user->allTeams()
            ->reject(fn ($team) => $team->id === $user->current_team_id)
            ->reject(fn ($team) => $personalClosed && blank($team->infodot_team_uuid))
            ->values();

        return $this->entitlement->refusal($request, $status, $company, $others);
    }
}
