<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\LoginDocument;
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
            // SEG-2: quien no tiene correo puede tener solo documento; el usuario (login) es opcional si hay documento.
            'login' => [$creating ? 'required_without:document_number' : 'sometimes', 'nullable', 'string', 'min:3', 'max:80', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'document_type' => ['required_with:document_number', 'nullable', 'string', 'in:'.implode(',', LoginDocument::TYPES)],
            'document_number' => ['sometimes', 'nullable', 'string', 'min:4', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],
            // Celular para recibir códigos por SMS o WhatsApp, con código de país.
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9 ()-]{8,20}$/'],
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
