<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Api\Domain;

use GabrieleCorio\FiscozenWrapper\Api\ApiClient;
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;

final class DashboardApi
{
    public function __construct(private readonly ApiClient $client) {}

    /**
     * Recupera le statistiche fiscali in tempo reale per l'anno indicato.
     *
     * @return array<mixed>
     * @throws ApiException
     */
    public function getRealtimeYearStats(int $fiscalYear): array
    {
        self::assertYear($fiscalYear);

        return $this->client->getRealtimeYearStats($fiscalYear);
    }

    /**
     * Recupera la previsione degli adempimenti per l'anno fiscale indicato.
     *
     * @return array<mixed>
     * @throws ApiException
     */
    public function getFulfillmentForecast(int $fiscalYear): array
    {
        self::assertYear($fiscalYear);

        return $this->client->getFulfillmentForecast($fiscalYear);
    }

    /**
     * Recupera le attività visualizzate nella dashboard.
     *
     * @return array<mixed>
     * @throws ApiException
     */
    public function getTasks(): array
    {
        return $this->client->getDashboardTasks();
    }

    private static function assertYear(int $fiscalYear): void
    {
        if ($fiscalYear < 2000 || $fiscalYear > 9999) {
            throw new \InvalidArgumentException('fiscalYear deve essere un anno a quattro cifre valido');
        }
    }
}
