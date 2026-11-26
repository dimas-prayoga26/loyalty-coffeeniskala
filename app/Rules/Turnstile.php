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
 *
 * In production the token must belong to this form on the configured
 * application hostname.
 */
class Turnstile implements ValidationRule
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** The widget's data-action on /verify. */
    public const ACTION = 'verify';

    public function __construct(
        private string $expectedHostname,
        private ?string $remoteIp = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2048) {
            $fail('Selesaikan verifikasi keamanan terlebih dahulu.');

            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
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

        if (! $response->successful() || $response->json('success') !== true) {
            Log::info('Turnstile rejected a token', ['errors' => $response->json('error-codes')]);
            $fail('Verifikasi keamanan gagal. Silakan coba lagi.');

            return;
        }

        // Cloudflare's always-pass testing keys (local, demo) answer for
        // example.com without an action: nothing more to compare.
        $production = app()->isProduction();
        if ($response->json('metadata.result_with_testing_key') === true) {
            if ($production) {
                $fail('Verifikasi keamanan gagal. Silakan coba lagi.');
            }

            return;
        }

        $expectedHostname = $production ? parse_url(config('app.url'), PHP_URL_HOST) : $this->expectedHostname;
        $hostname = $response->json('hostname');

        $hostnameMatches = is_string($hostname) && is_string($expectedHostname) && $expectedHostname !== ''
            && strcasecmp($hostname, $expectedHostname) === 0;

        if ($response->json('action') !== self::ACTION || ! $hostnameMatches) {
            Log::warning('Turnstile token made for another form or domain', [
                'action' => $response->json('action'),
                'hostname' => $hostname,
                'expected_hostname' => $expectedHostname,
            ]);
            $fail('Verifikasi keamanan gagal. Silakan coba lagi.');
        }
    }
}
