<?php

namespace Tests\Feature;

use App\Rules\Turnstile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The /verify Turnstile check against a faked Cloudflare siteverify.
 */
class TurnstileRuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.turnstile.secret_key' => 'test-secret']);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function cloudflareAnswers(array $answer, int $status = 200): void
    {
        Http::fake(['challenges.cloudflare.com/*' => Http::response($answer, $status)]);
    }

    private function passes(string $token = 'token-from-the-widget', string $host = 'coffeeniskala.com'): bool
    {
        return Validator::make(
            ['cf-turnstile-response' => $token],
            ['cf-turnstile-response' => [new Turnstile($host, '203.0.113.7')]],
        )->passes();
    }

    public function test_a_token_from_the_verify_form_on_this_domain_passes(): void
    {
        $this->cloudflareAnswers(['success' => true, 'hostname' => 'coffeeniskala.com', 'action' => 'verify']);

        $this->assertTrue($this->passes());

        Http::assertSent(fn (Request $request) => $request->isForm()
            && $request['secret'] === 'test-secret'
            && $request['response'] === 'token-from-the-widget'
            && $request['remoteip'] === '203.0.113.7');
    }

    public function test_a_token_solved_on_another_domain_fails(): void
    {
        // The widget also allows localhost: a token made there is not for production.
        $this->cloudflareAnswers(['success' => true, 'hostname' => 'localhost', 'action' => 'verify']);

        $this->assertFalse($this->passes());
    }

    public function test_a_token_made_for_another_action_fails(): void
    {
        $this->cloudflareAnswers(['success' => true, 'hostname' => 'coffeeniskala.com', 'action' => 'login']);

        $this->assertFalse($this->passes());
    }

    public function test_a_used_or_invalid_token_fails(): void
    {
        $this->cloudflareAnswers(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);

        $this->assertFalse($this->passes());
    }

    public function test_an_error_status_from_cloudflare_fails(): void
    {
        $this->cloudflareAnswers(['success' => true, 'hostname' => 'coffeeniskala.com', 'action' => 'verify'], 500);

        $this->assertFalse($this->passes());
    }

    public function test_cloudflare_out_of_reach_fails(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->assertFalse($this->passes());
    }

    public function test_the_testing_keys_used_locally_and_on_the_demo_still_pass(): void
    {
        // What Cloudflare answers for the always-pass testing secret.
        $this->cloudflareAnswers([
            'success' => true,
            'hostname' => 'example.com',
            'metadata' => ['result_with_testing_key' => true],
        ]);

        $this->assertTrue($this->passes(host: 'demo-loyalty.kopiniskala.com'));
    }

    public function test_an_oversized_token_fails_without_asking_cloudflare(): void
    {
        // An empty one never gets here: the request's "required" stops it first.
        Http::fake();

        $this->assertFalse($this->passes(str_repeat('a', 2049)));

        Http::assertNothingSent();
    }
}
