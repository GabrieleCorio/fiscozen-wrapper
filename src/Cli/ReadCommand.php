<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Cli;

use GabrieleCorio\FiscozenWrapper\FiscozenClient;

/**
 * Esegue esclusivamente le letture esposte dal comando locale.
 */
final class ReadCommand
{
    public function __construct(private readonly FiscozenClient $client) {}

    /**
     * @param array<string, string> $options
     * @return array<mixed>
     */
    public function execute(string $command, array $options = []): array
    {
        self::validateRequest($command, $options);

        return match ($command) {
            'dashboard' => $this->dashboard(self::requiredYear($options)),
            'invoices' => $this->client->invoices()->list(
                page: self::positiveInt($options, 'page', 1),
                pageSize: self::positiveInt($options, 'page-size', 20),
                counterpart: self::counterpartIds($options),
                dateFrom: self::optionalDate($options, 'date-from'),
                dateTo: self::optionalDate($options, 'date-to'),
                counterpartSearch: $options['counterpart-search'] ?? null,
            )->toArray(),
            'invoice' => $this->client->invoices()->findByNumber(
                number: self::requiredPositiveInt($options, 'number', 'invoice'),
                year: self::requiredYear($options, 'invoice'),
            ),
            'counterparts' => $this->client->counterparts()->list(),
            'payment-methods' => $this->client->payments()->listMethods(
                page: self::positiveInt($options, 'page', 1),
                pageSize: self::positiveInt($options, 'page-size', 20),
            )->toArray(),
            default => throw new \InvalidArgumentException("Comando non supportato: {$command}"),
        };
    }

    /** @param array<string, string> $options */
    public static function validateRequest(string $command, array $options = []): void
    {
        $allowed = match ($command) {
            'dashboard' => ['year'],
            'invoice' => ['number', 'year'],
            'invoices' => ['page', 'page-size', 'counterpart', 'counterpart-search', 'date-from', 'date-to'],
            'payment-methods' => ['page', 'page-size'],
            'counterparts' => [],
            default => throw new \InvalidArgumentException("Comando non supportato: {$command}"),
        };

        $unknown = array_diff(array_keys($options), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Opzione non supportata: ' . reset($unknown));
        }

        if ($command === 'dashboard') {
            self::requiredYear($options);
        }

        if ($command === 'invoice') {
            self::requiredPositiveInt($options, 'number', 'invoice');
            self::requiredYear($options, 'invoice');
        }

        if ($command === 'invoices') {
            self::counterpartIds($options);
            if (isset($options['counterpart-search'])) {
                if (isset($options['counterpart'])) {
                    throw new \InvalidArgumentException('counterpart e counterpart-search non sono combinabili');
                }
                if (trim($options['counterpart-search']) === '') {
                    throw new \InvalidArgumentException('counterpart-search non può essere vuoto');
                }
            }
            $from = self::optionalDate($options, 'date-from');
            $to = self::optionalDate($options, 'date-to');
            if ($from !== null && $to !== null && $from > $to) {
                throw new \InvalidArgumentException('date-from non può essere successiva a date-to');
            }
        }

        if (in_array($command, ['invoices', 'payment-methods'], true)) {
            self::positiveInt($options, 'page', 1);
            self::positiveInt($options, 'page-size', 20);
        }
    }

    /** @return array<mixed> */
    private function dashboard(int $year): array
    {
        return [
            'year' => $year,
            'stats' => $this->client->dashboard()->getRealtimeYearStats($year),
            'forecast' => $this->client->dashboard()->getFulfillmentForecast($year),
            'tasks' => $this->client->dashboard()->getTasks(),
        ];
    }

    /** @param array<string, string> $options */
    private static function requiredYear(array $options, string $command = 'dashboard'): int
    {
        if (!isset($options['year'])) {
            throw new \InvalidArgumentException("Il comando {$command} richiede --year=YYYY");
        }

        $year = filter_var($options['year'], FILTER_VALIDATE_INT);
        if ($year === false || $year < 2000 || $year > 9999) {
            throw new \InvalidArgumentException('year deve essere un anno a quattro cifre valido');
        }

        return $year;
    }

    /**
     * @param array<string, string> $options
     * @return list<int>|null
     */
    private static function counterpartIds(array $options): ?array
    {
        if (!isset($options['counterpart'])) {
            return null;
        }

        $ids = [];
        foreach (explode(',', $options['counterpart']) as $part) {
            $id = filter_var($part, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                throw new \InvalidArgumentException('counterpart deve essere uno o più ID separati da virgola');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /** @param array<string, string> $options */
    private static function optionalDate(array $options, string $name): ?\DateTimeImmutable
    {
        if (!isset($options[$name])) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $options[$name]);
        if ($date === false || $date->format('Y-m-d') !== $options[$name]) {
            throw new \InvalidArgumentException("{$name} deve essere una data nel formato YYYY-MM-DD");
        }

        return $date;
    }

    /** @param array<string, string> $options */
    private static function requiredPositiveInt(array $options, string $name, string $command): int
    {
        if (!isset($options[$name])) {
            throw new \InvalidArgumentException("Il comando {$command} richiede --{$name}=N");
        }

        return self::positiveInt($options, $name, 1);
    }

    /** @param array<string, string> $options */
    private static function positiveInt(array $options, string $name, int $default): int
    {
        if (!isset($options[$name])) {
            return $default;
        }

        $value = filter_var($options[$name], FILTER_VALIDATE_INT);
        if ($value === false || $value < 1) {
            throw new \InvalidArgumentException("{$name} deve essere maggiore o uguale a 1");
        }

        return $value;
    }
}
