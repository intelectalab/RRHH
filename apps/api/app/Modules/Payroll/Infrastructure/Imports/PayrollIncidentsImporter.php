<?php

namespace App\Modules\Payroll\Infrastructure\Imports;

use App\Modules\Payroll\Application\PayrollIncidents;
use App\Modules\Payroll\Application\PayrollPeriods;
use App\Modules\Payroll\Infrastructure\Models\PayrollGroup;
use App\Modules\Payroll\Infrastructure\Models\PayrollIncident;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use App\Shared\Imports\ImportColumn;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportValues;
use App\Shared\Imports\RowCheck;

/**
 * CPT-6 · Valores por trabajador del periodo (montos fijos y variables) importados como incidencias.
 *
 * Usa las mismas reglas que el alta manual de PER-2 (PayrollIncidents::check): periodo que todavía recibe
 * cambios, trabajador de la población, concepto activo y valores numéricos. Cada incidencia queda con origen
 * «import» y el lote que la creó (source_type = import_job, source_id), así se distingue de lo cargado a mano.
 * Deshacer la pasa a la papelera mientras el periodo siga abierto.
 */
final class PayrollIncidentsImporter implements Importer
{
    public function __construct(private readonly PayrollIncidents $incidents, private readonly PayrollPeriods $periods) {}

    public function entity(): string
    {
        return 'payroll_incidents';
    }

    public function permission(): string
    {
        return 'payroll.incidents.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('payroll_group', true, 'MENSUAL-01', ['grupo', 'grupo_planilla']),
            new ImportColumn('period', true, '202611', ['periodo', 'periodo_planilla']),
            new ImportColumn('document_type', false, 'dni', ['tipo_documento']),
            new ImportColumn('document_number', true, '45678912', ['documento', 'dni']),
            new ImportColumn('concept_code', true, 'comisiones', ['concepto', 'codigo_concepto']),
            new ImportColumn('quantity', false, '8', ['cantidad', 'horas', 'dias']),
            new ImportColumn('rate', false, '', ['tasa']),
            new ImportColumn('amount', false, '350.00', ['monto', 'importe']),
            new ImportColumn('notes', false, '', ['nota', 'observacion']),
        ];
    }

    public function naturalKey(array $data): ?string
    {
        $doc = trim($data['document_number'] ?? '');

        return $doc === '' ? null : implode('|', [trim($data['payroll_group'] ?? ''), trim($data['period'] ?? ''), $doc, trim($data['concept_code'] ?? '')]);
    }

    public function validate(array $data, ImportContext $context): RowCheck
    {
        $check = new RowCheck([]);
        $period = $this->period(trim($data['payroll_group'] ?? ''), trim($data['period'] ?? ''), $context);
        if ($period === null) {
            return $check->error('period', 'not_found', (string) trans('payroll.import.period_missing', [], $context->locale));
        }
        $entity = (string) PayrollGroup::query()->whereKey($period->payroll_group_id)->value('legal_entity_id');
        $employmentId = $context->lookups()->employment(($data['document_type'] ?? '') ?: 'dni', $data['document_number'] ?? '', $entity);
        if ($employmentId === null) {
            return $check->error('document_number', 'not_found', (string) trans('imports.employment_missing', [], $context->locale));
        }
        $input = ['employment_id' => $employmentId, 'concept_code' => trim($data['concept_code'] ?? ''), 'notes' => $data['notes'] ?? null];
        foreach (['quantity', 'rate', 'amount'] as $field) {
            $raw = trim($data[$field] ?? '');
            if ($raw === '') {
                continue;
            }
            $value = ImportValues::decimal($raw);
            if ($value === null) {
                $check->error($field, 'invalid', (string) trans('imports.invalid_number', [], $context->locale));

                continue;
            }
            $input[$field] = $value;
        }
        if (! $check->ok()) {
            return $check;
        }
        $key = 'payroll_population.'.$period->id;
        if (! isset($context->memo[$key])) {
            $context->memo[$key] = $this->periods->population($period);
        }
        /** @var list<string> $population */
        $population = $context->memo[$key];
        $row = $this->incidents->check($period, $input, $population);
        if (isset($row['error'])) {
            $field = match ($row['error']['field']) {
                'employment_id' => 'document_number', 'payroll_concept_id' => 'concept_code', default => $row['error']['field'],
            };

            return $check->error($field, $row['error']['rule'], $row['error']['message']);
        }
        $check->parsed = ['payroll_period_id' => $period->id, 'input' => $input];

        return $check;
    }

    public function import(array $parsed, ImportContext $context): array
    {
        $period = PayrollPeriod::query()->findOrFail((string) $parsed['payroll_period_id']);
        /** @var array<string, mixed> $input */
        $input = $parsed['input'];
        $incident = $this->incidents->create($period, $input, $context->userId, 'import', ['source_type' => 'import_job', 'source_id' => $context->jobId]);

        return ['entity_id' => (string) $incident->id, 'previous' => ['__created' => true]];
    }

    public function blockingReason(string $entityId, ImportContext $context): ?string
    {
        $incident = PayrollIncident::query()->find($entityId);

        return $incident === null ? null : $this->incidents->lockReason($incident);
    }

    public function revert(string $entityId, ?array $previous, ImportContext $context): void
    {
        $incident = PayrollIncident::query()->find($entityId);
        if ($incident !== null) {
            $this->incidents->delete($incident, $context->userId);
        }
    }

    /** Periodo del grupo por su código PDT (AAAAMM) o por «AAAA-NN»; el regular que todavía recibe cambios primero. */
    private function period(string $groupCode, string $value, ImportContext $context): ?PayrollPeriod
    {
        $key = 'payroll_period.'.$groupCode.'|'.$value;
        if (array_key_exists($key, $context->memo)) {
            $id = $context->memo[$key];

            return is_string($id) ? PayrollPeriod::query()->find($id) : null;
        }
        $groupId = $groupCode === '' ? null : PayrollGroup::query()->where('code', $groupCode)->value('id');
        $q = PayrollPeriod::query()->where('payroll_group_id', $groupId);
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $value, $m) === 1) {
            $q->where('period_year', (int) $m[1])->where('period_number', (int) $m[2]);
        } else {
            $q->where('statutory_period_code', preg_replace('/\D/', '', $value));
        }
        $candidates = $q->orderByRaw("CASE kind WHEN 'regular' THEN 0 ELSE 1 END")->get();
        $period = $candidates->first(fn (PayrollPeriod $p) => $this->periods->isEditable($p)) ?? $candidates->first();
        $context->memo[$key] = $groupId === null ? null : $period?->id;

        return $groupId === null ? null : $period;
    }
}
