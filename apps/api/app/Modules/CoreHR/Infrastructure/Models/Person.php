<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Modules\CoreHR\Database\Factories\PersonFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * COL-1 · Persona natural (C-01): existe una sola vez por organización aunque tenga varias relaciones
 * laborales, en una o varias empresas del grupo. No es el usuario ni la relación laboral.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $first_names
 * @property string $paternal_surname
 * @property string|null $maternal_surname
 * @property string|null $preferred_name
 * @property CarbonInterface|null $birth_date
 * @property string|null $sex
 * @property string|null $nationality_code
 * @property string|null $marital_status
 * @property string|null $personal_email
 * @property string|null $mobile_phone
 * @property string|null $photo_file_id
 * @property CarbonInterface|null $anonymized_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class Person extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'people';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['lock_version' => 'integer', 'birth_date' => 'date', 'anonymized_at' => 'datetime'];

    /** @return HasMany<PersonDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(PersonDocument::class, 'person_id');
    }

    /** @return HasMany<Employment, $this> */
    public function employments(): HasMany
    {
        return $this->hasMany(Employment::class, 'person_id');
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([$this->preferred_name ?: $this->first_names, $this->paternal_surname, $this->maternal_surname])));
    }

    protected static function newFactory(): PersonFactory
    {
        return PersonFactory::new();
    }
}
