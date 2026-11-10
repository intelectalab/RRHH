<?php

namespace App\Modules\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LegalEntityRequest extends FormRequest
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
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'min:2', 'max:200'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'tax_id' => [$req, 'string', 'max:30'],
            'country' => [$this->isMethod('post') ? 'required' : 'prohibited', 'string', 'size:2'],
            'currency' => [$req, 'string', 'size:3'],
            'timezone' => [$req, 'string', 'timezone:all'],
            'payroll_regime' => [$req, 'string', 'max:40'],
            'status' => ['prohibited'],
        ];
    }
}
