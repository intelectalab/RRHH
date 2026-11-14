<?php

namespace App\Modules\Absences\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AUS-3 · Certificado médico de un descanso (CITT o particular). Número y diagnóstico van cifrados
 * (FieldCipher) y solo viajan con time.absences.medical_certificate.view; cada lectura queda en data_access_logs.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $absence_request_id
 * @property string $kind
 * @property string|null $certificate_number_enc
 * @property string|null $issuer
 * @property string|null $diagnosis_enc
 * @property string|null $issued_on
 * @property string|null $file_id
 * @property int|null $days_granted
 * @property bool $is_work_related
 * @property string|null $validated_by_user_id
 * @property CarbonInterface|null $validated_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class AbsenceMedicalCertificate extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'absence_medical_certificates';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['kind' => 'citt', 'is_work_related' => false];

    protected $casts = ['days_granted' => 'integer', 'is_work_related' => 'boolean', 'validated_at' => 'datetime'];

    protected $hidden = ['certificate_number_enc', 'diagnosis_enc'];
}
