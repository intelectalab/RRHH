<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Modules\CoreHR\Database\Factories\DependentFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * COL-6 · Derechohabiente (E-19): pertenece a la persona, no a la relación laboral. Es dato personal de
 * un tercero: el documento va cifrado (number_enc) con su huella (number_hash) y la discapacidad cifrada.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $person_id
 * @property string $full_name
 * @property string $relationship
 * @property string|null $doc_type
 * @property string|null $number_enc
 * @property string|null $number_hash
 * @property CarbonInterface|null $birth_date
 * @property bool $is_student
 * @property string|null $disability_enc
 * @property CarbonInterface|null $registered_on
 * @property CarbonInterface|null $deregistered_on
 * @property string|null $deregistration_reason
 * @property string $registry_status
 * @property bool $entitles_family_allowance
 * @property string|null $proof_file_id
 * @property CarbonInterface|null $majority_alerted_on
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class Dependent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<DependentFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'dependents';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $hidden = ['number_enc', 'number_hash', 'disability_enc'];

    protected $casts = [
        'birth_date' => 'date', 'registered_on' => 'date', 'deregistered_on' => 'date', 'majority_alerted_on' => 'date',
        'is_student' => 'boolean', 'entitles_family_allowance' => 'boolean', 'lock_version' => 'integer',
    ];

    protected static function newFactory(): DependentFactory
    {
        return DependentFactory::new();
    }
}
