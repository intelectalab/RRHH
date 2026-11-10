<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'confirmed', 'max:200',
                Password::min((int) config('identity.password_min_length'))->letters()->numbers()],
        ];
    }
}
