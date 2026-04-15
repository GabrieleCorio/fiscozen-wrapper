<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Exceptions;

class ApiException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $httpStatusCode = 0)
    {
        parent::__construct($message);
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
