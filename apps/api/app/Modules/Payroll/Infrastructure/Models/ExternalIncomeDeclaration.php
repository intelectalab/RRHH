<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\ExternalIncomeDeclarationFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DEC-9 · Declaración jurada de rentas de otros empleadores.
 *
 * El caso de uso trabaja con el constructor de consultas; el modelo sirve al alcance por organización y a las fábricas.
 *
 * @property string $id
 * @property string $tenant_id
 */
class ExternalIncomeDeclaration extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ExternalIncomeDeclarationFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'external_income_declarations';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): ExternalIncomeDeclarationFactory
    {
        return ExternalIncomeDeclarationFactory::new();
    }
}
