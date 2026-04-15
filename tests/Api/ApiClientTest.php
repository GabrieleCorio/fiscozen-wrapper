<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Tests\Api;

use GabrieleCorio\FiscozenWrapper\Api\ApiClient;
use GabrieleCorio\FiscozenWrapper\Auth\AuthManager;
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase
{
    private string $cookieFile;

    protected function setUp(): void
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'fiscozen_api_test_');
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
     * @param  array<Response|\Throwable>  $responses
     */
    private function makeApiClient(array $responses): ApiClient
    {
        $mockHandler  = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mockHandler);

        // AuthManager che non fa mai chiamate HTTP (cookie file assente → isAuthenticated false)
        $auth = new AuthManager($this->cookieFile);

        return new ApiClient(
            auth: $auth,
            httpClientFactory: fn(FileCookieJar $jar) => new Client([
                'handler' => $handlerStack,
                'cookies' => $jar,
            ]),
        );
    }

    private function userMeResponse(): Response
    {
        return new Response(200, [], json_encode([
            'data' => [
                'first_name'   => 'Mario',
                'last_name'    => 'Rossi',
                'email'        => 'mario.rossi@example.com',
                'phone_number' => '+39 333 1234567',
                'fiscal_code'  => 'RSSMRA80A01H501Z',
            ],
        ]));
    }

    // -------------------------------------------------------------------------
    // getUserMe()
    // -------------------------------------------------------------------------

    public function testGetUserMeReturnsUserData(): void
    {
        $client = $this->makeApiClient([$this->userMeResponse()]);
        $result = $client->getUserMe();

        self::assertSame('Mario', $result['data']['first_name']);
        self::assertSame('Rossi', $result['data']['last_name']);
        self::assertSame('mario.rossi@example.com', $result['data']['email']);
        self::assertSame('+39 333 1234567', $result['data']['phone_number']);
        self::assertSame('RSSMRA80A01H501Z', $result['data']['fiscal_code']);
    }

    public function testGetUserMeThrowsApiExceptionOn401(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/401/');

        $client = $this->makeApiClient([
            new Response(401, [], json_encode(['detail' => 'Authentication credentials not provided.'])),
        ]);

        $client->getUserMe();
    }

    public function testGetUserMeThrowsApiExceptionOn403(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/403/');

        $client = $this->makeApiClient([
            new Response(403, [], json_encode(['detail' => 'Permission denied.'])),
        ]);

        $client->getUserMe();
    }

    public function testGetUserMeThrowsApiExceptionOn500(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/500/');

        $client = $this->makeApiClient([
            new Response(500, [], json_encode(['detail' => 'Internal server error.'])),
        ]);

        $client->getUserMe();
    }

    public function testGetUserMeThrowsApiExceptionOnNetworkError(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/Errore di rete/');

        $client = $this->makeApiClient([
            new ConnectException('Connection refused', new Request('GET', '/api/v1/users/self/')),
        ]);

        $client->getUserMe();
    }

    public function testGetUserMeApiExceptionCarriesHttpStatusCode(): void
    {
        $client = $this->makeApiClient([
            new Response(404, [], json_encode(['detail' => 'Not found.'])),
        ]);

        try {
            $client->getUserMe();
            self::fail('Attesa ApiException non lanciata');
        } catch (ApiException $e) {
            self::assertSame(404, $e->getHttpStatusCode());
        }
    }

    public function testGetUserMeReturnsEmptyArrayOnEmptyBody(): void
    {
        // 200 + body vuoto o JSON malformato: json_decode restituisce null → []
        // Non deve lanciare eccezione, il chiamante gestisce i campi mancanti.
        $client = $this->makeApiClient([
            new Response(200, [], ''),
        ]);

        $result = $client->getUserMe();

        self::assertIsArray($result);
        self::assertEmpty($result);
    }
}
