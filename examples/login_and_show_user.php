<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;
use GabrieleCorio\FiscozenWrapper\Exceptions\AuthenticationException;
use GabrieleCorio\FiscozenWrapper\FiscozenClient;

// ---------------------------------------------------------------------------
// Configurazione
// ---------------------------------------------------------------------------

$email      = 'la-tua-email@example.com';
$password   = 'la-tua-password';
$sessionDir = __DIR__ . '/../sessions';

// ---------------------------------------------------------------------------
// Autenticazione
// ---------------------------------------------------------------------------

$client = new FiscozenClient(
    email: $email,
    password: $password,
    sessionDir: $sessionDir,
);

echo "Controllo sessione...\n";

try {
    $client->login(function (string $phoneNumber): string {
        echo "OTP richiesto al numero: {$phoneNumber}\n";
        echo "Inserisci il codice OTP: ";
        return trim(fgets(STDIN));
    });
} catch (AuthenticationException $e) {
    echo "Errore di autenticazione: {$e->getMessage()}\n";
    exit(1);
}

echo "Autenticato con successo.\n\n";

// ---------------------------------------------------------------------------
// Recupero e stampa dati utente
// ---------------------------------------------------------------------------

try {
    $response = $client->getUserMe();
    $user     = $response['data'] ?? [];

    echo "=== Dati utente ===\n";
    echo "Nome:           " . ($user['first_name']   ?? 'N/D') . "\n";
    echo "Cognome:        " . ($user['last_name']    ?? 'N/D') . "\n";
    echo "Email:          " . ($user['email']        ?? 'N/D') . "\n";
    echo "Telefono:       " . ($user['phone_number'] ?? 'N/D') . "\n";
    echo "Codice fiscale: " . ($user['fiscal_code']  ?? 'N/D') . "\n";
    echo "\nRisposta completa:\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

} catch (ApiException $e) {
    echo "Errore API (HTTP {$e->getHttpStatusCode()}): {$e->getMessage()}\n";
    exit(1);
}
