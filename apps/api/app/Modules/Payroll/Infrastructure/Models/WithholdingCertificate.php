<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\WithholdingCertificateFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DEC-6 · Certificado de rentas y retenciones de quinta emitido.
 *
 * El caso de uso trabaja con el constructor de consultas; el modelo sirve al alcance por organización y a las fábricas.
 *
 * @property string $id
 * @property string $tenant_id
 */
class WithholdingCertificate extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<WithholdingCertificateFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'withholding_certificates';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): WithholdingCertificateFactory
    {
        return WithholdingCertificateFactory::new();
    }
}
