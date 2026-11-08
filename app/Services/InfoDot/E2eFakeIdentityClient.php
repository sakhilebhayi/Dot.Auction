<?php

namespace App\Services\InfoDot;

/**
 * TESTING ONLY. Stands in for InfoDot's redeem endpoint in the browser end-to-end suite (tests/e2e/auction-b), so the
 * POST /auth/ecosystem hand-off can be driven without InfoDot. It is bound only when the app runs in the "local" or
 * "testing" environment AND AUCTION_E2E_FAKE_INFODOT=1 (see AppServiceProvider); production never registers it.
 *
 * Token format: "e2e:{email}[:{name}]" redeems to that verified identity, "e2e-down" behaves like an unreachable
 * InfoDot, anything else is refused.
 */
class E2eFakeIdentityClient extends IdentityClient
{
    public function enabled(): bool
    {
        return true;
    }

    /**
     * @return array{user: array<string, mixed>, teams: list<array<string, mixed>>, current_team_id: mixed}|null
     */
    public function redeem(string $token): ?array
    {
        if ($token === 'e2e-down') {
            throw new InfoDotUnavailable('InfoDot is not available (fake).');
        }

        if (! preg_match('/^e2e:([^:\s]+@[^:\s]+)(?::(.+))?$/', $token, $m)) {
            return null;
        }

        return [
            'user' => ['email' => $m[1], 'name' => $m[2] ?? 'E2E Person', 'email_verified' => true],
            'teams' => [],
            'current_team_id' => null,
        ];
    }
}
