<?php

namespace App\Modules\Documents\Database\Factories;

use App\Modules\Documents\Infrastructure\Models\DocumentCategory;
use App\Modules\Documents\Infrastructure\Models\DocumentRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * DOC-3 · Solicitud pendiente de entrega a una relación laboral nueva.
 *
 * @extends Factory<DocumentRequest>
 */
class DocumentRequestFactory extends Factory
{
    protected $model = DocumentRequest::class;

    public function definition(): array
    {
        return [
            'document_category_id' => fn (): string => DocumentCategory::factory()->create()->id,
            'employment_id' => fn (): string => DocumentFactory::employment(),
            'person_id' => fn (array $a): ?string => DB::table('employments')->where('id', $a['employment_id'])->value('person_id'),
            'title' => 'Documento solicitado',
            'due_on' => now()->addWeek()->toDateString(),
        ];
    }
}
