<?php

namespace App\Services\InfoDot;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Asks InfoDot whether a company still holds this platform (R-50), and builds the page shown when it does not.
 *
 * One read per company per ten minutes. Only a clear answer about this platform's own row counts. Anything else (a
 * timeout, a refusal, a server error, a body that is not what was expected, no secret configured) is "not known": the
 * caller carries on as before, the failure is logged once and remembered for a minute. An outage at InfoDot must never
 * lock a company out, and "not known" is never shown to anyone as "active".
 *
 * Nothing here reads or writes auctions, bids or settlements, and nothing the scheduler runs passes through it.
 */
class CompanyEntitlement
{
    public const ANSWER_SECONDS = 600;

    public const FAILURE_SECONDS = 60;

    /** Not a status from InfoDot: the person is in a personal workspace, which does not hold this platform. */
    public const NEEDS_COMPANY = 'needs_company';

    public function enabled(): bool
    {
        return (bool) config('ecosystem.infodot.entitlement_check', true);
    }

    public function platform(): string
    {
        return (string) config('ecosystem.infodot.platform', 'auction');
    }

    /**
     * @return array{entitled: bool, status: string}|null null when the answer is not known
     */
    public function check(string $companyUuid): ?array
    {
        $key = 'entitlement:'.$this->platform().':'.$companyUuid;
        $cached = Cache::get($key);

        if (is_array($cached) && array_key_exists('known', $cached)) {
            return $cached['known'] ? ['entitled' => (bool) $cached['entitled'], 'status' => (string) $cached['status']] : null;
        }

        $answer = $this->ask($companyUuid);

        if ($answer === null) {
            Cache::put($key, ['known' => false], self::FAILURE_SECONDS);

            return null;
        }

        Cache::put($key, ['known' => true] + $answer, self::ANSWER_SECONDS);

        return $answer;
    }

    /**
     * @return array{entitled: bool, status: string}|null
     */
    private function ask(string $companyUuid): ?array
    {
        $base = $this->infodotUrl();
        $token = config('ecosystem.infodot.secret');

        if ($base === null || blank($token)) {
            return $this->unknown($companyUuid, 'not configured');
        }

        try {
            $response = Http::withToken((string) $token)
                ->withHeaders(['X-Dot-Platform' => $this->platform()])
                ->acceptJson()
                ->timeout((int) config('ecosystem.infodot.timeout', 5))
                ->get($base.'/api/ecosystem/companies/'.rawurlencode($companyUuid).'/platforms', ['platform' => $this->platform()]);
        } catch (Throwable $e) {
            return $this->unknown($companyUuid, class_basename($e));
        }

        if (! $response->successful()) {
            return $this->unknown($companyUuid, 'status '.$response->status());
        }

        $body = $response->json();
        $rows = is_array($body) ? ($body['platforms'] ?? null) : null;

        if (! is_array($rows)) {
            return $this->unknown($companyUuid, 'unexpected body');
        }

        foreach ($rows as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $this->platform()) {
                if (! is_bool($row['entitled'] ?? null)) {
                    return $this->unknown($companyUuid, 'unexpected body');
                }

                return ['entitled' => $row['entitled'], 'status' => is_string($row['status'] ?? null) ? $row['status'] : ''];
            }
        }

        return $this->unknown($companyUuid, 'own row missing');
    }

    private function unknown(string $companyUuid, string $why): null
    {
        // Once per failure window: the result of this call is cached for FAILURE_SECONDS by check().
        Log::warning('Company entitlement could not be read from InfoDot; carrying on.', ['company' => $companyUuid, 'reason' => $why]);

        return null;
    }

    /** InfoDot's address, taken from the redeem endpoint this app already calls. */
    public function infodotUrl(): ?string
    {
        $parts = parse_url((string) config('ecosystem.infodot.redeem_url', ''));

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public function platformsUrl(): string
    {
        return ($this->infodotUrl() ?? rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/')).'/platforms/'.$this->platform();
    }

    /**
     * Which of the four wordings fits a status from the read endpoint or a reason from the redeem answer.
     *
     * @return 'off'|'suspended'|'payment'|'ended'|'company'
     */
    public static function kind(?string $status): string
    {
        return match ($status) {
            self::NEEDS_COMPANY, 'no_personal_access', 'company_required' => 'company',
            'suspended' => 'suspended',
            'payment_required' => 'payment',
            'subscription_expired', 'expired', 'trial_ended', 'cancelled' => 'ended',
            default => 'off',
        };
    }

    /**
     * The plain page: HTTP 403, no auction data, one link to InfoDot, and the person's other companies to switch to.
     *
     * @param  Collection<int, Team>|null  $switchTo
     */
    public function refusal(Request $request, ?string $status, ?string $company = null, ?Collection $switchTo = null): Response
    {
        if ($request->expectsJson() && ! $request->hasHeader('X-Livewire')) {
            return response()->json(['message' => 'This platform is not switched on for your company.'], 403);
        }

        return response()->view('errors.entitlement', [
            'kind' => self::kind($status),
            'company' => filled($company) ? $company : null,
            'platformsUrl' => $this->platformsUrl(),
            'switchTo' => $switchTo ?? collect(),
        ], 403);
    }
}
