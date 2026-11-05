<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * The portal caches billing data per account for a few minutes. This clears it for one account,
 * without needing a logged-in user (so it also works from scheduled commands).
 */
class BillingCache
{
    private const TAGS = [
        'billing.details',
        'billing.invoices',
        'billing.transactions',
        'billing.payment_methods',
        'billing.outstanding_invoices',
    ];

    public static function forget(int $accountId): void
    {
        foreach (self::TAGS as $tag) {
            Cache::tags($tag)->forget($accountId);
        }
    }
}
