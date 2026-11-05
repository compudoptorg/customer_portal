<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Activates accounts created by the registration site in the "Pending Payment" status
 * once their first invoice has been paid.
 *
 * This must never break a payment: every failure is logged and swallowed. Staff can always
 * activate an account by hand in Sonar, and the scheduled `sonar:activate-pending-accounts`
 * command retries accounts that are still pending.
 */
class PendingAccountActivator
{
    public const RESULT_DISABLED = 'disabled';
    public const RESULT_NOT_PENDING = 'not_pending';
    public const RESULT_STILL_OWED = 'still_owed';
    public const RESULT_NO_INVOICE = 'no_invoice';
    public const RESULT_ACTIVATED = 'activated';
    public const RESULT_ERROR = 'error';

    private const ACCOUNT_QUERY = <<<'GRAPHQL'
query ($id: Int64Bit!) {
  accounts(id: $id) { entities { id account_status_id activation_date } }
}
GRAPHQL;

    private const INVOICES_QUERY = <<<'GRAPHQL'
query ($id: Int64Bit!, $paginator: Paginator!) {
  invoices(account_id: $id, paginator: $paginator) {
    page_info { page total_pages }
    entities { id remaining_due void debits { entities { service_id description } } }
  }
}
GRAPHQL;

    private const ACCOUNT_SERVICES_QUERY = <<<'GRAPHQL'
query ($id: Int64Bit!, $service: Int64Bit!) {
  account_services(account_id: $id, service_id: $service) { entities { id } }
}
GRAPHQL;

    private const ADD_SERVICE_MUTATION = <<<'GRAPHQL'
mutation ($input: AddServiceToAccountMutationInput!) {
  addServiceToAccount(input: $input) { id next_bill_date }
}
GRAPHQL;

    private const BILLING_PARAMETERS_MUTATION = <<<'GRAPHQL'
mutation ($account_id: Int64Bit!, $input: UpdateAccountBillingParameterMutationInput!) {
  updateAccountBillingParameter(account_id: $account_id, input: $input) { bill_day }
}
GRAPHQL;

    /** Registration labels the first month of a monthly plan "First month - <plan name>". */
    private const FIRST_MONTH_PREFIX = 'First month';

    private const UPDATE_ACCOUNT_MUTATION = <<<'GRAPHQL'
mutation ($id: Int64Bit!, $input: UpdateAccountMutationInput!) {
  updateAccount(id: $id, input: $input) { id account_status_id activation_date }
}
GRAPHQL;

    private const PENDING_ACCOUNTS_QUERY = <<<'GRAPHQL'
query ($status: Int64Bit!, $paginator: Paginator!) {
  accounts(account_status_id: $status, paginator: $paginator) {
    page_info { page total_pages }
    entities { id }
  }
}
GRAPHQL;

    private const MAX_PAGES = 50;

    public function __construct(private SonarGraphql $sonar)
    {
    }

    public function isEnabled(): bool
    {
        return config('customer_portal.pending_payment_status_id') !== null && $this->sonar->isConfigured();
    }

    /**
     * Call after every successful payment. Never throws.
     *
     * @param bool $quiet Don't log "still owed" outcomes (used by the scheduled retry, which would otherwise log
     *                    every unpaid pending account every few minutes).
     * @return string One of the RESULT_* constants.
     */
    public function activateIfFirstInvoicePaid(int $accountId, bool $quiet = false): string
    {
        if (!$this->isEnabled()) {
            return self::RESULT_DISABLED;
        }

        try {
            $account = $this->fetchAccount($accountId);
            if ((int) $account['account_status_id'] !== (int) config('customer_portal.pending_payment_status_id')) {
                return self::RESULT_NOT_PENDING;
            }

            [$owed, $paidCount, $paidFirstMonth] = $this->invoiceStatus($accountId);

            // No invoice yet: registration creates the account a few seconds before its invoice,
            // and leaves the account pending with no invoice if invoicing fails. Neither is paid.
            if ($owed === [] && $paidCount === 0) {
                if (!$quiet) {
                    Log::info('Pending account not activated: it has no invoice yet.', ['account_id' => $accountId]);
                }

                return self::RESULT_NO_INVOICE;
            }

            if ($owed !== []) {
                if (!$quiet) {
                    Log::info('Pending account not activated: invoices still owed.', [
                        'account_id' => $accountId,
                        'invoice_ids' => $owed,
                    ]);
                }

                return self::RESULT_STILL_OWED;
            }

            // Monthly plans: registration only charges the first month. The recurring plan is added
            // here, at activation, so nothing recurs while the account waits for its first payment.
            // This runs before the status change: if it fails, the account stays pending and the
            // scheduled retry tries again, so an account can never become Active without its plan.
            if ($paidFirstMonth) {
                $this->startMonthlyPlan($accountId);
            }

            $updated = $this->updateAccount($accountId, [
                'account_status_id' => (int) config('customer_portal.active_status_id'),
            ]);

            // The registration site's reports rely on activation_date, so make sure it is set.
            if (empty($updated['activation_date'])) {
                $this->updateAccount($accountId, [
                    'activation_date' => Carbon::now(config('app.timezone'))->format('Y-m-d'),
                ]);
            }

            BillingCache::forget($accountId);
            Log::info('Pending account activated after first invoice was paid.', ['account_id' => $accountId]);

            return self::RESULT_ACTIVATED;
        } catch (Throwable $e) {
            Log::error('Failed to activate pending account; activate it by hand in Sonar.', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return self::RESULT_ERROR;
        }
    }

    /**
     * IDs of all accounts currently in the Pending Payment status.
     *
     * @return int[]
     * @throws RuntimeException
     */
    public function pendingAccountIds(): array
    {
        $ids = [];
        foreach ($this->paginate(self::PENDING_ACCOUNTS_QUERY, 'accounts', [
            'status' => (int) config('customer_portal.pending_payment_status_id'),
        ]) as $account) {
            $ids[] = (int) $account['id'];
        }

        return $ids;
    }

    private function fetchAccount(int $accountId): array
    {
        $data = $this->sonar->query(self::ACCOUNT_QUERY, ['id' => $accountId]);
        $account = $data['accounts']['entities'][0] ?? null;
        if (!is_array($account) || !array_key_exists('account_status_id', $account)) {
            throw new RuntimeException("Account $accountId not found in Sonar GraphQL.");
        }

        return $account;
    }

    /**
     * @return array{0: int[], 1: int, 2: bool} IDs of non-void invoices with a remaining balance; the number
     *                                           of non-void invoices that are fully paid; and whether a paid
     *                                           invoice holds a monthly plan's first-month charge.
     */
    private function invoiceStatus(int $accountId): array
    {
        $owed = [];
        $paidCount = 0;
        $paidFirstMonth = false;
        foreach ($this->paginate(self::INVOICES_QUERY, 'invoices', ['id' => $accountId]) as $invoice) {
            if (!array_key_exists('remaining_due', $invoice)) {
                throw new RuntimeException('Invoice without remaining_due in Sonar response.');
            }
            if (!empty($invoice['void'])) {
                continue;
            }
            if ((float) $invoice['remaining_due'] > 0) {
                $owed[] = (int) $invoice['id'];
                continue;
            }
            $paidCount++;
            foreach ($invoice['debits']['entities'] ?? [] as $debit) {
                if ((int) ($debit['service_id'] ?? 0) === (int) config('customer_portal.first_month_debit_service_id')
                    && str_starts_with((string) ($debit['description'] ?? ''), self::FIRST_MONTH_PREFIX)) {
                    $paidFirstMonth = true;
                }
            }
        }

        return [$owed, $paidCount, $paidFirstMonth];
    }

    /**
     * Add the recurring monthly plan (unless a retry already added it) and set the bill date, so the
     * first recurring charge lands about one month after activation. The first month was on the paid
     * invoice. Bill days are capped at 28: activations on the 29th-31st bill on the 1st, starting with
     * the 1st of the month after next.
     */
    private function startMonthlyPlan(int $accountId): void
    {
        $serviceId = (int) config('customer_portal.monthly_service_id');
        $now = Carbon::now(config('app.timezone'));
        [$billDay, $monthsAhead] = $now->day > 28 ? [1, 2] : [$now->day, 1];
        $firstBill = $now->copy()->startOfMonth()->addMonthsNoOverflow($monthsAhead)->day($billDay)->format('Y-m-d');

        $existing = $this->sonar->query(self::ACCOUNT_SERVICES_QUERY, ['id' => $accountId, 'service' => $serviceId]);
        if (empty($existing['account_services']['entities'])) {
            $this->sonar->query(self::ADD_SERVICE_MUTATION, ['input' => [
                'account_id' => $accountId,
                'service_id' => $serviceId,
                'quantity' => 1,
                'prorate' => false,
                'next_bill_date' => $firstBill,
            ]]);
        }

        $this->sonar->query(self::BILLING_PARAMETERS_MUTATION, [
            'account_id' => $accountId,
            'input' => ['bill_day' => $billDay, 'next_bill_date' => $firstBill],
        ]);
    }

    private function updateAccount(int $accountId, array $input): array
    {
        $data = $this->sonar->query(self::UPDATE_ACCOUNT_MUTATION, ['id' => $accountId, 'input' => $input]);
        if (!isset($data['updateAccount']) || !is_array($data['updateAccount'])) {
            throw new RuntimeException('Unexpected updateAccount response.');
        }

        return $data['updateAccount'];
    }

    /**
     * Iterate over every entity of a paginated root query.
     */
    private function paginate(string $query, string $root, array $variables): \Generator
    {
        $page = 1;
        do {
            $data = $this->sonar->query($query, $variables + [
                'paginator' => ['page' => $page, 'records_per_page' => 100],
            ]);
            if (!isset($data[$root]['entities']) || !is_array($data[$root]['entities'])) {
                throw new RuntimeException("Unexpected '$root' response shape.");
            }
            yield from $data[$root]['entities'];
            $totalPages = (int) ($data[$root]['page_info']['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= self::MAX_PAGES);
    }
}
