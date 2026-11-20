<?php

namespace Tests\Unit;

use App\Exceptions\InvalidWhatsAppNumberException;
use App\Support\WhatsAppNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WhatsAppNumberTest extends TestCase
{
    #[DataProvider('sameNumberWrittenDifferently')]
    public function test_every_common_style_maps_to_one_stored_form(string $input): void
    {
        $this->assertSame('6281234567890', WhatsAppNumber::normalize($input));
    }

    public static function sameNumberWrittenDifferently(): array
    {
        return [
            'local' => ['081234567890'],
            'local with dashes' => ['0812-3456-7890'],
            'without the leading zero' => ['81234567890'],
            'country code' => ['6281234567890'],
            'plus and spaces' => ['+62 812 3456 7890'],
            'plus and the local zero' => ['+62 0812-3456-7890'],
            '00 international prefix' => ['006281234567890'],
            'surrounding whitespace' => ['  0812 3456 7890  '],
            'parentheses and dots' => ['(0812) 3456.7890'],
        ];
    }

    public function test_foreign_numbers_keep_their_country_code(): void
    {
        $this->assertSame('6591234567', WhatsAppNumber::normalize('+65 9123 4567'));
    }

    public function test_a_configured_test_number_is_accepted(): void
    {
        WhatsAppNumber::allowTestNumbers(['0987654321', 'bukan nomor']);

        try {
            $this->assertSame('62987654321', WhatsAppNumber::normalize('0987-654-321'));
        } finally {
            WhatsAppNumber::allowTestNumbers([]);
        }

        $this->expectException(InvalidWhatsAppNumberException::class);
        WhatsAppNumber::normalize('0987654321');
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected(string $input): void
    {
        $this->expectException(InvalidWhatsAppNumberException::class);

        WhatsAppNumber::normalize($input);
    }

    public static function invalidInputs(): array
    {
        return [
            'empty' => [''],
            'only a plus' => ['+'],
            'letters' => ['0812abc7890'],
            'plus in the middle' => ['0812+34567890'],
            'too short' => ['0812345'],
            'too long' => ['08123456789012'],
            'landline, not mobile' => ['021-1234567'],
        ];
    }
}
