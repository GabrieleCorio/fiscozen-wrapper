<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use GabrieleCorio\FiscozenWrapper\Exceptions\AuthenticationException;

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

$client = fiscozenClientFromEnv();

echo "Controllo sessione...\n";

if ($client->isAuthenticated()) {
    echo "Sessione già attiva, nessun login necessario.\n";
    exit(0);
}

// Step 1: invia le credenziali — i cookie intermedi vengono persistiti su disco.
try {
    $phoneNumber = $client->startLogin();
    echo "OTP inviato al numero: {$phoneNumber}\n";
} catch (AuthenticationException $e) {
    fwrite(STDERR, "Errore di autenticazione: {$e->getMessage()}\n");
    exit(1);
}

echo "Inserisci il codice OTP: ";
$otp = trim((string) fgets(STDIN));

// Step 2: verifica l'OTP e completa l'autenticazione.
try {
    $client->submitOtp($otp);
} catch (AuthenticationException $e) {
    fwrite(STDERR, "Errore di autenticazione: {$e->getMessage()}\n");
    exit(1);
}

echo "Autenticato con successo.\n";
