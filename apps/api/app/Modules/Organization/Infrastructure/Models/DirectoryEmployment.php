<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\DirectoryEmploymentFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ORG-7 · Lectura de una relación laboral para el directorio y el organigrama. El módulo de
 * colaboradores (COL) es el dueño de employments; este modelo solo existe para sembrar datos en las
 * pruebas (aislamiento) y Organización no escribe con él.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $person_id
 * @property string $legal_entity_id
 * @property string $employee_code
 * @property string $status
 */
class DirectoryEmployment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<DirectoryEmploymentFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'employments';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected static function newFactory(): DirectoryEmploymentFactory
    {
        return DirectoryEmploymentFactory::new();
    }
}
