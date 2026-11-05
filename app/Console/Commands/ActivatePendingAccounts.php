<?php

namespace App\Console\Commands;

use App\Services\PendingAccountActivator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safety net for the activation that runs right after a portal payment: retries every account still in the
 * Pending Payment status, in case that call failed or Sonar hadn't applied the payment to the invoice yet.
 */
class ActivatePendingAccounts extends Command
{
    protected $signature = 'sonar:activate-pending-accounts';

    protected $description = 'Activate Pending Payment accounts whose invoices are all paid';

    public function handle(PendingAccountActivator $activator): int
    {
        if (!$activator->isEnabled()) {
            $this->info('Pending payment activation is not configured; nothing to do.');

            return self::SUCCESS;
        }

        try {
            $accountIds = $activator->pendingAccountIds();
        } catch (Throwable $e) {
            Log::error('Could not list pending payment accounts.', ['error' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $counts = [];
        foreach ($accountIds as $accountId) {
            $result = $activator->activateIfFirstInvoicePaid($accountId, true);
            $counts[$result] = ($counts[$result] ?? 0) + 1;
        }

        $this->info('Pending accounts checked: ' . count($accountIds) . ' ' . json_encode($counts));

        return self::SUCCESS;
    }
}
