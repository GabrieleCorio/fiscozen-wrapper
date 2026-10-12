<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Api\Domain;

use GabrieleCorio\FiscozenWrapper\Api\ApiClient;
use GabrieleCorio\FiscozenWrapper\Api\PaginatedResponse;
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;
use GabrieleCorio\FiscozenWrapper\Exceptions\InvoiceNotFoundException;

final class InvoicesApi
{
    private const LOOKUP_PAGE_SIZE = 50;

    /** Fatture più recenti per prime. */
    public const DEFAULT_ORDERING = '-invoice_date,-invoice_number,-id';

    public function __construct(private readonly ApiClient $client) {}

    /**
     * Recupera l'elenco paginato delle fatture.
     *
     * I filtri sono combinabili. Se nessuna fattura corrisponde, la risposta ha
     * `count` 0 e `results` vuoto (nessuna eccezione).
     *
     * @param int|list<int>|null $counterpart Filtra per cliente (ID controparte, uno o più).
     * @param \DateTimeInterface|null $dateFrom Data fattura minima (inclusa).
     * @param \DateTimeInterface|null $dateTo Data fattura massima (inclusa).
     * @param string|null $counterpartSearch Cerca i clienti per nome, cognome o ragione sociale
     *        (non per CF/P.IVA) e filtra per tutti quelli trovati. Non combinabile con `$counterpart`.
     * @return PaginatedResponse<array<mixed>>
     * @throws ApiException
     */
    public function list(
        ?int $page = null,
        ?int $pageSize = null,
        ?string $ordering = self::DEFAULT_ORDERING,
        ?bool $selfInvoice = null,
        ?int $user = null,
        ?string $search = null,
        int|array|null $counterpart = null,
        ?\DateTimeInterface $dateFrom = null,
        ?\DateTimeInterface $dateTo = null,
        ?string $counterpartSearch = null,
    ): PaginatedResponse {
        self::assertPagination($page, $pageSize);
        $counterpartIds = self::counterpartIds($counterpart);

        if ($dateFrom !== null && $dateTo !== null
            && $dateFrom->format('Y-m-d') > $dateTo->format('Y-m-d')) {
            throw new \InvalidArgumentException('dateFrom non può essere successiva a dateTo');
        }

        if ($counterpartSearch !== null) {
            if ($counterpartIds !== []) {
                throw new \InvalidArgumentException('counterpart e counterpartSearch non sono combinabili');
            }

            $counterpartIds = (new CounterpartsApi($this->client))->findIds($counterpartSearch);

            // Nessun cliente trovato: nessuna fattura possibile, evita di richiedere l'elenco completo.
            if ($counterpartIds === []) {
                return PaginatedResponse::fromArray([
                    'count' => 0,
                    'page' => $page ?? 1,
                    'pages' => 0,
                    'next' => null,
                    'previous' => null,
                    'results' => [],
                ]);
            }
        }

        return PaginatedResponse::fromArray($this->client->getInvoices([
            'page' => $page,
            'page_size' => $pageSize,
            'ordering' => $ordering,
            'self_invoice' => $selfInvoice === null ? null : ($selfInvoice ? 'true' : 'false'),
            'user' => $user,
            'search' => $search,
            'counterpart__in' => $counterpartIds === [] ? null : implode(',', $counterpartIds),
            'date_from' => $dateFrom?->format('Y-m-d'),
            'date_to' => $dateTo?->format('Y-m-d'),
        ]));
    }

    /**
     * Recupera il dettaglio di una fattura tramite il suo ID interno Fiscozen.
     *
     * @return array<mixed>
     * @throws ApiException
     */
    public function get(int $id): array
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('id deve essere maggiore o uguale a 1');
        }

        return $this->client->getInvoice($id);
    }

    /**
     * Recupera il dettaglio di una fattura a partire da numero e anno (es. 24/2026),
     * senza richiedere l'ID interno Fiscozen.
     *
     * @return array<mixed>
     * @throws InvoiceNotFoundException Se nessuna fattura corrisponde a numero e anno.
     * @throws ApiException
     */
    public function findByNumber(
        int $number,
        int $year,
        bool $selfInvoice = false,
        ?int $user = null,
    ): array {
        if ($number < 1) {
            throw new \InvalidArgumentException('number deve essere maggiore o uguale a 1');
        }

        if ($year < 2000 || $year > 9999) {
            throw new \InvalidArgumentException('year deve essere un anno a quattro cifre valido');
        }

        $fullId = "{$number}/{$year}";
        $page = 1;

        do {
            $response = $this->list(
                page: $page,
                pageSize: self::LOOKUP_PAGE_SIZE,
                selfInvoice: $selfInvoice,
                user: $user,
                search: $fullId,
            );

            // La ricerca è parziale ("4/2026" trova anche "24/2026"): serve il confronto esatto.
            foreach ($response->results as $invoice) {
                if (($invoice['full_id'] ?? null) === $fullId && isset($invoice['id'])) {
                    return $this->get((int) $invoice['id']);
                }
            }

            $page++;
        } while ($page <= $response->pages);

        throw new InvoiceNotFoundException("Invoice not found: {$fullId}");
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

    /**
     * @param int|list<int>|null $counterpart
     * @return list<int>
     */
    private static function counterpartIds(int|array|null $counterpart): array
    {
        $ids = $counterpart === null ? [] : (array) $counterpart;

        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('counterpart deve contenere ID maggiori o uguali a 1');
            }
        }

        return array_values($ids);
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
