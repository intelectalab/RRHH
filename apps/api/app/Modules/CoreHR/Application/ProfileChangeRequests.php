<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Domain\ProfileChangeRequestStateMachine;
use App\Modules\CoreHR\Infrastructure\Models\Person;
use App\Modules\CoreHR\Infrastructure\Models\ProfileChangeRequest;
use App\Shared\Audit\AuditLogger;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Security\FieldCipher;
use App\Shared\Settings\ParameterResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * COL-4 · RN-COL-06 · El colaborador propone cambios de sus propios datos personales.
 *
 * - Los campos de la lista colaboradores.campos_requieren_aprobacion esperan a RRHH; los demás (nombre
 *   preferido, correo y celular personales, por defecto) se aplican en el acto.
 * - Con colaboradores.cambios_perfil_requieren_aprobacion = false todo se aplica en el acto.
 * - Los valores propuestos se guardan cifrados; la auditoría registra qué campos cambiaron.
 */
final class ProfileChangeRequests
{
    /** Datos que el colaborador puede proponer cambiar (address = dirección de domicilio). */
    public const FIELDS = ['first_names', 'paternal_surname', 'maternal_surname', 'preferred_name', 'birth_date', 'sex',
        'marital_status', 'nationality_code', 'personal_email', 'mobile_phone', 'address'];

    public function __construct(
        private readonly ProfileChangeRequestStateMachine $machine,
        private readonly PersonService $people,
        private readonly PersonalData $personal,
        private readonly ParameterResolver $parameters,
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Campos que esperan aprobación según la organización.
     *
     * @return array<int, string>
     */
    public function fieldsRequiringApproval(): array
    {
        if ($this->parameters->value('colaboradores.cambios_perfil_requieren_aprobacion') === false) {
            return [];
        }
        $list = $this->parameters->value('colaboradores.campos_requieren_aprobacion');

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : self::FIELDS;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function create(Person $person, array $changes, string $userId): ProfileChangeRequest
    {
        $changes = array_intersect_key($changes, array_flip(self::FIELDS));
        if ($changes === []) {
            throw ValidationException::withMessages(['changes' => [(string) trans('people.change_request_empty')]]);
        }
        $needsApproval = $this->fieldsRequiringApproval();
        $pending = array_values(array_intersect(array_keys($changes), $needsApproval));
        $applied = array_values(array_diff(array_keys($changes), $pending));

        return DB::transaction(function () use ($person, $changes, $pending, $applied, $userId): ProfileChangeRequest {
            if ($pending !== [] && ProfileChangeRequest::query()->where('person_id', $person->id)->where('status', 'pending')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['changes' => [(string) trans('people.change_request_open')]]);
            }
            $this->apply($person, array_intersect_key($changes, array_flip($applied)));

            $request = ProfileChangeRequest::create([
                'person_id' => $person->id,
                'requested_by_user_id' => $userId,
                'changes' => ['enc' => FieldCipher::encrypt((string) json_encode($changes, JSON_THROW_ON_ERROR)), 'applied' => $applied, 'pending' => $pending],
                'status' => $pending === [] ? 'approved' : 'pending',
                'reviewed_at' => $pending === [] ? now() : null,
            ]);
            if ($pending !== []) {
                $this->outbox->record(DomainEventTypes::PERSON_DATA_CHANGE_REQUESTED, 'person', $person->id, [
                    'request_id' => $request->id, 'person_id' => $person->id, 'fields' => $pending,
                ]);
            }
            $this->audit->record('person.change_requested', 'person', $person->id, null, ['request_id' => $request->id, 'applied' => $applied, 'pending' => $pending]);

            return $request->refresh();
        });
    }

    public function approve(ProfileChangeRequest $request, string $userId): ProfileChangeRequest
    {
        $this->machine->getNextState($request->status, 'approve');

        return DB::transaction(function () use ($request, $userId): ProfileChangeRequest {
            /** @var ProfileChangeRequest $locked */
            $locked = ProfileChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->machine->getNextState($locked->status, 'approve');
            /** @var Person $person */
            $person = Person::query()->findOrFail($locked->person_id);
            $values = $this->values($locked);
            $this->apply($person, array_intersect_key($values, array_flip($this->listOf($locked, 'pending'))));
            $this->close($locked, 'approved', $userId, null);
            $this->outbox->record(DomainEventTypes::PERSON_DATA_CHANGE_APPROVED, 'person', $person->id, [
                'request_id' => $locked->id, 'person_id' => $person->id, 'fields' => $this->listOf($locked, 'pending'),
            ]);
            $this->audit->record('person.change_approved', 'person', $person->id, null, ['request_id' => $locked->id, 'fields' => $this->listOf($locked, 'pending')]);

            return $locked->refresh();
        });
    }

    public function reject(ProfileChangeRequest $request, ?string $reason, string $userId): ProfileChangeRequest
    {
        $this->machine->getNextState($request->status, 'reject');
        if ($reason === null || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => [(string) trans('people.reason_required')]]);
        }

        return DB::transaction(function () use ($request, $reason, $userId): ProfileChangeRequest {
            $this->close($request, 'rejected', $userId, trim($reason));
            $this->audit->record('person.change_rejected', 'person', $request->person_id, null, ['request_id' => $request->id], trim($reason));

            return $request->refresh();
        });
    }

    public function cancel(ProfileChangeRequest $request, string $userId): ProfileChangeRequest
    {
        $this->machine->getNextState($request->status, 'cancel');

        return DB::transaction(function () use ($request, $userId): ProfileChangeRequest {
            $this->close($request, 'cancelled', $userId, null);
            $this->audit->record('person.change_cancelled', 'person', $request->person_id, null, ['request_id' => $request->id]);

            return $request->refresh();
        });
    }

    /**
     * @param  array<int, ProfileChangeRequest>  $requests
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(array $requests): array
    {
        if ($requests === []) {
            return [];
        }
        $people = DB::table('people')->whereIn('id', array_map(fn ($r) => $r->person_id, $requests))->get()->keyBy('id');
        $users = DB::table('users')->whereIn('id', array_filter(array_map(fn ($r) => $r->requested_by_user_id, $requests)))->pluck('display_name', 'id');

        return array_map(function (ProfileChangeRequest $r) use ($people, $users): array {
            $p = $people->get($r->person_id);

            return [
                'id' => $r->id,
                'person_id' => $r->person_id,
                'person_name' => $p === null ? null : EmploymentPresenter::name($p),
                'changes' => $this->values($r),
                'applied_fields' => $this->listOf($r, 'applied'),
                'pending_fields' => $this->listOf($r, 'pending'),
                'status' => $r->status,
                'requested_by_user_id' => $r->requested_by_user_id,
                'requested_by_name' => $users[$r->requested_by_user_id] ?? null,
                'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                'rejection_reason' => $r->rejection_reason,
                'allowed_actions' => $this->machine->allowedActions($r->status),
                'created_at' => $r->created_at->toIso8601String(),
                'updated_at' => $r->updated_at->toIso8601String(),
            ];
        }, $requests);
    }

    /**
     * Aplica los cambios a la persona (la dirección como dirección de domicilio nueva).
     *
     * @param  array<string, mixed>  $changes
     */
    private function apply(Person $person, array $changes): void
    {
        $address = $changes['address'] ?? null;
        unset($changes['address']);
        if ($changes !== []) {
            $this->people->update($person, $changes, null);
        }
        if (is_string($address) && trim($address) !== '') {
            $this->personal->addAddress($person, ['kind' => 'home', 'address' => $address]);
        }
    }

    private function close(ProfileChangeRequest $request, string $status, string $userId, ?string $reason): void
    {
        $request->forceFill([
            'status' => $status, 'reviewed_by_user_id' => $userId, 'reviewed_at' => now(), 'rejection_reason' => $reason,
            'lock_version' => $request->lock_version + 1,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function values(ProfileChangeRequest $request): array
    {
        $enc = $request->changes['enc'] ?? null;
        if (! is_string($enc)) {
            return [];
        }
        $decoded = json_decode(FieldCipher::decrypt($enc), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, string>
     */
    private function listOf(ProfileChangeRequest $request, string $key): array
    {
        $list = $request->changes[$key] ?? [];

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
