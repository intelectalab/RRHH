<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
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
            'email' => ['required_without_all:login,document_number', 'nullable', 'string', 'email:rfc', 'max:254'],
            'login' => ['nullable', 'string', 'max:80'],
            'document_type' => ['required_with:document_number', 'nullable', 'string', 'max:20'],
            'document_number' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'max:200'],
        ];
    }
}
