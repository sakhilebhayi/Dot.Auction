<?php

namespace App\Http\Controllers;

use App\Services\Auctions\SettlementPaymentRecorder;
use App\Services\Paystack\PaystackConfig;
use App\Services\Paystack\VerifiedTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/paystack. Server to server: no session, no CSRF token, throttled.
 *
 * Order matters. (1) The signature, an HMAC-SHA512 of the RAW request body keyed with the secret key,
 * is compared in constant time BEFORE the body is parsed or trusted; anything else gets the same
 * generic 401. With no usable key nothing can be verified, so everything is a 401. (2) Only then is
 * the JSON read (400 if it is not an object). (3) Only `charge.success` does anything; other events
 * are acknowledged. (4) The rules for marking a sale paid live in SettlementPaymentRecorder; a
 * payment that does not match is recorded for an operator and still answered 200 so Paystack stops
 * retrying. The webhook makes no outbound call, so it answers fast.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaystackConfig $config, SettlementPaymentRecorder $recorder): JsonResponse
    {
        $raw = $request->getContent();
        $key = $config->keyForVerification();
        $signature = $request->headers->get('x-paystack-signature');

        if ($key === null || ! is_string($signature) || $signature === ''
            || ! hash_equals(hash_hmac('sha512', $raw, $key), strtolower(trim($signature)))) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload) || array_is_list($payload) && $payload !== []) {
            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        if (($payload['event'] ?? null) !== 'charge.success' || ! is_array($payload['data'] ?? null)) {
            return response()->json(['message' => 'Ignored.']);
        }

        $data = $payload['data'];
        $tx = VerifiedTransaction::fromPayload($data);

        if ($tx->reference === '' || strlen($tx->reference) > 191) {
            $tx = $tx->withReference('unreferenced-'.substr(sha1($raw), 0, 16));
        }

        $metadata = $data['metadata'] ?? null;
        $metadata = is_array($metadata) ? $metadata : null;

        $recorder->apply($tx, SettlementPaymentRecorder::FROM_WEBHOOK, $metadata);

        return response()->json(['message' => 'OK.']);
    }
}
