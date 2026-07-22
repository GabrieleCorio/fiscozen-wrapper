<?php

declare(strict_types=1);

// Configurazione condivisa dagli script in examples/: legge le credenziali e la
// directory di sessione dalle variabili d'ambiente, così nessuno script le
// contiene in chiaro nel codice.

require dirname(__DIR__) . '/vendor/autoload.php';

use GabrieleCorio\FiscozenWrapper\FiscozenClient;

function fiscozenClientFromEnv(): FiscozenClient
{
    $email    = getenv('FISCOZEN_EMAIL');
    $password = getenv('FISCOZEN_PASSWORD');
    if (!is_string($email) || $email === '' || !is_string($password) || $password === '') {
        fwrite(STDERR, "FISCOZEN_EMAIL e FISCOZEN_PASSWORD devono essere impostate nell'ambiente.\n");
        exit(2);
    }

    $configuredSessionDir = getenv('FISCOZEN_SESSION_DIR');
    $sessionDir = is_string($configuredSessionDir) && $configuredSessionDir !== ''
        ? $configuredSessionDir
        : dirname(__DIR__) . '/sessions';

    return new FiscozenClient(email: $email, password: $password, sessionDir: $sessionDir);
}

/**
 * Gli script di sola lettura non rifanno il login: richiedono una sessione già
 * avviata con examples/login.php, per mantenere ciascuno script a una sola azione.
 */
function requireAuthenticatedSession(FiscozenClient $client): void
{
    if (!$client->isAuthenticated()) {
        fwrite(STDERR, "Nessuna sessione attiva. Esegui prima: php examples/login.php\n");
        exit(3);
    }
}

/** @return array<string, string> */
function parseOptions(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException("Opzione non valida: {$argument}");
        }

        [$name, $value] = explode('=', substr($argument, 2), 2);
        if ($name === '' || $value === '') {
            throw new InvalidArgumentException("Opzione non valida: {$argument}");
        }

        $options[$name] = $value;
    }

    return $options;
}
