<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserRequest extends FormRequest
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
        $creating = $this->isMethod('post');

        return [
            'login' => [$creating ? 'required' : 'sometimes', 'string', 'min:3', 'max:80', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'display_name' => ['sometimes', 'string', 'min:2', 'max:150'],
            // RN-SEG-09: el correo nunca es obligatorio.
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:254'],
            'person_id' => ['sometimes', 'nullable', 'uuid'],
            'role_ids' => ['sometimes', 'array', 'max:20'],
            'role_ids.*' => ['string'],
            // SEG-10: rol con alcance propio y unidades concretas (reemplaza a role_ids si viene).
            'role_assignments' => ['sometimes', 'array', 'max:20'],
            'role_assignments.*.role_id' => ['required', 'uuid'],
            'role_assignments.*.scope' => ['sometimes', 'nullable', 'string', 'in:organization,legal_entity,site,unit,team,self'],
            'role_assignments.*.legal_entity_ids' => ['sometimes', 'array'],
            'role_assignments.*.legal_entity_ids.*' => ['uuid'],
            'role_assignments.*.site_ids' => ['sometimes', 'array'],
            'role_assignments.*.site_ids.*' => ['uuid'],
            'role_assignments.*.org_unit_ids' => ['sometimes', 'array'],
            'role_assignments.*.org_unit_ids.*' => ['uuid'],
            // El estado solo cambia por acciones (regla 3): se rechaza aquí.
            'status' => ['prohibited'],
            'mfa_enabled' => ['prohibited'],
        ];
    }
}
