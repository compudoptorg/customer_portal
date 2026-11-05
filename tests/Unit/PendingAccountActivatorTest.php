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
}
