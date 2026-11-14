<?php

namespace App\Modules\CoreHR\Infrastructure\SelfService;

use App\Modules\CoreHR\Application\EmploymentProfile;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\SelfService\HomeCard;
use Illuminate\Support\Facades\DB;

/**
 * ESS-1 · Tarjeta «Mi perfil» del inicio: puesto, unidad, jefe, antigüedad calculada por el servidor y
 * cambios de datos que esperan a RRHH (ESS-2).
 */
final class ProfileHomeCard implements HomeCard
{
    public function __construct(private readonly EmploymentProfile $profile) {}

    public function key(): string
    {
        return 'profile';
    }

    public function permission(): string
    {
        return 'people.employments.view';
    }

    public function order(): int
    {
        return 0;
    }

    public function card(string $userId, string $employmentId): ?array
    {
        $employment = Employment::query()->find($employmentId);
        if (! $employment instanceof Employment) {
            return null;
        }
        $header = $this->profile->header($employment, null, $employment->person_id);
        $pending = DB::table('profile_change_requests')->where('person_id', $employment->person_id)->where('status', 'pending')->count();

        return [
            'href' => '/mi/perfil',
            'pending' => $pending,
            'data' => [
                'person_name' => $header['person_name'],
                'first_names' => $header['first_names'],
                'job_profile_name' => $header['job_profile_name'],
                'position_title' => $header['position_title'],
                'org_unit_name' => $header['org_unit_name'],
                'manager_name' => $header['manager_name'],
                'legal_entity_name' => $header['legal_entity_name'],
                'seniority' => $header['seniority'],
                'pending_changes' => $pending,
            ],
        ];
    }
}
