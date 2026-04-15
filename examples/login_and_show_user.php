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

$client = new FiscozenClient(
    email: $email,
    password: $password,
    sessionDir: $sessionDir,
);

// ---------------------------------------------------------------------------
// Autenticazione in due step separati
//
// Questo esempio mostra il flusso pensato per applicazioni web, dove l'invio
// delle credenziali e l'inserimento dell'OTP avvengono in request HTTP separate.
//
// Per un contesto CLI/test è possibile usare il metodo sincrono:
//
//   $client->login(function (string $phone): string {
//       echo "OTP inviato a {$phone}: ";
//       return trim(fgets(STDIN));
//   });
// ---------------------------------------------------------------------------

echo "Controllo sessione...\n";

if ($client->isAuthenticated()) {
    echo "Sessione già attiva, skip del login.\n\n";
} else {
    // Step 1: invia le credenziali — i cookie intermedi vengono persistiti su disco.
    try {
        $phoneNumber = $client->startLogin();
        echo "OTP inviato al numero: {$phoneNumber}\n";
    } catch (AuthenticationException $e) {
        echo "Errore di autenticazione: {$e->getMessage()}\n";
        exit(1);
    }

    // In una web app qui finisce la prima request e inizia la seconda.
    // In questo script CLI chiediamo subito l'OTP da terminale.
    echo "Inserisci il codice OTP: ";
    $otp = trim(fgets(STDIN));

    // Step 2: verifica l'OTP e completa l'autenticazione.
    try {
        $client->submitOtp($otp);
    } catch (AuthenticationException $e) {
        echo "Errore di autenticazione: {$e->getMessage()}\n";
        exit(1);
    }

    echo "Autenticato con successo.\n\n";
}

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
