<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;

$client = fiscozenClientFromEnv();
requireAuthenticatedSession($client);

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
    fwrite(STDERR, "Errore API (HTTP {$e->getHttpStatusCode()}): {$e->getMessage()}\n");
    exit(1);
}
