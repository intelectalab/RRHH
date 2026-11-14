<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\EmploymentProfile;
use App\Modules\CoreHR\Application\PersonalData;
use App\Modules\CoreHR\Application\PersonService;
use App\Modules\CoreHR\Application\ProfileChangeRequests;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\CoreHR\Infrastructure\Models\Person;
use App\Modules\CoreHR\Infrastructure\Models\ProfileChangeRequest;
use App\Shared\Exceptions\ModuleNotEnabledException;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Permissions\SensitiveFields;
use App\Shared\SelfService\OwnEmployments;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ESS-2 · GET /me/employment · Mi perfil: la ficha propia del colaborador.
 *
 * - RN-ESS-01: solo la relación laboral propia (?employment_id de otra persona responde 404).
 * - Sus propios datos personales los ve siempre (son suyos), pero cada lectura queda en el registro de
 *   accesos igual que cuando los ve RRHH (regla 7). Los datos de salud no viajan aquí.
 * - Indica qué datos puede proponer cambiar (colaboradores.campos_editables_portal), cuáles esperan a RRHH
 *   (colaboradores.campos_requieren_aprobacion) y cuáles tienen hoy un cambio pendiente (se muestran como tal).
 */
final class MeEmploymentController
{
    /** Solicitudes de cambio recientes que se listan en el perfil. */
    private const RECENT_REQUESTS = 10;

    /** Lectura de datos personales propios: el titular siempre los ve (no da acceso a los de nadie más). */
    private const SELF_FIELDS = ['people.people.personal_data.view'];

    public function __invoke(
        Request $request,
        OwnEmployments $own,
        EmploymentProfile $profile,
        PersonService $people,
        PersonalData $personal,
        ProfileChangeRequests $changes,
        SensitiveFields $sensitive,
        PermissionResolver $resolver,
        ModuleGate $modules,
    ): JsonResponse {
        if (! $modules->isEnabled('core')) {
            throw new ModuleNotEnabledException('core');
        }
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);
        $userId = (string) $user->getAuthIdentifier();

        $employments = $own->list($userId);
        $picked = $own->pick($userId, $request->query('employment_id'));
        if ($picked === null) {
            return response()->json(['data' => ['employments' => [], 'employment' => null]]);
        }
        /** @var Employment $employment */
        $employment = Employment::query()->findOrFail($picked['id']);
        /** @var Person $person */
        $person = Person::query()->findOrFail($employment->person_id);

        // El titular lee sus datos personales; sus otros permisos (p. ej. de RRHH) se conservan.
        $mine = $resolver->for($userId);
        $self = new EffectivePermissions($mine->actions, $mine->fields + array_fill_keys(self::SELF_FIELDS, 'own'), $mine->systemRoles);

        $requests = ProfileChangeRequest::query()->where('person_id', $person->id)->orderByDesc('created_at')->limit(self::RECENT_REQUESTS)->get()->all();
        $presented = $changes->presentMany($requests);
        $pendingFields = [];
        foreach ($presented as $r) {
            if (($r['status'] ?? null) === 'pending') {
                $pendingFields = array_merge($pendingFields, (array) ($r['pending_fields'] ?? []));
            }
        }

        $addressRows = $personal->addresses($person);
        $addresses = $sensitive->presentMany('PersonAddress', $addressRows, $self, 'api', array_fill(0, count($addressRows), $person->id));
        $contacts = $personal->contacts($person);

        return response()->json(['data' => [
            'employments' => $employments,
            'employment' => $picked,
            'header' => $profile->header($employment, array_column($employments, 'id'), $person->id),
            'work_data' => $profile->workData($employment),
            'person' => $sensitive->present('Person', $people->present($person), $self, 'api', $person->id),
            'addresses' => $addresses,
            'emergency_contacts' => $sensitive->presentMany('EmergencyContact', $contacts, $self, 'api', array_fill(0, count($contacts), $person->id)),
            'change_requests' => $presented,
            'editable_fields' => $changes->editableFields(),
            'approval_fields' => $changes->fieldsRequiringApproval(),
            'pending_fields' => array_values(array_unique($pendingFields)),
            'can_propose' => $mine->has('people.change_requests.create') && ! $picked['read_only'],
        ]]);
    }
}
