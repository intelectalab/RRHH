<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            // La política (longitud, letras y números, historial) la aplica PasswordPolicy con los
            // parámetros de la organización (SEG-5).
            'password' => ['required', 'string', 'confirmed', 'max:200'],
        ];
    }
}
