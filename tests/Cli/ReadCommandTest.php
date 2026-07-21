<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Tests\Cli;

use GabrieleCorio\FiscozenWrapper\Api\ApiClient;
use GabrieleCorio\FiscozenWrapper\Auth\AuthManager;
use GabrieleCorio\FiscozenWrapper\Cli\ReadCommand;
use GabrieleCorio\FiscozenWrapper\FiscozenClient;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ReadCommandTest extends TestCase
{
    private string $cookieFile;

    protected function setUp(): void
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'fiscozen_cli_test_');
        unlink($this->cookieFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    }

    public function testDashboardReturnsOneJsonReadyStructure(): void
    {
        $command = $this->makeCommand([
            new Response(200, [], json_encode(['fiscal_year' => 2026, 'year_revenue' => 1000])),
            new Response(200, [], json_encode(['fiscal_year' => 2026, 'forecast_range' => []])),
            new Response(200, [], json_encode([['id' => 1, 'title' => 'Mock task']])),
        ]);

        $result = $command->execute('dashboard', ['year' => '2026']);

        self::assertSame(2026, $result['year']);
        self::assertSame(1000, $result['stats']['year_revenue']);
        self::assertSame(1, $result['tasks'][0]['id']);
        self::assertJson(json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testInvoicesReturnsPaginationAsArray(): void
    {
        $command = $this->makeCommand([$this->paginatedResponse([['id' => 11]])]);

        $result = $command->execute('invoices', ['page' => '2', 'page-size' => '10']);

        self::assertSame(1, $result['count']);
        self::assertSame(11, $result['results'][0]['id']);
    }

    public function testCounterpartsReturnsArray(): void
    {
        $command = $this->makeCommand([new Response(200, [], json_encode([['id' => 22]]))]);

        self::assertSame(22, $command->execute('counterparts')[0]['id']);
    }

    public function testPaymentMethodsReturnsPaginationAsArray(): void
    {
        $command = $this->makeCommand([$this->paginatedResponse([['id' => 33]])]);

        self::assertSame(33, $command->execute('payment-methods')['results'][0]['id']);
    }

    public function testDashboardRequiresYear(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--year=YYYY');

        $this->makeCommand([])->execute('dashboard');
    }

    public function testInvalidPageIsRejectedWithoutHttpRequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('page');

        $this->makeCommand([])->execute('invoices', ['page' => '0']);
    }

    public function testUnknownOptionIsRejectedWithoutHttpRequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Opzione non supportata');

        $this->makeCommand([])->execute('counterparts', ['unknown' => 'value']);
    }

    public function testRequestCanBeValidatedBeforeAuthentication(): void
    {
        ReadCommand::validateRequest('invoices', ['page' => '1', 'page-size' => '50']);

        $this->addToAssertionCount(1);
    }

    /** @param array<Response|\Throwable> $responses */
    private function makeCommand(array $responses): ReadCommand
    {
        $mockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mockHandler);
        $auth = new AuthManager($this->cookieFile);
        $api = new ApiClient(
            auth: $auth,
            httpClientFactory: fn(FileCookieJar $jar) => new Client([
                'handler' => $handlerStack,
                'cookies' => $jar,
            ]),
        );
        $client = new FiscozenClient(
            email: 'test@example.invalid',
            password: 'not-a-real-password',
            auth: $auth,
            api: $api,
        );

        return new ReadCommand($client);
    }

    /** @param list<array<mixed>> $results */
    private function paginatedResponse(array $results): Response
    {
        return new Response(200, [], json_encode([
            'count' => count($results),
            'page' => 1,
            'pages' => 1,
            'next' => null,
            'previous' => null,
            'results' => $results,
        ]));
    }
}
