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
 * One read per company per minute (R-61: a platform switched off or on at InfoDot takes effect within about a minute).
 * Only a clear answer about this platform's own row counts, and it also says whether InfoDot has the platform switched
 * on (`available`, `message`; absent or anything but exactly false means available). Every clear answer is also
 * remembered for six hours: when InfoDot gives no clear answer (a timeout, a refusal, a server error, a body that is
 * not what was expected, no secret configured) the last clear answer under six hours old is used, so a platform last
 * told "unavailable" stays unavailable and one last told "available" stays available. With none, the answer is "not
 * known": the caller carries on as before and the failure is logged once. An outage at InfoDot must never lock a
 * company out, and "not known" is never shown to anyone as "active".
 *
 * Nothing here reads or writes auctions, bids or settlements, and nothing the scheduler runs passes through it.
 */
class CompanyEntitlement
{
    public const ANSWER_SECONDS = 60;

    public const FAILURE_SECONDS = 60;

    /** How long the last clear answer is used when InfoDot cannot be reached. */
    public const LAST_KNOWN_SECONDS = 21600;

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
     * @return array{entitled: bool, status: string, available: bool, message: ?string}|null null when the answer is not known
     */
    public function check(string $companyUuid): ?array
    {
        $key = 'entitlement:'.$this->platform().':'.$companyUuid;
        $cached = Cache::get($key);

        if (is_array($cached) && array_key_exists('known', $cached)) {
            return $cached['known'] ? $this->shape($cached) : null;
        }

        $answer = $this->ask($companyUuid);

        try {
            if ($answer !== null) {
                Cache::put($key.':last', ['answer' => $answer, 'at' => time()], self::LAST_KNOWN_SECONDS);
            } else {
                $last = Cache::get($key.':last');

                if (is_array($last) && is_array($last['answer'] ?? null) && time() - (int) ($last['at'] ?? 0) < self::LAST_KNOWN_SECONDS) {
                    $answer = $last['answer'];
                }
            }

            Cache::put($key, $answer === null ? ['known' => false] : ['known' => true] + $answer, $answer === null ? self::FAILURE_SECONDS : self::ANSWER_SECONDS);
        } catch (Throwable) {
            // No cache, no memory of the answer: the next request asks again.
        }

        return $answer;
    }

    /**
     * @param  array<string, mixed>  $cached
     * @return array{entitled: bool, status: string, available: bool, message: ?string}
     */
    private function shape(array $cached): array
    {
        return [
            'entitled' => (bool) $cached['entitled'],
            'status' => (string) ($cached['status'] ?? ''),
            'available' => ($cached['available'] ?? true) !== false,
            'message' => is_string($cached['message'] ?? null) ? $cached['message'] : null,
        ];
    }

    /**
     * @return array{entitled: bool, status: string, available: bool, message: ?string}|null
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

                return [
                    'entitled' => $row['entitled'],
                    'status' => is_string($row['status'] ?? null) ? $row['status'] : '',
                    // R-61: an InfoDot from before contract 2 gives no `available`, which is not a switch-off.
                    'available' => ($row['available'] ?? true) !== false,
                    'message' => is_string($row['message'] ?? null) && $row['message'] !== '' ? mb_substr($row['message'], 0, 400) : null,
                ];
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
