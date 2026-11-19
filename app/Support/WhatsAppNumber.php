<?php

namespace App\Support;

use App\Exceptions\InvalidWhatsAppNumberException;

/**
 * Turns what a cashier types into the one stored form: digits only, with
 * country code (6281234567890). The unique key on phone_number relies on
 * this; "0812-3456-7890", "+62 812 3456 7890" and "6281234567890" must all
 * map to the same customer.
 */
final class WhatsAppNumber
{
    private const INDONESIA = '62';

    public static function normalize(string $input): string
    {
        $input = trim($input);

        // Digits and the usual separators; "+" only as the first character.
        if (! preg_match('/^\+?[\d\s\-.()]+$/', $input)) {
            throw InvalidWhatsAppNumberException::for($input);
        }

        $digits = preg_replace('/\D/', '', $input);

        $number = match (true) {
            str_starts_with($input, '+') => $digits,
            str_starts_with($digits, '00') => substr($digits, 2),
            str_starts_with($digits, '0') => self::INDONESIA.substr($digits, 1),
            str_starts_with($digits, '8') => self::INDONESIA.$digits,
            default => $digits,
        };

        // "+62 0812..." is a common mix of both styles.
        if (str_starts_with($number, self::INDONESIA.'0')) {
            $number = self::INDONESIA.substr($number, 3);
        }

        if (! self::isValid($number)) {
            throw InvalidWhatsAppNumberException::for($input);
        }

        return $number;
    }

    private static function isValid(string $number): bool
    {
        // Indonesian mobile numbers: 08xx with 10 to 13 digits locally.
        if (str_starts_with($number, self::INDONESIA)) {
            return (bool) preg_match('/^628\d{8,11}$/', $number);
        }

        // Other countries: E.164, at most 15 digits.
        return (bool) preg_match('/^[1-9]\d{7,14}$/', $number);
    }
}
