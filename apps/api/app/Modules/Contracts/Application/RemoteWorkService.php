<?php

namespace App\Modules\Contracts\Application;

use App\Modules\Contracts\Domain\RemoteWorkStateMachine;
use App\Modules\Contracts\Infrastructure\Models\RemoteWorkAgreement;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\VersionConflictException;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Notifications\Notifier;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Time\WorkingDays;
use App\Shared\Vigencia\EffectiveDated;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CON-6 · Teletrabajo (Ley 31572).
 *
 * - Una solicitud de cambio de modalidad nace con su plazo de respuesta: requested_on más el parámetro legal
 *   teletrabajo_respuesta_dias_habiles en días hábiles de la empresa (descansos y feriados de la sede). Antes de
 *   vencer (teletrabajo.aviso_respuesta_dias) se avisa una vez a quienes deciden.
 * - El acuerdo se emite solo con su contenido mínimo completo (RN-CON-10): modalidad, jornada, desconexión digital
 *   (no menor al parámetro legal teletrabajo_desconexion_horas), lugar, supervisión, equipos y compensación de gastos.
 *   Emitir genera el documento en el legajo; registrar el ejemplar firmado lo deja vigente (la firma electrónica
 *   espera la decisión D-10). Ningún número legal está en el código: los plazos vienen de parámetros con vigencia.
 */
final class RemoteWorkService
{
    /** RN-CON-10 · Contenido mínimo del acuerdo. */
    public const MINIMUM_CONTENT = ['modality', 'schedule_description', 'disconnection_hours', 'work_place', 'supervision_mechanism', 'equipment_provided_by', 'expense_compensation'];

    /** Campos que se completan mientras el acuerdo no está firmado. */
    private const EDITABLE = ['modality', 'starts_on', 'ends_on', 'work_place', 'schedule_description', 'disconnection_hours', 'supervision_mechanism', 'equipment_provided_by', 'request_reason'];

    public function __construct(
        private readonly RemoteWorkStateMachine $machine,
        private readonly ContractDocuments $documents,
        private readonly LegalParameters $legal,
        private readonly WorkingDays $workingDays,
        private readonly ParameterResolver $parameters,
        private readonly Notifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, ?string $userId): RemoteWorkAgreement
    {
        $employmentId = (string) $input['employment_id'];
        $open = RemoteWorkAgreement::query()->where('employment_id', $employmentId)->whereIn('status', ['requested', 'approved', 'pending_signature'])->exists();
        if ($open) {
            throw new AyniException('remote_work_in_progress', 409, (string) trans('contracts.remote_work.active_exists'));
        }
        $requestedOn = EffectiveDated::day($input['requested_on'] ?? now());
        $values = $this->columns($input) + [
            'employment_id' => $employmentId, 'status' => 'requested', 'requested_on' => $requestedOn,
            'requested_by' => (string) ($input['requested_by'] ?? 'employee'), 'requested_by_user_id' => $userId,
            'response_due_on' => $this->responseDue($employmentId, $requestedOn),
        ];
        $this->assertDisconnection($values, $employmentId);

        return DB::transaction(function () use ($values): RemoteWorkAgreement {
            $agreement = RemoteWorkAgreement::create($values);
            $agreement->refresh();
            $this->audit->record('remote_work.requested', 'remote_work_agreement', $agreement->id, null, $this->auditable($agreement));

            return $agreement;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(RemoteWorkAgreement $agreement, array $input, ?int $expectedVersion): RemoteWorkAgreement
    {
        if ($expectedVersion !== null && $expectedVersion !== $agreement->lock_version) {
            throw new VersionConflictException($expectedVersion, $agreement->lock_version);
        }
        if (! in_array($agreement->status, ['requested', 'approved', 'pending_signature'], true)) {
            throw new AyniException('remote_work_not_editable', 409, (string) trans('contracts.remote_work.not_editable'));
        }
        $values = $this->columns($input);
        $this->assertDisconnection($values + ['disconnection_hours' => $agreement->disconnection_hours], $agreement->employment_id);
        $before = $this->auditable($agreement);

        return DB::transaction(function () use ($agreement, $values, $before): RemoteWorkAgreement {
            $agreement->fill($values)->save();
            $agreement->refresh();
            $this->audit->record('remote_work.updated', 'remote_work_agreement', $agreement->id, $before, $this->auditable($agreement));

            return $agreement;
        });
    }

    public function decide(RemoteWorkAgreement $agreement, string $action, ?string $reason, ?string $userId): RemoteWorkAgreement
    {
        if ($action === 'reject' && ($reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages(['reason' => [(string) trans('contracts.remote_work.reason_required')]]);
        }

        return $this->move($agreement, $action, [
            'responded_on' => now()->toDateString(), 'decided_by_user_id' => $userId, 'decision_reason' => $reason,
        ], $reason);
    }

    /** RN-CON-10 · Emite (o vuelve a emitir) el acuerdo con su contenido mínimo completo. */
    public function issue(RemoteWorkAgreement $agreement, ?string $userId): RemoteWorkAgreement
    {
        $this->machine->getNextState($agreement->status, 'issue');
        $missing = $this->missingContent($agreement);
        if ($missing !== []) {
            $labels = array_map(fn (string $f): string => (string) trans('contracts.remote_work.field_'.$f), $missing);
            throw ValidationException::withMessages(['missing_content' => [(string) trans('contracts.remote_work.missing_content', ['fields' => implode(', ', $labels)])]]);
        }
        $this->assertDisconnection(['disconnection_hours' => $agreement->disconnection_hours], $agreement->employment_id);
        [$title, $body, $footer] = $this->content($agreement);

        return DB::transaction(function () use ($agreement, $userId, $title, $body, $footer): RemoteWorkAgreement {
            $documentId = $this->documents->generateRemoteWork($agreement, $title, $body, $footer, $userId);

            return $this->move($agreement, 'issue', ['document_id' => $documentId], null);
        });
    }

    public function registerSignature(RemoteWorkAgreement $agreement, string $contents, string $name, string $mime, string $origin, string $signedOn, ?string $userId): RemoteWorkAgreement
    {
        $this->machine->getNextState($agreement->status, 'register-signature');

        return DB::transaction(function () use ($agreement, $contents, $name, $mime, $origin, $signedOn, $userId): RemoteWorkAgreement {
            $documentId = $this->documents->attachSigned($agreement->document_id, $agreement->employment_id, 'remote_work_agreement', $agreement->id,
                (string) trans('contracts.remote_work.signed_version'), $contents, $name, $mime, $userId);

            return $this->move($agreement, 'register-signature', [
                'document_id' => $documentId, 'signed_at' => CarbonImmutable::parse($signedOn)->startOfDay(), 'signature_origin' => $origin,
            ], null);
        });
    }

    public function end(RemoteWorkAgreement $agreement, string $endsOn, string $reason): RemoteWorkAgreement
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => [(string) trans('contracts.remote_work.reason_required')]]);
        }

        return $this->move($agreement, 'end', ['ends_on' => $endsOn, 'end_reason' => $reason], $reason);
    }

    /**
     * Campos del contenido mínimo que faltan.
     *
     * @return list<string>
     */
    public function missingContent(RemoteWorkAgreement $a): array
    {
        $missing = [];
        foreach (self::MINIMUM_CONTENT as $f) {
            $v = $a->getAttribute($f);
            if ($v === null || (is_string($v) && trim($v) === '')) {
                $missing[] = $f;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    public function allowedActions(RemoteWorkAgreement $a): array
    {
        return array_values($this->machine->allowedActions($a->status));
    }

    /** Días que faltan para el plazo de respuesta y si avisar (según teletrabajo.aviso_respuesta_dias). */
    public function dueSoon(RemoteWorkAgreement $a, string $today): bool
    {
        if ($a->status !== 'requested' || $a->response_due_on === null) {
            return false;
        }
        $notice = (int) ($this->parameters->value('teletrabajo.aviso_respuesta_dias', $this->legalEntity($a->employment_id)) ?? 0);

        return $a->response_due_on->toDateString() <= CarbonImmutable::parse($today)->addDays($notice)->toDateString();
    }

    /**
     * Aviso diario: solicitudes sin responder cuyo plazo vence dentro del aviso configurado (una sola vez cada una).
     *
     * @return int solicitudes avisadas
     */
    public function remindDue(string $today): int
    {
        $count = 0;
        $pending = RemoteWorkAgreement::query()->where('status', 'requested')->whereNull('due_reminder_sent_at')->whereNotNull('response_due_on')->get();
        foreach ($pending as $a) {
            if (! $this->dueSoon($a, $today)) {
                continue;
            }
            $person = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.id', $a->employment_id)
                ->first(['p.first_names', 'p.preferred_name', 'p.paternal_surname']);
            $employee = $person === null ? '' : trim(($person->preferred_name ?: $person->first_names).' '.$person->paternal_surname);
            DB::transaction(function () use ($a, $employee, &$count): void {
                $this->notifier->notify('remote_work.response_due', $this->deciders(), [
                    'employee' => $employee, 'due_on' => (string) $a->response_due_on?->format('d/m/Y'),
                ], 'remote_work_agreement', $a->id);
                $a->forceFill(['due_reminder_sent_at' => now()])->save();
                $count++;
            });
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function move(RemoteWorkAgreement $agreement, string $action, array $extra, ?string $reason): RemoteWorkAgreement
    {
        $next = $this->machine->getNextState($agreement->status, $action);

        return DB::transaction(function () use ($agreement, $action, $next, $extra, $reason): RemoteWorkAgreement {
            $locked = RemoteWorkAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            $this->machine->getNextState($locked->status, $action);
            $before = $this->auditable($locked);
            $locked->forceFill(['status' => $next] + $extra)->save();
            $locked->refresh();
            $this->audit->record('remote_work.'.str_replace('-', '_', $action), 'remote_work_agreement', $locked->id, $before, $this->auditable($locked), $reason);

            return $locked;
        });
    }

    /** Fecha límite: el parámetro legal en días hábiles contados desde el día siguiente a la solicitud. */
    private function responseDue(string $employmentId, string $requestedOn): ?string
    {
        $p = $this->legal->at($this->country($employmentId), 'teletrabajo_respuesta_dias_habiles', $requestedOn);
        $days = is_numeric($p['value'] ?? null) ? (int) $p['value'] : null;
        if ($days === null || $days <= 0) {
            return null;
        }
        $entity = $this->legalEntity($employmentId);
        $rest = $entity === null ? [] : $this->workingDays->restDays($entity);
        $site = $this->site($employmentId);
        $day = CarbonImmutable::parse($requestedOn);
        $horizon = $day->addDays($days * 3 + 30)->toDateString();
        $holidays = $this->workingDays->holidays($site, $day->toDateString(), $horizon);
        $counted = 0;
        while ($counted < $days) {
            $day = $day->addDay();
            if (! in_array(WorkingDays::weekday($day), $rest, true) && ! in_array($day->toDateString(), $holidays, true)) {
                $counted++;
            }
        }

        return $day->toDateString();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function assertDisconnection(array $values, string $employmentId): void
    {
        if (! isset($values['disconnection_hours'])) {
            return;
        }
        $p = $this->legal->at($this->country($employmentId), 'teletrabajo_desconexion_horas');
        $min = is_numeric($p['value'] ?? null) ? (int) $p['value'] : null;
        if ($min !== null && (int) $values['disconnection_hours'] < $min) {
            throw ValidationException::withMessages(['disconnection_hours' => [(string) trans('contracts.remote_work.disconnection_below_minimum', ['hours' => $min])]]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function columns(array $input): array
    {
        $values = array_intersect_key($input, array_flip(self::EDITABLE));
        if (array_key_exists('expense_compensation', $input)) {
            $c = $input['expense_compensation'];
            $values['expense_compensation'] = is_array($c) ? (string) $c['amount'] : null;
            $values['currency'] = is_array($c) ? strtoupper((string) $c['currency']) : null;
        }

        return $values;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function content(RemoteWorkAgreement $a): array
    {
        $row = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->join('legal_entities as l', 'l.id', '=', 'e.legal_entity_id')
            ->where('e.id', $a->employment_id)->first(['p.first_names', 'p.paternal_surname', 'p.maternal_surname', 'l.legal_name']);
        $employee = $row === null ? '' : trim($row->first_names.' '.$row->paternal_surname.' '.($row->maternal_surname ?? ''));
        $employer = $row === null ? '' : (string) $row->legal_name;
        $compensation = $a->expense_compensation === null ? '—' : $a->currency.' '.(string) BigDecimal::of($a->expense_compensation)->toScale(2, RoundingMode::HALF_UP);
        $body = (string) trans('contracts.remote_work.body', [
            'employer' => $employer, 'employee' => $employee,
            'modality' => (string) trans('contracts.remote_work.modality_'.$a->modality),
            'starts_on' => $a->starts_on->format('d/m/Y'),
            'ends_on' => $a->ends_on === null ? '' : (string) trans('contracts.remote_work.ends_on_text', ['date' => $a->ends_on->format('d/m/Y')]),
            'work_place' => (string) $a->work_place, 'schedule' => (string) $a->schedule_description,
            'disconnection' => (string) $a->disconnection_hours, 'supervision' => (string) $a->supervision_mechanism,
            'equipment' => (string) trans('contracts.remote_work.equipment_'.$a->equipment_provided_by), 'compensation' => $compensation,
        ]);

        return [(string) trans('contracts.remote_work.title'), $body, $employer];
    }

    /**
     * @return array<int, string>
     */
    private function deciders(): array
    {
        return array_values(DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->whereNull('r.deleted_at')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')->where('rp.permission_code', 'contracts.remote_work.decide')
            ->join('users as u', 'u.id', '=', 'ur.user_id')->where('u.status', 'active')->whereNull('u.deleted_at')
            ->distinct()->pluck('ur.user_id')->map(fn ($id): string => (string) $id)->all());
    }

    private function legalEntity(string $employmentId): ?string
    {
        $v = DB::table('employments')->where('id', $employmentId)->value('legal_entity_id');

        return $v === null ? null : (string) $v;
    }

    private function site(string $employmentId): ?string
    {
        $today = now()->toDateString();
        $v = DB::table('employment_assignments')->where('employment_id', $employmentId)->where('valid_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))->value('site_id');

        return $v === null ? null : (string) $v;
    }

    private function country(string $employmentId): string
    {
        $entity = $this->legalEntity($employmentId);
        $v = $entity === null ? null : DB::table('legal_entities')->where('id', $entity)->value('country_code');

        return $v === null ? 'PE' : (string) $v;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(RemoteWorkAgreement $a): array
    {
        return [
            'status' => $a->status, 'modality' => $a->modality, 'starts_on' => $a->starts_on->toDateString(), 'ends_on' => $a->ends_on?->toDateString(),
            'work_place' => $a->work_place, 'schedule_description' => $a->schedule_description, 'disconnection_hours' => $a->disconnection_hours,
            'supervision_mechanism' => $a->supervision_mechanism, 'equipment_provided_by' => $a->equipment_provided_by,
            'expense_compensation' => $a->expense_compensation, 'currency' => $a->currency, 'response_due_on' => $a->response_due_on?->toDateString(),
        ];
    }
}
