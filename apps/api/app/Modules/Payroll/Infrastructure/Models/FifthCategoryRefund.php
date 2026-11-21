<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\FifthCategoryRefundFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DEC-11 · Devolución de un exceso de retención de quinta (ME-49).
 *
 * El caso de uso trabaja con el constructor de consultas; el modelo sirve al alcance por organización y a las fábricas.
 *
 * @property string $id
 * @property string $tenant_id
 */
class FifthCategoryRefund extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<FifthCategoryRefundFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'fifth_category_refunds';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): FifthCategoryRefundFactory
    {
        return FifthCategoryRefundFactory::new();
    }
}
