<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\RoleScopes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RoleRequest extends FormRequest
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
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array', 'max:2000'],
            'permissions.*' => ['string', 'max:150'],
            'field_permissions' => ['sometimes', 'array', 'max:500'],
            'field_permissions.*' => ['string', 'max:150'],
            'scope' => ['sometimes', 'string', Rule::in(RoleScopes::apiValues())],
            // `system` lo fija el servidor: un rol creado por la API nunca es de sistema.
            'system' => ['sometimes', 'boolean', 'declined'],
        ];
    }
}
