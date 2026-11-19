<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Validates a Cloudflare Turnstile token (the cf-turnstile-response field)
 * with Cloudflare's siteverify API. Tokens are single use and expire after
 * 5 minutes, so a resubmitted form needs a fresh widget.
 */
class Turnstile implements ValidationRule
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private ?string $remoteIp = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2048) {
            $fail('Selesaikan verifikasi keamanan terlebih dahulu.');

            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(8)
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => config('services.turnstile.secret_key'),
                    'response' => $value,
                    'remoteip' => $this->remoteIp,
                ]));
        } catch (ConnectionException $e) {
            Log::warning('Turnstile siteverify unreachable', ['message' => $e->getMessage()]);
            $fail('Verifikasi keamanan sedang tidak dapat diproses. Silakan coba lagi.');

            return;
        }

        if ($response->json('success') !== true) {
            Log::info('Turnstile rejected a token', ['errors' => $response->json('error-codes')]);
            $fail('Verifikasi keamanan gagal. Silakan coba lagi.');
        }
    }
}
