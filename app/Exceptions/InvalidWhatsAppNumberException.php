<?php

namespace App\Exceptions;

use InvalidArgumentException;

class InvalidWhatsAppNumberException extends InvalidArgumentException
{
    public static function for(string $input): self
    {
        return new self(sprintf('Nomor WhatsApp tidak valid: "%s".', $input));
    }
}
