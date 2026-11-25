<?php

namespace App\Http\Requests;

use App\Rules\Turnstile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 1 of /verify: the phone number. The Turnstile token is checked
 * first: a bot gets no answer about whether a number has a card. The PIN
 * is asked on the next page (/verify/pin).
 */
class VerifyCustomerRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'cf-turnstile-response' => ['bail', 'required', new Turnstile($this->getHost(), $this->ip())],
            'phone' => ['bail', 'required', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cf-turnstile-response.required' => 'Selesaikan verifikasi keamanan terlebih dahulu.',
            'phone.required' => 'Tulis nomor HP Anda dulu.',
            'phone.max' => 'Nomor HP terlalu panjang.',
        ];
    }
}
