<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\BankFileTemplateFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DEC-5 · Plantilla de archivo bancario propia de la organización.
 *
 * El caso de uso trabaja con el constructor de consultas; el modelo sirve al alcance por organización y a las fábricas.
 *
 * @property string $id
 * @property string $tenant_id
 */
class BankFileTemplate extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BankFileTemplateFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'bank_file_templates';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): BankFileTemplateFactory
    {
        return BankFileTemplateFactory::new();
    }
}
