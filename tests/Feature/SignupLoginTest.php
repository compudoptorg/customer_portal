<?php

namespace Tests\Feature;

use App\Http\Controllers\BillingController;
use App\Http\Middleware\VerifyCsrfToken;
use App\UsernameLanguage;
use Mockery;
use SonarSoftware\CustomerPortalFramework\Controllers\AccountAuthenticationController;
use SonarSoftware\CustomerPortalFramework\Exceptions\AuthenticationException;
use Tests\TestCase;
use Tests\UsesInMemoryDatabase;

class SignupLoginTest extends TestCase
{
    use UsesInMemoryDatabase;

    private $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();

        $this->auth = Mockery::mock(AccountAuthenticationController::class);
        $this->instance(AccountAuthenticationController::class, $this->auth);
    }

    private function acceptLogin(string $username = 'jane@example.com', int $accountId = 1234): void
    {
        $this->auth->shouldReceive('authenticateUser')
            ->with($username, 'Passw0rdX')
            ->andReturn((object) ['account_id' => $accountId, 'contact_id' => 1, 'username' => $username]);
    }

    private function postCud(array $fields)
    {
        return $this->post('/signup-login', $fields + [
            'username' => 'jane@example.com',
            'password' => 'Passw0rdX',
        ]);
    }

    public function testRedirectsToThePaymentPageForANumericInvoice(): void
    {
        $this->acceptLogin();

        $response = $this->postCud(['invoice' => '48213', 'language' => 'es']);

        $response->assertRedirect('/portal/billing/payment?invoice=48213');
        $response->assertSessionHas('authenticated', true);
        $this->assertSame(1234, session('user')->account_id);
        $this->assertSame('es', UsernameLanguage::where('username', 'jane@example.com')->value('language'));
    }

    public function testRedirectsToBillingWithoutAnInvoice(): void
    {
        $this->acceptLogin();

        $this->postCud(['language' => 'en'])->assertRedirect($this->billingUrl());
        $this->postCud(['invoice' => ''])->assertRedirect($this->billingUrl());
    }

    /**
     * Same destination as a normal login: the billing overview (BillingController@index, which Laravel
     * resolves to one of its several URLs).
     */
    private function billingUrl(): string
    {
        return action([BillingController::class, 'index']);
    }

    /**
     * @dataProvider invalidInvoiceValues
     */
    public function testInvalidInvoiceValuesFallBackToBilling($invoice): void
    {
        $this->acceptLogin();

        $response = $this->postCud(['invoice' => $invoice]);

        $response->assertRedirect($this->billingUrl());
        $this->assertStringNotContainsString('evil', $response->headers->get('Location'));
    }

    public static function invalidInvoiceValues(): array
    {
        return [
            'letters' => ['abc'],
            'negative' => ['-5'],
            'zero' => ['0'],
            'leading zero' => ['048213'],
            'decimal' => ['12.5'],
            'injection' => ['12; DROP TABLE'],
            'absolute url' => ['https://evil.example/phish'],
            'protocol relative url' => ['//evil.example'],
            'array' => [['1', '2']],
            'too long' => [str_repeat('9', 30)],
        ];
    }

    public function testUnsupportedLanguageDefaultsToEnglish(): void
    {
        $this->acceptLogin();

        $this->postCud(['language' => 'de']);

        $this->assertSame('en', UsernameLanguage::where('username', 'jane@example.com')->value('language'));
    }

    public function testFailedLoginGoesToTheLoginPageWithAnError(): void
    {
        $this->auth->shouldReceive('authenticateUser')->andThrow(new AuthenticationException('nope'));

        $response = $this->postCud(['invoice' => '48213']);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors();
        $response->assertSessionMissing('authenticated');
    }

    public function testMissingCredentialsFailWithoutCallingSonar(): void
    {
        $this->auth->shouldNotReceive('authenticateUser');

        $this->post('/signup-login', ['username' => 'jane@example.com'])->assertRedirect('/')->assertSessionHasErrors();
        $this->post('/signup-login', ['username' => ['x'], 'password' => 'y'])->assertRedirect('/')->assertSessionHasErrors();
    }

    public function testThrottleIsApplied(): void
    {
        $this->auth->shouldReceive('authenticateUser')->times(11)->andThrow(new AuthenticationException('nope'));

        for ($i = 0; $i < 11; $i++) {
            $this->postCud([])->assertRedirect('/');
        }

        // 12th attempt is refused before Sonar is even asked (times(11) above enforces that).
        $response = $this->postCud([]);
        $response->assertRedirect('/');
        $response->assertSessionHasErrors();
        $this->assertStringContainsString(
            utrans('errors.tooManyFailedAuthenticationAttempts'),
            implode(' ', session('errors')->all())
        );
    }

    public function testAnAlreadyLoggedInUserIsReplaced(): void
    {
        $this->acceptLogin('new@example.com', 999);

        $response = $this->withSession([
            'authenticated' => true,
            'user' => (object) ['account_id' => 1, 'username' => 'old@example.com'],
        ])->post('/signup-login', ['username' => 'new@example.com', 'password' => 'Passw0rdX', 'invoice' => '7']);

        $response->assertRedirect('/portal/billing/payment?invoice=7');
        $this->assertSame(999, session('user')->account_id);
    }

    public function testRouteIsCsrfExempt(): void
    {
        $except = (new \ReflectionClass(VerifyCsrfToken::class))->getProperty('except');
        $except->setAccessible(true);

        $this->assertContains('signup-login', $except->getValue(app(VerifyCsrfToken::class)));
    }
}
