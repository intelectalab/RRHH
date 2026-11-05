<?php

namespace Tests\Feature;

use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Files\FileService;
use App\Shared\Files\Models\StoredFile;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\OutboxService;
use App\Shared\Outbox\ProcessedEventTracker;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\TenantTestHelper;
use Tests\TestCase;

class FileServiceTest extends TestCase
{
    use DatabaseTransactions;
    use TenantTestHelper;

    private string $tenantId;

    private FileService $fileService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->createAndBindTenant();

        // Usamos fake storage para pruebas rápidas y aisladas
        Storage::fake('s3');
        config(['files.disk' => 's3']);
        config(['files.max_size_bytes' => 1024 * 1024]); // 1 MB para tests
        config(['files.allowed_mimes' => ['application/pdf', 'image/png', 'image/jpeg']]);

        $outboxService = new OutboxService(app(ProcessedEventTracker::class));
        $this->fileService = new FileService($outboxService);
    }

    public function test_upload_stores_file_in_storage_and_records_metadata_and_outbox(): void
    {
        $file = UploadedFile::fake()->create('contrato_laboral.pdf', 200, 'application/pdf');

        $storedFile = $this->fileService->upload(
            fileContent: $file,
            originalName: 'contrato_laboral.pdf',
            mimeType: 'application/pdf',
            tenantId: $this->tenantId,
        );

        $this->assertInstanceOf(StoredFile::class, $storedFile);
        $this->assertSame($this->tenantId, $storedFile->tenant_id);
        $this->assertSame('contrato_laboral.pdf', $storedFile->original_name);
        $this->assertSame('application/pdf', $storedFile->mime_type);
        $this->assertStringStartsWith("tenants/{$this->tenantId}/", $storedFile->storage_key);

        // Verifica almacenamiento físico
        Storage::disk('s3')->assertExists($storedFile->storage_key);

        // Verifica registro en base de datos
        $this->assertDatabaseHas('stored_files', [
            'id' => $storedFile->id,
            'tenant_id' => $this->tenantId,
            'original_name' => 'contrato_laboral.pdf',
            'mime_type' => 'application/pdf',
        ]);

        // Verifica que se emitió evento de outbox document.uploaded.v1
        $this->assertDatabaseHas('outbox_events', [
            'tenant_id' => $this->tenantId,
            'event_type' => DomainEventTypes::DOCUMENT_UPLOADED,
            'aggregate_id' => $storedFile->id,
        ]);
    }

    public function test_upload_rejects_disallowed_mime_types(): void
    {
        $file = UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload');

        $this->expectException(BusinessRuleViolatedException::class);
        $this->expectExceptionMessage("tipo de archivo 'application/x-msdownload' no está permitido");

        $this->fileService->upload(
            fileContent: $file,
            originalName: 'malware.exe',
            mimeType: 'application/x-msdownload',
            tenantId: $this->tenantId,
        );
    }

    public function test_upload_rejects_files_exceeding_max_configured_size(): void
    {
        // 2 MB supera el límite de 1 MB configurado en setUp
        $file = UploadedFile::fake()->create('archivo_pesado.pdf', 2048, 'application/pdf');

        $this->expectException(BusinessRuleViolatedException::class);
        $this->expectExceptionMessage('supera el tamaño máximo permitido');

        $this->fileService->upload(
            fileContent: $file,
            originalName: 'archivo_pesado.pdf',
            mimeType: 'application/pdf',
            tenantId: $this->tenantId,
        );
    }

    public function test_get_signed_url_generates_temporary_url_and_logs_download(): void
    {
        $file = UploadedFile::fake()->create('boleta_pago.pdf', 50, 'application/pdf');

        $storedFile = $this->fileService->upload(
            fileContent: $file,
            originalName: 'boleta_pago.pdf',
            mimeType: 'application/pdf',
            tenantId: $this->tenantId,
            classification: StoredFile::CLASSIFICATION_CONFIDENTIAL,
        );

        $signedUrl = $this->fileService->getSignedUrl(
            fileId: $storedFile->id,
            tenantId: $this->tenantId,
            userId: null,
            ttlSeconds: 300,
        );

        $this->assertNotEmpty($signedUrl);

        // Verifica que la descarga quedó registrada en activity_events
        $this->assertDatabaseHas('activity_events', [
            'tenant_id' => $this->tenantId,
            'activity' => 'file.downloaded',
            'subject_type' => 'stored_file',
            'subject_id' => $storedFile->id,
        ]);

        // Por ser archivo CONFIDENTIAL, debe registrar también en data_access_logs (RN-PLT-07)
        $this->assertDatabaseHas('data_access_logs', [
            'tenant_id' => $this->tenantId,
            'access_kind' => 'download',
            'entity_type' => 'stored_file',
            'entity_id' => $storedFile->id,
        ]);
    }

    public function test_file_from_another_tenant_is_not_accessible(): void
    {
        $file = UploadedFile::fake()->create('documento_privado.pdf', 50, 'application/pdf');

        $storedFile = $this->fileService->upload(
            fileContent: $file,
            originalName: 'documento_privado.pdf',
            mimeType: 'application/pdf',
            tenantId: $this->tenantId,
        );

        // Otro tenant intenta acceder
        $otherTenantId = (string) Str::uuid();

        $this->expectException(ModelNotFoundException::class);

        $this->fileService->getSignedUrl(
            fileId: $storedFile->id,
            tenantId: $otherTenantId,
        );
    }
}
