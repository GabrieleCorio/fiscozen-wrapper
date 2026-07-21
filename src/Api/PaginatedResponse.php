<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Api;

use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;

/**
 * Risposta paginata restituita dagli endpoint di elenco Fiscozen.
 *
 * @template T of array<mixed>
 */
final class PaginatedResponse
{
    /**
     * @param list<T> $results
     */
    private function __construct(
        public readonly int $count,
        public readonly int $page,
        public readonly int $pages,
        public readonly ?string $next,
        public readonly ?string $previous,
        public readonly array $results,
    ) {}

    /**
     * @param array<mixed> $data
     * @return self<array<mixed>>
     * @throws ApiException Se la risposta non ha la struttura paginata osservata.
     */
    public static function fromArray(array $data): self
    {
        foreach (['count', 'page', 'pages', 'results'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $data)) {
                throw new ApiException("Risposta paginata non valida: campo {$requiredKey} mancante");
            }
        }

        if (!is_array($data['results'])) {
            throw new ApiException('Risposta paginata non valida: results deve essere un array');
        }

        return new self(
            count: (int) $data['count'],
            page: (int) $data['page'],
            pages: (int) $data['pages'],
            next: isset($data['next']) ? (string) $data['next'] : null,
            previous: isset($data['previous']) ? (string) $data['previous'] : null,
            results: array_values($data['results']),
        );
    }

    /**
     * Converte la risposta in un array serializzabile in JSON.
     *
     * @return array{count: int, page: int, pages: int, next: ?string, previous: ?string, results: list<array<mixed>>}
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'page' => $this->page,
            'pages' => $this->pages,
            'next' => $this->next,
            'previous' => $this->previous,
            'results' => $this->results,
        ];
    }
}
