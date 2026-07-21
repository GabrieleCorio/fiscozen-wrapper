<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Api\Domain;

use GabrieleCorio\FiscozenWrapper\Api\ApiClient;
use GabrieleCorio\FiscozenWrapper\Api\PaginatedResponse;
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;

final class InvoicesApi
{
    public function __construct(private readonly ApiClient $client) {}

    /**
     * Recupera l'elenco paginato delle fatture.
     *
     * @return PaginatedResponse<array<mixed>>
     * @throws ApiException
     */
    public function list(
        ?int $page = null,
        ?int $pageSize = null,
        ?string $ordering = null,
        ?bool $selfInvoice = null,
        ?int $user = null,
    ): PaginatedResponse {
        self::assertPagination($page, $pageSize);

        return PaginatedResponse::fromArray($this->client->getInvoices([
            'page' => $page,
            'page_size' => $pageSize,
            'ordering' => $ordering,
            'self_invoice' => $selfInvoice === null ? null : ($selfInvoice ? 'true' : 'false'),
            'user' => $user,
        ]));
    }

    /**
     * Recupera le informazioni sul formato di fatturazione consentito per un giorno.
     *
     * @return array<mixed>
     * @throws ApiException
     */
    public function getFormatInfo(\DateTimeInterface $day, ?bool $documentIsSts = null): array
    {
        return $this->client->getInvoiceFormatInfo([
            'day' => $day->format('Y-m-d'),
            'document_is_sts' => $documentIsSts === null ? 'null' : ($documentIsSts ? 'true' : 'false'),
        ]);
    }

    private static function assertPagination(?int $page, ?int $pageSize): void
    {
        if ($page !== null && $page < 1) {
            throw new \InvalidArgumentException('page deve essere maggiore o uguale a 1');
        }

        if ($pageSize !== null && $pageSize < 1) {
            throw new \InvalidArgumentException('pageSize deve essere maggiore o uguale a 1');
        }
    }
}
