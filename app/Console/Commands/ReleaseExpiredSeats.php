<?php

namespace App\Console\Commands;

use App\Models\Seat;
use Illuminate\Console\Command;

class ReleaseExpiredSeats extends Command
{
    protected $signature = 'seats:release-expired';

    protected $description = 'Release seats whose designation validity has ended, and unpaid seat reservations that timed out.';

    public function handle(): int
    {
        $expired = Seat::releaseExpired();
        $stale = Seat::releaseStaleReservations();

        $this->info("Released {$expired} expired seat(s) and {$stale} stale reservation(s).");

        return self::SUCCESS;
    }
}
