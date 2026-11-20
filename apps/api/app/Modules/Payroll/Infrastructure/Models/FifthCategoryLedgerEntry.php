<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\FifthCategoryLedgerEntryFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * PER-5 · PER-11 · Movimiento del libro de retenciones de quinta (solo inserción: la base rechaza cambios y
 * borrados). La consulta va por FifthCategoryLedger; el modelo existe para sembrar datos en las pruebas.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 */
class FifthCategoryLedgerEntry extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<FifthCategoryLedgerEntryFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'fifth_category_ledger';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['parameters' => 'array', 'trace' => 'array'];

    protected static function newFactory(): FifthCategoryLedgerEntryFactory
    {
        return FifthCategoryLedgerEntryFactory::new();
    }
}
