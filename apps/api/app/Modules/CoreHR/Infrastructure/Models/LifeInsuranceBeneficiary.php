<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Modules\CoreHR\Database\Factories\LifeInsuranceBeneficiaryFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * COL-7 · Beneficiario de Vida Ley declarado por el trabajador. Cada declaración (declaration_id) agrupa
 * sus beneficiarios con el archivo firmado; nombre y documento del tercero viajan cifrados.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string|null $insurance_policy_member_id
 * @property string $full_name_enc
 * @property string|null $document_number_enc
 * @property string|null $relationship
 * @property string|null $percentage
 * @property CarbonInterface|null $declared_on
 * @property string $declaration_id
 * @property string|null $declaration_file_id
 * @property CarbonInterface|null $signed_on
 * @property string $status
 * @property CarbonInterface $created_at
 */
class LifeInsuranceBeneficiary extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<LifeInsuranceBeneficiaryFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'insurance_beneficiaries';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['full_name_enc', 'document_number_enc'];

    protected $casts = ['declared_on' => 'date', 'signed_on' => 'date'];

    protected static function newFactory(): LifeInsuranceBeneficiaryFactory
    {
        return LifeInsuranceBeneficiaryFactory::new();
    }
}
