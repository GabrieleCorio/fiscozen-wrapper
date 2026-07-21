<?php

declare(strict_types=1);

use GabrieleCorio\FiscozenWrapper\Cli\ReadCommand;
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;
use GabrieleCorio\FiscozenWrapper\Exceptions\AuthenticationException;
use GabrieleCorio\FiscozenWrapper\FiscozenClient;

require dirname(__DIR__) . '/vendor/autoload.php';

const USAGE = <<<'TEXT'
Uso:
  php examples/read_data.php dashboard --year=YYYY
  php examples/read_data.php invoices [--page=N] [--page-size=N]
  php examples/read_data.php counterparts
  php examples/read_data.php payment-methods [--page=N] [--page-size=N]

Variabili d'ambiente richieste:
  FISCOZEN_EMAIL
  FISCOZEN_PASSWORD

Variabile opzionale:
  FISCOZEN_SESSION_DIR
TEXT;

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

if ($argc < 2 || in_array($argv[1], ['help', '--help', '-h'], true)) {
    fwrite(STDERR, USAGE . PHP_EOL);
    exit($argc < 2 ? 2 : 0);
}

try {
    $command = $argv[1];
    if (!in_array($command, ['dashboard', 'invoices', 'counterparts', 'payment-methods'], true)) {
        throw new InvalidArgumentException("Comando non supportato: {$command}");
    }
    $options = parseOptions(array_slice($argv, 2));
    ReadCommand::validateRequest($command, $options);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . USAGE . PHP_EOL);
    exit(2);
}

$email = getenv('FISCOZEN_EMAIL');
$password = getenv('FISCOZEN_PASSWORD');
if (!is_string($email) || $email === '' || !is_string($password) || $password === '') {
    fwrite(STDERR, "FISCOZEN_EMAIL e FISCOZEN_PASSWORD devono essere impostate nell'ambiente.\n");
    exit(2);
}

$configuredSessionDir = getenv('FISCOZEN_SESSION_DIR');
$sessionDir = is_string($configuredSessionDir) && $configuredSessionDir !== ''
    ? $configuredSessionDir
    : dirname(__DIR__) . '/sessions';

try {
    $client = new FiscozenClient($email, $password, $sessionDir);
    $client->login(static function (string $maskedPhoneNumber): string {
        fwrite(STDERR, "OTP inviato al numero {$maskedPhoneNumber}. Inserisci OTP: ");
        $otp = fgets(STDIN);
        if ($otp === false || trim($otp) === '') {
            throw new AuthenticationException('OTP non fornito');
        }

        return trim($otp);
    });

    $result = (new ReadCommand($client))->execute($command, $options);
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . USAGE . PHP_EOL);
    exit(2);
} catch (AuthenticationException $e) {
    fwrite(STDERR, "Autenticazione non riuscita.\n");
    exit(3);
} catch (ApiException $e) {
    $status = $e->getHttpStatusCode();
    fwrite(STDERR, $status > 0 ? "Lettura non riuscita (HTTP {$status}).\n" : "Lettura non riuscita.\n");
    exit(4);
} catch (JsonException) {
    fwrite(STDERR, "La risposta non può essere convertita in JSON.\n");
    exit(5);
}
