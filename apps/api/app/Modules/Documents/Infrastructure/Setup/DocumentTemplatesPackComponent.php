<?php

namespace App\Modules\Documents\Infrastructure\Setup;

use App\Modules\Documents\Application\DocumentTemplateService;
use App\Shared\Setup\CountryPackComponent;
use Illuminate\Support\Facades\DB;

/**
 * IMP-4 · Plantillas de documentos del paquete de país (DOC-4: certificado y constancia de trabajo) y los
 * tipos documentales del legajo que necesitan. Se editan como borrador y se publican como versión nueva
 * en Documentos → Plantillas.
 */
final class DocumentTemplatesPackComponent implements CountryPackComponent
{
    public function __construct(private readonly DocumentTemplateService $templates) {}

    public function code(): string
    {
        return 'document_templates';
    }

    public function moduleCode(): string
    {
        return 'core';
    }

    public function items(string $country): array
    {
        return array_values(array_map(fn (array $t) => $t['name'], $this->defaults($country)));
    }

    public function present(string $country): int
    {
        return DB::table('document_templates')->whereIn('code', array_keys($this->defaults($country)))->count();
    }

    public function apply(string $country, ?string $userId): void
    {
        $this->templates->ensureDefaults();
    }

    public function link(): string
    {
        return '/documentos/plantillas';
    }

    /** @return array<string, array{name: string}> */
    private function defaults(string $country): array
    {
        $file = base_path('packages/country-'.strtolower($country).'/data/plantillas_documentos.php');
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1 || ! is_file($file)) {
            return [];
        }

        /** @var array<string, array{name: string}> */
        return require $file;
    }
}
