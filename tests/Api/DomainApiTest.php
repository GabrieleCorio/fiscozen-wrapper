<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Tests\Api;

use GabrieleCorio\FiscozenWrapper\Api\ApiClient;
use GabrieleCorio\FiscozenWrapper\Api\Domain\CounterpartsApi;
use GabrieleCorio\FiscozenWrapper\Api\Domain\DashboardApi;
use GabrieleCorio\FiscozenWrapper\Api\Domain\InvoicesApi;
use GabrieleCorio\FiscozenWrapper\Api\Domain\PaymentsApi;
use GabrieleCorio\FiscozenWrapper\Auth\AuthManager;
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;
use GabrieleCorio\FiscozenWrapper\FiscozenClient;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class DomainApiTest extends TestCase
{
    private string $cookieFile;

    protected function setUp(): void
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'fiscozen_domain_test_');
        unlink($this->cookieFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    }

    public function testInvoicesListSendsTypedFiltersAndParsesPagination(): void
    {
        [$client, $history] = $this->makeApiClient([$this->paginatedResponse([['id' => 101]])]);

        $result = (new InvoicesApi($client))->list(
            page: 2,
            pageSize: 25,
            ordering: '-invoice_date,-invoice_number,-id',
            selfInvoice: false,
            user: 123,
        );

        self::assertSame(1, $result->count);
        self::assertSame(101, $result->results[0]['id']);
        self::assertSame('/api/v1/invoices/', $history->entries[0]['request']->getUri()->getPath());
        parse_str($history->entries[0]['request']->getUri()->getQuery(), $query);
        self::assertSame([
            'page' => '2',
            'page_size' => '25',
            'ordering' => '-invoice_date,-invoice_number,-id',
            'self_invoice' => 'false',
            'user' => '123',
        ], $query);
    }

    public function testInvoiceFormatInfoFormatsTypedParameters(): void
    {
        [$client, $history] = $this->makeApiClient([
            new Response(200, [], json_encode(['allowed_invoicing_format' => 'test-format'])),
        ]);

        $result = (new InvoicesApi($client))->getFormatInfo(new \DateTimeImmutable('2026-07-20'));

        self::assertSame('test-format', $result['allowed_invoicing_format']);
        parse_str($history->entries[0]['request']->getUri()->getQuery(), $query);
        self::assertSame(['day' => '2026-07-20', 'document_is_sts' => 'null'], $query);
    }

    public function testCounterpartsUsesObservedQueryNames(): void
    {
        [$client, $history] = $this->makeApiClient([new Response(200, [], '[]')]);

        (new CounterpartsApi($client))->list(choices: 'test-choice', counterpartType: 'test-type');

        parse_str($history->entries[0]['request']->getUri()->getQuery(), $query);
        self::assertSame([
            'choices' => 'test-choice',
            'counterpart_type' => 'test-type',
        ], $query);
    }

    public function testPaymentMethodsParsesPaginatedResponse(): void
    {
        [$client, $history] = $this->makeApiClient([$this->paginatedResponse([['id' => 7]])]);

        $result = (new PaymentsApi($client))->listMethods(page: 1, pageSize: 10);

        self::assertSame(7, $result->results[0]['id']);
        self::assertSame('/api/v1/payment_methods/', $history->entries[0]['request']->getUri()->getPath());
        parse_str($history->entries[0]['request']->getUri()->getQuery(), $query);
        self::assertSame(['page' => '1', 'page_size' => '10'], $query);
    }

    public function testDashboardMethodsUseOnlyObservedGetEndpoints(): void
    {
        [$client, $history] = $this->makeApiClient([
            new Response(200, [], json_encode(['fiscal_year' => 2026])),
            new Response(200, [], json_encode(['fiscal_year' => 2026])),
            new Response(200, [], json_encode([['id' => 1]])),
        ]);
        $dashboard = new DashboardApi($client);

        self::assertSame(2026, $dashboard->getRealtimeYearStats(2026)['fiscal_year']);
        self::assertSame(2026, $dashboard->getFulfillmentForecast(2026)['fiscal_year']);
        self::assertSame(1, $dashboard->getTasks()[0]['id']);

        self::assertSame('/api/v1/fiscal_profile/realtime_year_stats/', $history->entries[0]['request']->getUri()->getPath());
        self::assertSame('/api/v1/fiscal_profile/fulfillment_forecast/', $history->entries[1]['request']->getUri()->getPath());
        self::assertSame('/customer-push/api/v1/dashboard-tasks/', $history->entries[2]['request']->getUri()->getPath());
    }

    public function testMalformedPaginatedResponseThrowsApiException(): void
    {
        [$client] = $this->makeApiClient([new Response(200, [], json_encode(['results' => []]))]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Risposta paginata non valida');

        (new InvoicesApi($client))->list();
    }

    public function testInvalidPaginationIsRejectedBeforeRequest(): void
    {
        [$client] = $this->makeApiClient([]);

        $this->expectException(\InvalidArgumentException::class);

        (new PaymentsApi($client))->listMethods(page: 0);
    }

    public function testFiscozenClientExposesApisByDomain(): void
    {
        [$apiClient] = $this->makeApiClient([]);
        $auth = new AuthManager($this->cookieFile);
        $client = new FiscozenClient(
            email: 'test@example.invalid',
            password: 'not-a-real-password',
            auth: $auth,
            api: $apiClient,
        );

        self::assertInstanceOf(InvoicesApi::class, $client->invoices());
        self::assertInstanceOf(CounterpartsApi::class, $client->counterparts());
        self::assertInstanceOf(PaymentsApi::class, $client->payments());
        self::assertInstanceOf(DashboardApi::class, $client->dashboard());
    }

    /**
     * @param array<Response|\Throwable> $responses
     * @return array{0: ApiClient, 1: object{entries: array<mixed>}}
     */
    private function makeApiClient(array $responses): array
    {
        $mockHandler = new MockHandler($responses);
        $history = new class {
            /** @var array<mixed> */
            public array $entries = [];
        };
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($history->entries));
        $auth = new AuthManager($this->cookieFile);
        $client = new ApiClient(
            auth: $auth,
            httpClientFactory: fn(FileCookieJar $jar) => new Client([
                'handler' => $handlerStack,
                'cookies' => $jar,
            ]),
        );

        return [$client, $history];
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
