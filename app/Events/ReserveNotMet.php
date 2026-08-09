<?php

namespace App\Events;

use App\Models\ReserveNotMetProposal;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReserveNotMet
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly ReserveNotMetProposal $proposal) {}
}
