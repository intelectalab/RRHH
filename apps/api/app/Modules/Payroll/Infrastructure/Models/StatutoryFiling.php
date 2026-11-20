<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\StatutoryFilingFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DEC-2 · DEC-3 · DEC-4 · Declaración o archivo oficial de una empresa y periodo (ME-24).
 *
 * El caso de uso trabaja con el constructor de consultas; el modelo sirve al alcance por organización y a las fábricas.
 *
 * @property string $id
 * @property string $tenant_id
 */
class StatutoryFiling extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<StatutoryFilingFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'statutory_filings';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): StatutoryFilingFactory
    {
        return StatutoryFilingFactory::new();
    }
}
