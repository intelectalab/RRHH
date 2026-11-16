<?php

namespace App\Modules\Documents\Database\Factories;

use App\Modules\Documents\Infrastructure\Models\DocumentGenerationBatch;
use App\Modules\Documents\Infrastructure\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * DOC-5 · Lote de generación terminado sobre una plantilla nueva.
 *
 * @extends Factory<DocumentGenerationBatch>
 */
class DocumentGenerationBatchFactory extends Factory
{
    protected $model = DocumentGenerationBatch::class;

    public function definition(): array
    {
        return [
            'document_template_id' => fn (): string => DocumentTemplate::factory()->create()->id,
            'document_template_version_id' => fn (array $a): string => (string) DB::table('document_template_versions')->where('document_template_id', $a['document_template_id'])->value('id'),
            'status' => 'completed', 'total' => 0, 'items' => [],
        ];
    }
}
