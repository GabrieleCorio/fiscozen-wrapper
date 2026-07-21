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
            )->toArray(),
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
            'invoices', 'payment-methods' => ['page', 'page-size'],
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
    private static function requiredYear(array $options): int
    {
        if (!isset($options['year'])) {
            throw new \InvalidArgumentException('Il comando dashboard richiede --year=YYYY');
        }

        $year = filter_var($options['year'], FILTER_VALIDATE_INT);
        if ($year === false || $year < 2000 || $year > 9999) {
            throw new \InvalidArgumentException('year deve essere un anno a quattro cifre valido');
        }

        return $year;
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
