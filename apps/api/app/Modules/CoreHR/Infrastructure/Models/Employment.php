<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Modules\CoreHR\Database\Factories\EmploymentFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * COL-2 · Relación laboral (C-01) entre una persona y una empresa. Todo lo laboral cuelga de aquí; lo que
 * cambia con el tiempo (asignación, compensación, régimen, contrato) vive en sus tablas con vigencia.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $person_id
 * @property string $legal_entity_id
 * @property string $employee_code
 * @property string $kind
 * @property string $worker_category
 * @property CarbonInterface $hire_date
 * @property CarbonInterface|null $seniority_date
 * @property CarbonInterface|null $termination_date
 * @property CarbonInterface|null $probation_ends_on
 * @property string $status
 * @property string|null $rehire_of_employment_id
 * @property string|null $work_email
 * @property string|null $work_phone
 * @property string|null $work_phone_ext
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Person|null $person
 */
class Employment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EmploymentFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'employments';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'lock_version' => 'integer', 'hire_date' => 'date', 'seniority_date' => 'date',
        'termination_date' => 'date', 'probation_ends_on' => 'date',
    ];

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    protected static function newFactory(): EmploymentFactory
    {
        return EmploymentFactory::new();
    }
}
