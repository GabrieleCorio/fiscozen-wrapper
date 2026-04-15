<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Exceptions;

class OtpRequiredException extends \RuntimeException
{
    public function __construct(private readonly string $phoneNumber)
    {
        parent::__construct("OTP required for phone: {$phoneNumber}");
    }

    public function getPhoneNumber(): string
    {
        return $this->phoneNumber;
    }
}
