<?php

namespace App\Modules\Documents\Database\Factories;

use App\Modules\Documents\Infrastructure\Models\Document;
use App\Modules\Documents\Infrastructure\Models\SignatureRequest;
use App\Shared\Documents\DocumentArchive;
use App\Shared\Documents\SimplePdf;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DOC-6 · Solicitud de firma en Borrador sobre un documento nuevo del legajo (con su primera versión). Solo se registra
 * en base (archivo y huella); la prueba que necesita el contenido lo escribe en el almacenamiento falso.
 *
 * @extends Factory<SignatureRequest>
 */
class SignatureRequestFactory extends Factory
{
    protected $model = SignatureRequest::class;

    public function definition(): array
    {
        return [
            'document_id' => fn (): string => self::document(),
            'document_version_id' => fn (array $a): string => (string) DB::table('documents')->where('id', $a['document_id'])->value('current_version_id'),
            'title' => 'Documento para firmar',
            'signing_order' => 'sequential',
            'signature_level' => 'advanced',
            'provider' => 'internal',
            'status' => 'draft',
        ];
    }

    /** Documento con una versión cuyo archivo es un PDF simple (tipo documental «signature_factory»). */
    public static function document(): string
    {
        $tenant = TenancyScope::requireTenant();
        $category = app(DocumentArchive::class)->category('signature_factory', 'Documentos para firmar', 'documents.documents.view');
        $document = Document::factory()->create(['document_category_id' => $category, 'status' => 'active', 'source' => 'generated'])->id;
        $contents = SimplePdf::render('Documento para firmar', 'Contenido de prueba '.Str::random(8).'.');
        $key = 'tenants/'.$tenant.'/documents/'.Str::uuid().'.pdf';
        $file = (string) DB::table('stored_files')->insertGetId([
            'tenant_id' => $tenant, 'storage_key' => $key, 'original_name' => 'documento.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents), 'sha256' => hash('sha256', $contents), 'classification' => 'confidential', 'scan_status' => 'clean',
        ]);
        $version = (string) DB::table('document_versions')->insertGetId([
            'tenant_id' => $tenant, 'document_id' => $document, 'version' => 1, 'stored_file_id' => $file, 'content_hash' => hash('sha256', $contents),
        ]);
        DB::table('documents')->where('id', $document)->update(['current_version_id' => $version]);

        return $document;
    }
}
