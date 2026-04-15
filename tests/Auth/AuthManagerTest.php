<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Tests\Auth;

use GabrieleCorio\FiscozenWrapper\Auth\AuthManager;
use GabrieleCorio\FiscozenWrapper\Exceptions\AuthenticationException;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class AuthManagerTest extends TestCase
{
    private string $cookieFile;

    protected function setUp(): void
    {
        // Crea un percorso unico per ogni test senza creare il file subito
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'fiscozen_test_');
        unlink($this->cookieFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Costruisce un AuthManager con un MockHandler che restituisce le risposte in sequenza.
     *
     * @param  array<Response|\Throwable>  $responses
     */
    private function makeManager(array $responses): AuthManager
    {
        $mockHandler  = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mockHandler);

        return new AuthManager(
            cookieFile: $this->cookieFile,
            httpClientFactory: fn(FileCookieJar $jar) => new Client([
                'handler' => $handlerStack,
                'cookies' => $jar,
            ]),
        );
    }

    private function sessionCheckOk(): Response
    {
        return new Response(200, [], json_encode(['data' => ['user' => ['display' => 'Test User']]]));
    }

    private function sessionCheckUnauthorized(): Response
    {
        return new Response(401, [], json_encode(['detail' => 'Authentication credentials not provided.']));
    }

    private function loginOtpRequired(string $phone = '+39 333 1234567'): Response
    {
        return new Response(401, [], json_encode([
            'data' => ['user_data' => ['phone_number' => $phone]],
        ]));
    }

    private function otpVerifyOk(): Response
    {
        return new Response(401, [], json_encode([
            'data'   => ['mfa_trust' => ['pending' => true]],
            'status' => 'mfa_trust',
        ]));
    }

    private function trustOk(): Response
    {
        return new Response(200, [], json_encode([
            'meta' => ['is_authenticated' => true],
            'data' => ['user' => ['display' => 'Test User']],
        ]));
    }

    private function initSessionOk(): Response
    {
        return new Response(200, ['Content-Type' => 'text/html'], '<html></html>');
    }

    // -------------------------------------------------------------------------
    // isAuthenticated()
    // -------------------------------------------------------------------------

    public function testIsAuthenticatedReturnsFalseWhenCookieFileDoesNotExist(): void
    {
        // Nessun mock necessario: il metodo esce subito prima di fare HTTP
        $manager = $this->makeManager([]);

        self::assertFalse($manager->isAuthenticated());
    }

    public function testIsAuthenticatedReturnsTrueWhenSessionIsValid(): void
    {
        touch($this->cookieFile);

        $manager = $this->makeManager([$this->sessionCheckOk()]);

        self::assertTrue($manager->isAuthenticated());
    }

    public function testIsAuthenticatedReturnsFalseWhenSessionIsExpired(): void
    {
        touch($this->cookieFile);

        $manager = $this->makeManager([$this->sessionCheckUnauthorized()]);

        self::assertFalse($manager->isAuthenticated());
    }

    public function testIsAuthenticatedReturnsFalseOnNetworkError(): void
    {
        touch($this->cookieFile);

        $networkError = new ConnectException('Connection refused', new Request('GET', '/'));
        $manager      = $this->makeManager([$networkError]);

        self::assertFalse($manager->isAuthenticated());
    }

    // -------------------------------------------------------------------------
    // authenticate() — sessione già valida
    // -------------------------------------------------------------------------

    public function testAuthenticateDoesNothingWhenAlreadyAuthenticated(): void
    {
        touch($this->cookieFile);

        $otpCallbackCalled = false;
        $otpCallback       = function () use (&$otpCallbackCalled): string {
            $otpCallbackCalled = true;
            return '123456';
        };

        // Solo una risposta: la verifica di sessione. Se il manager facesse
        // ulteriori chiamate, MockHandler lancerebbe OutOfBoundsException.
        $manager = $this->makeManager([$this->sessionCheckOk()]);
        $manager->authenticate('user@example.com', 'secret', $otpCallback);

        self::assertFalse($otpCallbackCalled, 'Il callback OTP non deve essere invocato se la sessione è attiva');
    }

    // -------------------------------------------------------------------------
    // authenticate() — flusso completo
    // -------------------------------------------------------------------------

    public function testAuthenticateRunsFullFlowWhenNotAuthenticated(): void
    {
        $receivedPhone = null;
        $otp           = '654321';

        $manager = $this->makeManager([
            $this->initSessionOk(),
            $this->loginOtpRequired('+39 333 9999999'),
            $this->otpVerifyOk(),
            $this->trustOk(),
        ]);

        $manager->authenticate('user@example.com', 'secret', function (string $phone) use (&$receivedPhone, $otp): string {
            $receivedPhone = $phone;
            return $otp;
        });

        self::assertSame('+39 333 9999999', $receivedPhone, 'Il callback deve ricevere il numero di telefono corretto');
    }

    public function testAuthenticateSkipsSessionCheckWhenCookieFileAbsent(): void
    {
        // Senza file di cookie isAuthenticated() → false senza fare HTTP,
        // quindi il mock non deve includere la sessionCheck.
        $manager = $this->makeManager([
            $this->initSessionOk(),
            $this->loginOtpRequired(),
            $this->otpVerifyOk(),
            $this->trustOk(),
        ]);

        $manager->authenticate('user@example.com', 'secret', fn() => '000000');

        // Se arriviamo qui senza eccezioni il flusso è andato a buon fine
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // authenticate() — errori di flusso
    // -------------------------------------------------------------------------

    public function testAuthenticateThrowsWhenLoginReturnsUnexpectedStatusCode(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/Login fallito/');

        $manager = $this->makeManager([
            $this->initSessionOk(),
            new Response(403, [], json_encode(['detail' => 'Forbidden'])),
        ]);

        $manager->authenticate('user@example.com', 'wrong_password', fn() => '000000');
    }

    public function testAuthenticateThrowsWhenLoginResponseMissingPhoneNumber(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/Login fallito/');

        $manager = $this->makeManager([
            $this->initSessionOk(),
            // 401 ma senza phone_number nel body
            new Response(401, [], json_encode(['data' => ['user_data' => []]])),
        ]);

        $manager->authenticate('user@example.com', 'secret', fn() => '000000');
    }

    public function testAuthenticateThrowsWhenOtpVerificationFails(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/OTP/');

        $manager = $this->makeManager([
            $this->initSessionOk(),
            $this->loginOtpRequired(),
            // OTP sbagliato: risposta senza 'mfa_trust' nel body
            new Response(401, [], json_encode(['detail' => 'Invalid OTP'])),
        ]);

        $manager->authenticate('user@example.com', 'secret', fn() => '000000');
    }

    public function testAuthenticateThrowsWhenOtpReturnsWrongStatusCode(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/OTP/');

        $manager = $this->makeManager([
            $this->initSessionOk(),
            $this->loginOtpRequired(),
            new Response(400, [], json_encode(['detail' => 'Bad request'])),
        ]);

        $manager->authenticate('user@example.com', 'secret', fn() => '000000');
    }

    public function testAuthenticateThrowsWhenTrustFails(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/Trust/');

        $manager = $this->makeManager([
            $this->initSessionOk(),
            $this->loginOtpRequired(),
            $this->otpVerifyOk(),
            new Response(500, [], json_encode(['detail' => 'Internal server error'])),
        ]);

        $manager->authenticate('user@example.com', 'secret', fn() => '000000');
    }

    public function testAuthenticateThrowsWhenTrustReturns200ButNotAuthenticated(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/Trust/');

        $manager = $this->makeManager([
            $this->initSessionOk(),
            $this->loginOtpRequired(),
            $this->otpVerifyOk(),
            new Response(200, [], json_encode(['meta' => ['is_authenticated' => false]])),
        ]);

        $manager->authenticate('user@example.com', 'secret', fn() => '000000');
    }

    // -------------------------------------------------------------------------
    // destroySession()
    // -------------------------------------------------------------------------

    public function testDestroySessionDeletesCookieFile(): void
    {
        touch($this->cookieFile);
        self::assertFileExists($this->cookieFile);

        $manager = $this->makeManager([]);
        $manager->destroySession();

        self::assertFileDoesNotExist($this->cookieFile);
    }

    public function testDestroySessionIsIdempotentWhenFileAlreadyMissing(): void
    {
        $manager = $this->makeManager([]);

        // Non deve lanciare eccezioni
        $manager->destroySession();

        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // reauthenticate()
    // -------------------------------------------------------------------------

    public function testReauthenticateForcesFreshLoginEvenIfSessionWasValid(): void
    {
        // Creiamo un file di cookie per simulare una sessione esistente.
        // reauthenticate() chiama destroySession() che lo cancella, quindi
        // isAuthenticated() restituirà false (file assente) senza fare HTTP,
        // e poi partirà il flusso completo di 4 chiamate.
        touch($this->cookieFile);

        $otpCallbackCalled = false;

        $manager = $this->makeManager([
            $this->initSessionOk(),
            $this->loginOtpRequired(),
            $this->otpVerifyOk(),
            $this->trustOk(),
        ]);

        $manager->reauthenticate('user@example.com', 'secret', function (string $phone) use (&$otpCallbackCalled): string {
            $otpCallbackCalled = true;
            return '123456';
        });

        self::assertTrue($otpCallbackCalled, 'Il callback OTP deve essere invocato nel flusso di reauthenticate');
    }
}
