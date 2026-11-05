<?php

namespace Tests\Unit;

use App\Services\PendingAccountActivator;
use App\Services\SonarGraphql;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PendingAccountActivatorTest extends TestCase
{
    private const PENDING = 12;
    private const ACCOUNT = 4321;

    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'customer_portal.sonar_graphql_url' => 'https://sonar.test/api/graphql',
            'customer_portal.sonar_graphql_token' => 'test-token',
            'customer_portal.pending_payment_status_id' => self::PENDING,
            'customer_portal.active_status_id' => 1,
            'app.timezone' => 'America/Denver',
        ]);
    }

    private function activator(array $responses): PendingAccountActivator
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new PendingAccountActivator(new SonarGraphql(new Client(['handler' => $stack])));
    }

    private static function jsonResponse(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private static function account(int $statusId, ?string $activationDate = null): Response
    {
        return self::jsonResponse(['data' => ['accounts' => ['entities' => [
            ['id' => self::ACCOUNT, 'account_status_id' => $statusId, 'activation_date' => $activationDate],
        ]]]]);
    }

    private static function invoices(array $invoices): Response
    {
        return self::jsonResponse(['data' => ['invoices' => [
            'page_info' => ['page' => 1, 'total_pages' => 1],
            'entities' => $invoices,
        ]]]);
    }

    private static function updated(?string $activationDate): Response
    {
        return self::jsonResponse(['data' => ['updateAccount' => [
            'id' => self::ACCOUNT, 'account_status_id' => 1, 'activation_date' => $activationDate,
        ]]]);
    }

    private function requestBody(int $i): array
    {
        /** @var Request $request */
        $request = $this->history[$i]['request'];

        return json_decode((string) $request->getBody(), true);
    }

    public function testNotPendingIsANoOp(): void
    {
        $result = $this->activator([self::account(1, '2024-01-01')])->activateIfFirstInvoicePaid(self::ACCOUNT);

        $this->assertSame(PendingAccountActivator::RESULT_NOT_PENDING, $result);
        $this->assertCount(1, $this->history);
        $this->assertSame('Bearer test-token', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame(self::ACCOUNT, $this->requestBody(0)['variables']['id']);
    }

    public function testDisabledWithoutPendingStatusMakesNoRequests(): void
    {
        config(['customer_portal.pending_payment_status_id' => null]);

        $result = $this->activator([])->activateIfFirstInvoicePaid(self::ACCOUNT);

        $this->assertSame(PendingAccountActivator::RESULT_DISABLED, $result);
        $this->assertCount(0, $this->history);
    }

    public function testInvoicesStillOwedLeavesTheAccountPending(): void
    {
        Log::spy();

        $result = $this->activator([
            self::account(self::PENDING),
            self::invoices([
                ['id' => 1, 'remaining_due' => 0, 'void' => false],
                ['id' => 2, 'remaining_due' => 4999, 'void' => false],
            ]),
        ])->activateIfFirstInvoicePaid(self::ACCOUNT);

        $this->assertSame(PendingAccountActivator::RESULT_STILL_OWED, $result);
        $this->assertCount(2, $this->history, 'no updateAccount mutation must be sent');
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => $ctx['invoice_ids'] === [2])->once();
    }

    /**
     * Registration creates the account seconds before its invoice, and leaves it pending with no invoice
     * if invoicing fails. The scheduled retry must not activate such an account for free.
     *
     * @dataProvider noPaidInvoiceProvider
     */
    public function testAccountWithoutAPaidInvoiceStaysPending(array $invoices): void
    {
        $result = $this->activator([
            self::account(self::PENDING),
            self::invoices($invoices),
        ])->activateIfFirstInvoicePaid(self::ACCOUNT);

        $this->assertSame(PendingAccountActivator::RESULT_NO_INVOICE, $result);
        $this->assertCount(2, $this->history, 'no updateAccount mutation must be sent');
    }

    public static function noPaidInvoiceProvider(): array
    {
        return [
            'no invoices' => [[]],
            'only voided invoices' => [[['id' => 1, 'remaining_due' => 0, 'void' => true]]],
        ];
    }

    public function testFullyPaidActivatesAndClearsTheBillingCache(): void
    {
        Cache::tags('billing.details')->put(self::ACCOUNT, 'stale', 600);

        $result = $this->activator([
            self::account(self::PENDING),
            self::invoices([
                ['id' => 1, 'remaining_due' => 0, 'void' => false],
                ['id' => 2, 'remaining_due' => 9900, 'void' => true], // voided invoices don't count
            ]),
            self::updated('2026-11-05'),
        ])->activateIfFirstInvoicePaid(self::ACCOUNT);

        $this->assertSame(PendingAccountActivator::RESULT_ACTIVATED, $result);
        $this->assertCount(3, $this->history);
        $mutation = $this->requestBody(2);
        $this->assertStringContainsString('updateAccount', $mutation['query']);
        $this->assertSame(['account_status_id' => 1], $mutation['variables']['input']);
        $this->assertNull(Cache::tags('billing.details')->get(self::ACCOUNT));
    }

    public function testSetsActivationDateWhenSonarLeavesItEmpty(): void
    {
        $this->travelTo(new \DateTime('2026-11-06 03:00:00', new \DateTimeZone('UTC'))); // 2026-11-05 in Denver

        $result = $this->activator([
            self::account(self::PENDING),
            self::invoices([['id' => 1, 'remaining_due' => '0.00', 'void' => false]]),
            self::updated(null),
            self::updated('2026-11-05'),
        ])->activateIfFirstInvoicePaid(self::ACCOUNT);

        $this->assertSame(PendingAccountActivator::RESULT_ACTIVATED, $result);
        $this->assertCount(4, $this->history);
        $this->assertSame(['activation_date' => '2026-11-05'], $this->requestBody(3)['variables']['input']);
    }

    public function testGraphqlErrorIsLoggedAndSwallowed(): void
    {
        Log::spy();

        $result = $this->activator([
            self::account(self::PENDING),
            self::invoices([['id' => 1, 'remaining_due' => 0, 'void' => false]]),
            self::jsonResponse(['errors' => [['message' => 'You do not have permission']]]),
        ])->activateIfFirstInvoicePaid(self::ACCOUNT);

        $this->assertSame(PendingAccountActivator::RESULT_ERROR, $result);
        Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx) => $ctx['account_id'] === self::ACCOUNT)->once();
    }

    public function testNetworkErrorIsLoggedAndSwallowed(): void
    {
        Log::spy();

        $result = $this->activator([
            new ConnectException('timed out', new Request('POST', 'https://sonar.test/api/graphql')),
        ])->activateIfFirstInvoicePaid(self::ACCOUNT);

        $this->assertSame(PendingAccountActivator::RESULT_ERROR, $result);
        Log::shouldHaveReceived('error')->once();
    }

    public function testUnexpectedShapeIsLoggedAndSwallowed(): void
    {
        Log::spy();

        $result = $this->activator([
            self::jsonResponse(['data' => ['accounts' => ['entities' => []]]]),
        ])->activateIfFirstInvoicePaid(self::ACCOUNT);

        $this->assertSame(PendingAccountActivator::RESULT_ERROR, $result);
        Log::shouldHaveReceived('error')->once();
    }

    public function testFollowsInvoicePagination(): void
    {
        $page = fn (int $n, array $entities) => self::jsonResponse(['data' => ['invoices' => [
            'page_info' => ['page' => $n, 'total_pages' => 2],
            'entities' => $entities,
        ]]]);

        $result = $this->activator([
            self::account(self::PENDING),
            $page(1, [['id' => 1, 'remaining_due' => 0, 'void' => false]]),
            $page(2, [['id' => 2, 'remaining_due' => 100, 'void' => false]]),
        ])->activateIfFirstInvoicePaid(self::ACCOUNT, true);

        $this->assertSame(PendingAccountActivator::RESULT_STILL_OWED, $result);
        $this->assertSame(2, $this->requestBody(2)['variables']['paginator']['page']);
    }

    // --- Monthly plans: the recurring plan is added at activation, not at signup ---

    /** A paid first invoice as the registration site creates it for a monthly customer. */
    private static function paidMonthlyInvoice(): Response
    {
        return self::invoices([['id' => 1, 'remaining_due' => 0, 'void' => false, 'debits' => ['entities' => [
            ['service_id' => 6, 'description' => null],
            ['service_id' => 4, 'description' => 'First month - Monthly 5G Home Internet'],
        ]]]]);
    }

    private static function accountServices(array $entities): Response
    {
        return self::jsonResponse(['data' => ['account_services' => ['entities' => $entities]]]);
    }

    private static function ok(string $field): Response
    {
        return self::jsonResponse(['data' => [$field => ['id' => 1]]]);
    }

    /**
     * @dataProvider billDateProvider
     */
    public function testPaidFirstMonthAddsMonthlyPlanBeforeActivating(string $now, int $billDay, string $firstBill): void
    {
        $this->travelTo(new \DateTime($now, new \DateTimeZone('America/Denver')));

        $result = $this->activator([
            self::account(self::PENDING),
            self::paidMonthlyInvoice(),
            self::accountServices([]),
            self::ok('addServiceToAccount'),
            self::ok('updateAccountBillingParameter'),
            self::updated('2026-03-15'),
        ])->activateIfFirstInvoicePaid(self::ACCOUNT);

        $this->assertSame(PendingAccountActivator::RESULT_ACTIVATED, $result);
        $this->assertCount(6, $this->history);

        $lookup = $this->requestBody(2);
        $this->assertStringContainsString('account_services', $lookup['query']);
        $this->assertSame(['id' => self::ACCOUNT, 'service' => 3], $lookup['variables']);

        $add = $this->requestBody(3);
        $this->assertStringContainsString('addServiceToAccount', $add['query']);
        $this->assertSame([
            'account_id' => self::ACCOUNT, 'service_id' => 3, 'quantity' => 1,
            'prorate' => false, 'next_bill_date' => $firstBill,
        ], $add['variables']['input']);

        $billing = $this->requestBody(4);
        $this->assertStringContainsString('updateAccountBillingParameter', $billing['query']);
        $this->assertSame(self::ACCOUNT, $billing['variables']['account_id']);
        $this->assertSame(['bill_day' => $billDay, 'next_bill_date' => $firstBill], $billing['variables']['input']);

        // Status changes only after the plan is in place
        $this->assertSame(['account_status_id' => 1], $this->requestBody(5)['variables']['input']);
    }

    public static function billDateProvider(): array
    {
        return [
            'mid month' => ['2026-03-15 10:00', 15, '2026-04-15'],
            'first of month' => ['2026-03-01 10:00', 1, '2026-04-01'],
            '28th' => ['2026-01-28 10:00', 28, '2026-02-28'],
            'december' => ['2026-12-15 10:00', 15, '2027-01-15'],
            '29th bills on the 1st after next month' => ['2026-03-29 10:00', 1, '2026-05-01'],
            '31st of January' => ['2026-01-31 10:00', 1, '2026-03-01'],
            '30th of November' => ['2026-11-30 10:00', 1, '2027-01-01'],
            '31st of December' => ['2026-12-31 10:00', 1, '2027-02-01'],
        ];
    }

    public function testRetryDoesNotAddTheMonthlyPlanTwice(): void
    {
        $result = $this->activator([
            self::account(self::PENDING),
            self::paidMonthlyInvoice(),
            self::accountServices([['id' => 55]]), // added by an earlier attempt that then failed
            self::ok('updateAccountBillingParameter'),
            self::updated('2026-03-15'),
        ])->activateIfFirstInvoicePaid(self::ACCOUNT);

        $this->assertSame(PendingAccountActivator::RESULT_ACTIVATED, $result);
        $this->assertCount(5, $this->history);
        foreach (range(0, 4) as $i) {
            $this->assertStringNotContainsString('addServiceToAccount', $this->requestBody($i)['query']);
        }
    }

    public function testPrepaidPlanGetsNoMonthlyPlan(): void
    {
        $result = $this->activator([
            self::account(self::PENDING),
            self::invoices([['id' => 1, 'remaining_due' => 0, 'void' => false, 'debits' => ['entities' => [
                ['service_id' => 7, 'description' => null],
                ['service_id' => 4, 'description' => 'Courtesy adjustment'], // adjustment, but not a first month
            ]]]]),
            self::updated('2026-03-15'),
        ])->activateIfFirstInvoicePaid(self::ACCOUNT);

        $this->assertSame(PendingAccountActivator::RESULT_ACTIVATED, $result);
        $this->assertCount(3, $this->history, 'only account, invoices and the status change');
    }

    public function testFailingToAddThePlanLeavesTheAccountPending(): void
    {
        Log::spy();

        $result = $this->activator([
            self::account(self::PENDING),
            self::paidMonthlyInvoice(),
            self::accountServices([]),
            self::jsonResponse(['errors' => [['message' => 'Service is disabled']]]),
        ])->activateIfFirstInvoicePaid(self::ACCOUNT);

        $this->assertSame(PendingAccountActivator::RESULT_ERROR, $result);
        $this->assertCount(4, $this->history, 'the account status must not be changed');
        Log::shouldHaveReceived('error')->once();
    }
}
