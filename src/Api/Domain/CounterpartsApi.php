<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Api\Domain;

use GabrieleCorio\FiscozenWrapper\Api\ApiClient;
use GabrieleCorio\FiscozenWrapper\Api\PaginatedResponse;
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;

final class CounterpartsApi
{
    private const SEARCH_PAGE_SIZE = 50;

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

    /**
     * Cerca i clienti per testo (nome, cognome, ragione sociale).
     *
     * Codice fiscale e partita IVA non sono ricercabili con questo endpoint.
     *
     * @return PaginatedResponse<array<mixed>>
     * @throws ApiException
     */
    public function search(
        string $term,
        ?int $page = null,
        ?int $pageSize = null,
        string $counterpartType = 'CUSTOMER',
    ): PaginatedResponse {
        $term = trim($term);
        if ($term === '') {
            throw new \InvalidArgumentException('Il testo di ricerca non può essere vuoto');
        }

        if (($page !== null && $page < 1) || ($pageSize !== null && $pageSize < 1)) {
            throw new \InvalidArgumentException('page e pageSize devono essere maggiori o uguali a 1');
        }

        return PaginatedResponse::fromArray($this->client->getCounterparts([
            'choices' => 'null',
            'counterpart_type' => $counterpartType,
            'search' => $term,
            'page' => $page,
            'page_size' => $pageSize,
        ]));
    }

    /**
     * Restituisce gli ID controparte (campo `counterpart`, non `counterpart_version`)
     * dei clienti che corrispondono al testo, scorrendo tutte le pagine.
     *
     * @return list<int>
     * @throws ApiException
     */
    public function findIds(string $term): array
    {
        $ids = [];
        $page = 1;

        do {
            $response = $this->search($term, page: $page, pageSize: self::SEARCH_PAGE_SIZE);

            foreach ($response->results as $counterpart) {
                if (!isset($counterpart['counterpart']) || !is_numeric($counterpart['counterpart'])) {
                    throw new ApiException('Risposta controparti non valida: campo counterpart mancante');
                }

                $ids[] = (int) $counterpart['counterpart'];
            }

            $page++;
        } while ($page <= $response->pages);

        return array_values(array_unique($ids));
    }
}
