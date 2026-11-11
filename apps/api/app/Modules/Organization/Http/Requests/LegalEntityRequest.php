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
            // CFG-2 · Datos fiscales y de presentación propios de la empresa (sin valor, hereda de la organización).
            'locale' => ['sometimes', 'nullable', 'string', 'in:es,en,pt'],
            'tax_regime' => ['sometimes', 'nullable', 'string', 'max:60'],
            'economic_activity_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'district_code' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9A-Za-z-]{2,12}$/'],
            'legal_representative_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['prohibited'],
            'has_logo' => ['prohibited'],
        ];
    }
}
