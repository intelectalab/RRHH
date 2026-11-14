<?php

namespace App\Modules\Documents\Database\Factories;

use App\Modules\Documents\Infrastructure\Models\DocumentCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Carpeta propia de la organización (no del sistema), activa.
 *
 * @extends Factory<DocumentCategory>
 */
class DocumentCategoryFactory extends Factory
{
    protected $model = DocumentCategory::class;

    public function definition(): array
    {
        return [
            'code' => 'f_'.Str::lower(Str::random(8)),
            'name' => 'Carpeta '.Str::random(5),
            'default_classification' => 'internal',
            'scope' => 'employee',
            'visible_to_employee' => true,
            'sort_order' => 500,
            'status' => 'active',
        ];
    }
}
