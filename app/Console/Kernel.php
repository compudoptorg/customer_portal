<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('sonar:deleteexpiredtokens')->hourly()->withoutOverlapping();

        $schedule->command('cache:prune-stale-tags')->hourly();

        // Retry activation of Pending Payment accounts (no-op unless PENDING_PAYMENT_STATUS_ID is set).
        $schedule->command('sonar:activate-pending-accounts')->everyTenMinutes()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
