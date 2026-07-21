<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Api\Domain;

use GabrieleCorio\FiscozenWrapper\Api\ApiClient;
use GabrieleCorio\FiscozenWrapper\Api\PaginatedResponse;
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;

final class PaymentsApi
{
    public function __construct(private readonly ApiClient $client) {}

    /**
     * Recupera l'elenco paginato dei metodi di pagamento.
     *
     * @return PaginatedResponse<array<mixed>>
     * @throws ApiException
     */
    public function listMethods(
        ?int $page = null,
        ?int $pageSize = null,
        ?string $ordering = null,
        ?string $choices = null,
    ): PaginatedResponse {
        if ($page !== null && $page < 1) {
            throw new \InvalidArgumentException('page deve essere maggiore o uguale a 1');
        }

        if ($pageSize !== null && $pageSize < 1) {
            throw new \InvalidArgumentException('pageSize deve essere maggiore o uguale a 1');
        }

        return PaginatedResponse::fromArray($this->client->getPaymentMethods([
            'page' => $page,
            'page_size' => $pageSize,
            'ordering' => $ordering,
            'choices' => $choices,
        ]));
    }
}
