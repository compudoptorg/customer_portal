<?php

namespace Tests\Unit;

use App\Http\Controllers\BillingController;
use PHPUnit\Framework\TestCase;

/**
 * The ?invoice= parameter on the payment page must only ever select one of the logged-in account's
 * outstanding invoices.
 */
class FindOutstandingInvoiceTest extends TestCase
{
    private function outstanding(): array
    {
        return [
            (object) ['id' => 48213, 'remaining_due' => 148.0],
            (object) ['id' => 48214, 'remaining_due' => 20.5],
        ];
    }

    public function testMatchingInvoiceIsReturned(): void
    {
        $invoice = BillingController::findOutstandingInvoice($this->outstanding(), '48214');

        $this->assertNotNull($invoice);
        $this->assertSame(20.5, $invoice->remaining_due);
    }

    public function testForeignOrPaidInvoiceIsIgnored(): void
    {
        // Not in this account's outstanding list: another account's invoice, or one already paid.
        $this->assertNull(BillingController::findOutstandingInvoice($this->outstanding(), '99999'));
    }

    public function testNonNumericValuesAreIgnored(): void
    {
        foreach (['abc', '', '0', '-48213', '048213', '48213abc', '48213.0', ' 48213'] as $value) {
            $this->assertNull(BillingController::findOutstandingInvoice($this->outstanding(), $value), "value: '$value'");
        }
        $this->assertNull(BillingController::findOutstandingInvoice($this->outstanding(), null));
        $this->assertNull(BillingController::findOutstandingInvoice($this->outstanding(), ['48213']));
    }

    public function testNoOutstandingInvoices(): void
    {
        $this->assertNull(BillingController::findOutstandingInvoice([], '48213'));
        $this->assertNull(BillingController::findOutstandingInvoice(null, '48213'));
    }
}
