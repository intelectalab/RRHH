<?php

namespace App\Modules\Documents\Database\Factories;

use App\Modules\Documents\Infrastructure\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DOC-4 · Plantilla en borrador con su primera versión.
 *
 * @extends Factory<DocumentTemplate>
 */
class DocumentTemplateFactory extends Factory
{
    protected $model = DocumentTemplate::class;

    public function definition(): array
    {
        return ['code' => 'plantilla_'.Str::lower(Str::random(8)), 'name' => 'Plantilla '.Str::random(5), 'kind' => 'letter', 'locale' => 'es'];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (DocumentTemplate $t): void {
            DB::table('document_template_versions')->insert(['tenant_id' => $t->tenant_id, 'document_template_id' => $t->id, 'version' => 1,
                'body' => 'Hola {{empleado.nombre_completo}}']);
        });
    }
}
