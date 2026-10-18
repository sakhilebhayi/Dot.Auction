<?php

namespace App\Console\Commands;

use App\Services\Auctions\SettlementLifecycle;
use Illuminate\Console\Command;

class MarkOverduePayments extends Command
{
    protected $signature = 'auction:mark-overdue-payments';

    protected $description = 'Start the payment clock for sales recorded before online payment was on, and mark unpaid sales past their deadline as overdue. Never relists or cancels.';

    public function handle(SettlementLifecycle $lifecycle): int
    {
        $started = $lifecycle->openClocks();
        $overdue = $lifecycle->markOverdue();

        $this->info("Started {$started} payment deadline(s); marked {$overdue} settlement(s) overdue.");

        return self::SUCCESS;
    }
}
