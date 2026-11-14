<?php

namespace App\Modules\Absences\Application;

use App\Modules\Absences\Infrastructure\Models\AbsenceMedicalCertificate;
use App\Modules\Absences\Infrastructure\Models\AbsenceRequest;
use App\Modules\Absences\Infrastructure\Models\AbsenceType;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Security\FieldCipher;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * AUS-3 · Certificado médico de un descanso (CITT de la seguridad social o certificado particular).
 *
 * Solo para ausencias cuyo tipo genera subsidio (incapacidad o maternidad). Número y diagnóstico se guardan
 * cifrados (FieldCipher) y la auditoría registra que cambiaron, nunca su valor. La lectura pasa por
 * SensitiveFields (esquema AbsenceMedicalCertificate) y queda en data_access_logs.
 */
final class MedicalCertificates
{
    public const KINDS = ['citt', 'private'];

    public function __construct(
        private readonly MedicalLeaveSplit $split,
        private readonly AuditLogger $audit,
    ) {}

    public function find(AbsenceRequest $absence): ?AbsenceMedicalCertificate
    {
        return AbsenceMedicalCertificate::query()->where('absence_request_id', $absence->id)->first();
    }

    /**
     * Registra o corrige el certificado de la ausencia.
     *
     * @param  array{kind?: string|null, certificate_number?: string|null, issuer?: string|null, diagnosis?: string|null, issued_on?: string|null, days_granted?: int|null, is_work_related?: bool|null, validated?: bool|null}  $input
     */
    public function save(AbsenceRequest $absence, array $input, string $userId): AbsenceMedicalCertificate
    {
        $this->assertMedical($absence);

        return DB::transaction(function () use ($absence, $input, $userId): AbsenceMedicalCertificate {
            $cert = AbsenceMedicalCertificate::query()->where('absence_request_id', $absence->id)->lockForUpdate()->first()
                ?? new AbsenceMedicalCertificate(['absence_request_id' => $absence->id]);
            $before = $cert->exists ? $this->auditable($cert) : null;
            foreach (['kind', 'issuer', 'issued_on', 'days_granted', 'is_work_related'] as $field) {
                if (array_key_exists($field, $input)) {
                    $cert->setAttribute($field, $field === 'is_work_related' ? (bool) $input[$field] : $input[$field]);
                }
            }
            foreach (['certificate_number', 'diagnosis'] as $field) {
                if (array_key_exists($field, $input)) {
                    $value = is_string($input[$field]) && trim($input[$field]) !== '' ? trim($input[$field]) : null;
                    $cert->setAttribute($field.'_enc', $value === null ? null : FieldCipher::encrypt($value));
                }
            }
            if (array_key_exists('validated', $input)) {
                $cert->forceFill($input['validated'] === true
                    ? ['validated_by_user_id' => $userId, 'validated_at' => now()]
                    : ['validated_by_user_id' => null, 'validated_at' => null]);
            }
            $cert->save();
            $this->audit->record($before === null ? 'absence.medical_certificate_created' : 'absence.medical_certificate_updated', AbsenceRequest::SUBJECT, $absence->id, $before, $this->auditable($cert));

            return $cert->refresh();
        });
    }

    /**
     * Certificado listo para la respuesta (descifrado; el controlador quita lo sensible sin permiso).
     *
     * @return array<string, mixed>
     */
    public function present(AbsenceRequest $absence, ?AbsenceMedicalCertificate $cert): array
    {
        $calendarDays = $absence->calendar_days === null ? null : (int) BigDecimal::of((string) $absence->calendar_days)->toScale(0, RoundingMode::FLOOR)->toInt();
        $granted = $cert?->days_granted;

        return [
            'id' => $cert?->id,
            'absence_request_id' => $absence->id,
            'kind' => $cert->kind ?? null,
            'certificate_number' => $cert?->certificate_number_enc === null ? null : FieldCipher::decrypt($cert->certificate_number_enc),
            'issuer' => $cert?->issuer,
            'diagnosis' => $cert?->diagnosis_enc === null ? null : FieldCipher::decrypt($cert->diagnosis_enc),
            'issued_on' => $cert?->issued_on === null ? null : substr((string) $cert->issued_on, 0, 10),
            'days_granted' => $granted,
            'absence_calendar_days' => $calendarDays,
            'days_mismatch' => $granted !== null && $calendarDays !== null && $granted !== $calendarDays,
            'is_work_related' => $cert->is_work_related ?? false,
            'validated_at' => $cert?->validated_at?->toIso8601String(),
            'validated_by_user_id' => $cert?->validated_by_user_id,
            'updated_at' => $cert?->updated_at->toIso8601String(),
        ];
    }

    private function assertMedical(AbsenceRequest $absence): void
    {
        $type = AbsenceType::query()->withTrashed()->find($absence->absence_type_id);
        if (! $type instanceof AbsenceType || $this->split->kind($type, $absence->day('starts_on')) === null) {
            throw new AyniException('not_medical_absence', 422, (string) trans('absences.certificates.not_medical'));
        }
        if (in_array($absence->status, ['rejected', 'cancelled'], true)) {
            throw new AyniException('invalid_transition', 409, (string) trans('absences.certificates.closed'), [], ['current_status' => $absence->status, 'allowed_actions' => []]);
        }
    }

    /**
     * Sin número ni diagnóstico en claro: solo si están cargados.
     *
     * @return array<string, mixed>
     */
    private function auditable(AbsenceMedicalCertificate $c): array
    {
        return [
            'kind' => $c->kind, 'issuer' => $c->issuer, 'issued_on' => $c->issued_on === null ? null : substr((string) $c->issued_on, 0, 10),
            'days_granted' => $c->days_granted, 'is_work_related' => $c->is_work_related,
            'has_certificate_number' => $c->certificate_number_enc !== null, 'has_diagnosis' => $c->diagnosis_enc !== null,
            'validated_at' => $c->validated_at?->toIso8601String(),
        ];
    }
}
