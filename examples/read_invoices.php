<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use GabrieleCorio\FiscozenWrapper\Cli\ReadCommand;
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;

const USAGE = "Uso: php examples/read_invoices.php [--page=N] [--page-size=N] [--counterpart=ID[,ID...] | --counterpart-search=TESTO] [--date-from=YYYY-MM-DD] [--date-to=YYYY-MM-DD]\n";

try {
    $options = parseOptions(array_slice($argv, 1));
    ReadCommand::validateRequest('invoices', $options);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n" . USAGE);
    exit(2);
}

$client = fiscozenClientFromEnv();
requireAuthenticatedSession($client);

try {
    $result = (new ReadCommand($client))->execute('invoices', $options);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (ApiException $e) {
    fwrite(STDERR, "Lettura non riuscita (HTTP {$e->getHttpStatusCode()}).\n");
    exit(4);
}
