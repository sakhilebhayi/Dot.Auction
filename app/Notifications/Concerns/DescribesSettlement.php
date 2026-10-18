<?php

namespace App\Notifications\Concerns;

use App\Models\AuctionSettlement;
use App\Services\Paystack\PaystackConfig;

/** Shared bits of the in-app settlement notices (database channel only; no Dot.Notify event). */
trait DescribesSettlement
{
    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    protected function lot(AuctionSettlement $settlement): string
    {
        return '"'.($settlement->auction->title ?? 'your auction').'"';
    }

    protected function testModeNote(): string
    {
        return app(PaystackConfig::class)->isTestMode() ? ' This was a test payment: no real money moved.' : '';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function payload(AuctionSettlement $settlement, string $type, string $title, string $message, array $extra = []): array
    {
        return array_merge([
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'auction_id' => $settlement->auction_id,
            'url' => route('settlements.show', $settlement),
        ], $extra);
    }
}
