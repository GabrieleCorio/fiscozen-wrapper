<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Api\Domain;

use GabrieleCorio\FiscozenWrapper\Api\ApiClient;
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;

final class CounterpartsApi
{
    public function __construct(private readonly ApiClient $client) {}

    /**
     * Recupera le controparti usando esclusivamente i filtri osservati nel HAR.
     *
     * @return array<mixed>
     * @throws ApiException
     */
    public function list(?string $choices = null, ?string $counterpartType = null): array
    {
        return $this->client->getCounterparts([
            'choices' => $choices,
            'counterpart_type' => $counterpartType,
        ]);
    }
}
