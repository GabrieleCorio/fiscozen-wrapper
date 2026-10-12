<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Exceptions;

/**
 * Nessuna fattura corrisponde a numero e anno richiesti.
 */
final class InvoiceNotFoundException extends ApiException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 404);
    }
}
