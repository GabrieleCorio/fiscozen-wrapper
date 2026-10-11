<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use GabrieleCorio\FiscozenWrapper\Cli\ReadCommand;
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;
use GabrieleCorio\FiscozenWrapper\Exceptions\InvoiceNotFoundException;

const USAGE = "Uso: php examples/read_invoice.php --number=N --year=YYYY\n";

try {
    $options = parseOptions(array_slice($argv, 1));
    ReadCommand::validateRequest('invoice', $options);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n" . USAGE);
    exit(2);
}

$client = fiscozenClientFromEnv();
requireAuthenticatedSession($client);

try {
    $result = (new ReadCommand($client))->execute('invoice', $options);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (InvoiceNotFoundException $e) {
    fwrite(STDERR, "Fattura non trovata.\n");
    exit(4);
} catch (ApiException $e) {
    fwrite(STDERR, "Lettura non riuscita (HTTP {$e->getHttpStatusCode()}).\n");
    exit(4);
}
