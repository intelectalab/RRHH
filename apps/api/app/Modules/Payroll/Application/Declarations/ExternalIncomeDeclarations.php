<?php

namespace App\Modules\Payroll\Application\Declarations;

use App\Modules\Payroll\Domain\ExternalIncomeDeclarationStateMachine;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * DEC-9 · Declaración jurada de rentas de otros empleadores (PER-5).
 *
 * - El trabajador la presenta desde el portal; RRHH también puede registrarla. Nace «recibida» y solo la proyección
 *   de quinta usa las validadas.
 * - Validar una declaración reemplaza (supersede) a la validada anterior de la misma relación, ejercicio, tipo y
 *   empleador: la anterior queda como historia con superseded_by_id, no se sobrescribe.
 * - Aplica desde el mes en que se valida (applies_from_month): los meses ya calculados no se rectifican.
 * - Sin declaración validada del ejercicio, el motor proyecta solo con lo pagado por esta empresa y la consulta lo
 *   avisa (meta.warning = no_external_income_declaration).
 */
final class ExternalIncomeDeclarations
{
    public function __construct(
        private readonly ExternalIncomeDeclarationStateMachine $machine,
        private readonly AuditLogger $audit,
        private readonly OutboxService $outbox,
    ) {}

    /**
     * @param  list<string>|null  $employments  relaciones visibles (null = todas)
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(?array $employments, array $filters): array
    {
        $q = DB::table('external_income_declarations')->orderByDesc('fiscal_year')->orderByDesc('created_at');
        if ($employments !== null) {
            $q->whereIn('employment_id', $employments);
        }
        foreach (['employment_id', 'fiscal_year', 'status', 'kind'] as $k) {
            if (($filters[$k] ?? null) !== null) {
                $q->where($k, $filters[$k]);
            }
        }

        return array_values($q->limit(200)->get()->map(fn ($r): array => $this->present($r))->all());
    }

    /** Aviso de la ficha: no hay declaración validada del ejercicio para la relación. */
    public function warning(string $employmentId, int $year): ?string
    {
        return DB::table('external_income_declarations')->where('employment_id', $employmentId)->where('fiscal_year', $year)
            ->where('status', 'validated')->exists() ? null : 'no_external_income_declaration';
    }

    public function employmentOf(string $id): ?string
    {
        $e = preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? DB::table('external_income_declarations')->where('id', $id)->value('employment_id') : null;

        return $e === null ? null : (string) $e;
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        return $this->present($this->row($id));
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function create(array $in, ?string $userId): array
    {
        return DB::transaction(function () use ($in, $userId): array {
            $today = CarbonImmutable::today()->toDateString();
            $id = (string) DB::table('external_income_declarations')->insertGetId([
                'tenant_id' => TenancyScope::requireTenant(), 'employment_id' => $in['employment_id'], 'fiscal_year' => (int) $in['fiscal_year'],
                'kind' => $in['kind'] ?? 'previous_employer', 'employer_tax_id' => $in['employer_tax_id'] ?? null, 'employer_name' => $in['employer_name'] ?? null,
                'period_from' => $in['period_from'] ?? null, 'period_to' => $in['period_to'] ?? null,
                'gross_income' => $in['gross_income'] ?? '0', 'exempt_income' => $in['exempt_income'] ?? null, 'tax_withheld' => $in['tax_withheld'] ?? '0',
                'currency' => $in['currency'] ?? 'PEN', 'monthly_detail' => isset($in['monthly_detail']) ? json_encode($in['monthly_detail']) : null,
                'this_employer_withholds' => $in['this_employer_withholds'] ?? null, 'document_id' => $in['document_id'] ?? null,
                'declared_on' => $today, 'received_on' => $today, 'status' => 'received', 'submitted_by_user_id' => $userId,
            ]);
            $this->audit->record('external_income_declaration.received', 'external_income_declaration', $id, null,
                ['employment_id' => $in['employment_id'], 'fiscal_year' => (int) $in['fiscal_year'], 'kind' => $in['kind'] ?? 'previous_employer']);

            return $this->show($id);
        });
    }

    /** @return array<string, mixed> */
    public function validate(string $id, ?string $userId): array
    {
        $d = $this->row($id);
        $next = $this->machine->getNextState((string) $d->status, 'validate');
        $today = CarbonImmutable::today();
        $year = (int) $d->fiscal_year;
        // Aplica desde el mes de validación del ejercicio en curso; de un ejercicio futuro, desde enero; de uno pasado,
        // solo en el último mes (ajuste final), sin tocar los meses ya cerrados.
        $month = $year === $today->year ? $today->month : ($year > $today->year ? 1 : 12);

        return DB::transaction(function () use ($d, $id, $next, $today, $month, $userId): array {
            $previous = DB::table('external_income_declarations')->where('employment_id', $d->employment_id)->where('fiscal_year', $d->fiscal_year)
                ->where('kind', $d->kind)->where('status', 'validated')->where('id', '<>', $id)
                ->where(fn ($q) => $d->employer_tax_id === null ? $q->whereNull('employer_tax_id') : $q->where('employer_tax_id', $d->employer_tax_id))
                ->pluck('id')->all();
            foreach ($previous as $p) {
                DB::table('external_income_declarations')->where('id', $p)->update(['status' => 'superseded', 'superseded_by_id' => $id]);
                $this->audit->record('external_income_declaration.superseded', 'external_income_declaration', (string) $p, ['status' => 'validated'], ['status' => 'superseded', 'superseded_by_id' => $id]);
            }
            DB::table('external_income_declarations')->where('id', $id)->update([
                'status' => $next, 'validated_on' => $today->toDateString(), 'validated_by_user_id' => $userId, 'applies_from_month' => $month,
            ]);
            $this->audit->record('external_income_declaration.validated', 'external_income_declaration', $id, ['status' => (string) $d->status],
                ['status' => $next, 'applies_from_month' => $month, 'supersedes' => array_map('strval', $previous)]);
            $this->outbox->record(DomainEventTypes::EXTERNAL_INCOME_VALIDATED, 'external_income_declaration', $id, [
                'declaration_id' => $id, 'employment_id' => (string) $d->employment_id, 'fiscal_year' => (int) $d->fiscal_year, 'applies_from_month' => $month,
            ]);

            return $this->show($id);
        });
    }

    /** @return array<string, mixed> */
    public function reject(string $id, string $reason, ?string $userId): array
    {
        $d = $this->row($id);
        $next = $this->machine->getNextState((string) $d->status, 'reject');

        return DB::transaction(function () use ($d, $id, $next, $reason, $userId): array {
            DB::table('external_income_declarations')->where('id', $id)->update(['status' => $next, 'rejection_reason' => $reason, 'validated_by_user_id' => $userId]);
            $this->audit->record('external_income_declaration.rejected', 'external_income_declaration', $id, ['status' => (string) $d->status], ['status' => $next, 'reason' => $reason]);

            return $this->show($id);
        });
    }

    /** @return array<string, mixed> */
    public function supersede(string $id, ?string $userId): array
    {
        $d = $this->row($id);
        $next = $this->machine->getNextState((string) $d->status, 'supersede');

        return DB::transaction(function () use ($d, $id, $next, $userId): array {
            DB::table('external_income_declarations')->where('id', $id)->update(['status' => $next]);
            $this->audit->record('external_income_declaration.superseded', 'external_income_declaration', $id, ['status' => (string) $d->status], ['status' => $next, 'by_user_id' => $userId]);

            return $this->show($id);
        });
    }

    private function row(string $id): \stdClass
    {
        $r = preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? DB::table('external_income_declarations')->where('id', $id)->first() : null;
        if ($r === null) {
            throw new AyniException('not_found', 404);
        }

        return $r;
    }

    /** @return array<string, mixed> */
    private function present(\stdClass $r): array
    {
        $cur = (string) ($r->currency ?? 'PEN');
        $money = fn (mixed $v): ?array => $v === null ? null : ['amount' => (string) $v, 'currency' => $cur];
        $date = fn (mixed $v): ?string => $v === null ? null : substr((string) $v, 0, 10);

        // Los montos opcionales sin valor no viajan (el contrato no los admite nulos).
        return array_filter([
            'id' => (string) $r->id, 'employment_id' => (string) $r->employment_id, 'fiscal_year' => (int) $r->fiscal_year, 'kind' => (string) $r->kind,
            'employer_tax_id' => $r->employer_tax_id === null ? null : (string) $r->employer_tax_id,
            'employer_name' => $r->employer_name === null ? null : (string) $r->employer_name,
            'period_from' => $date($r->period_from), 'period_to' => $date($r->period_to),
            'gross_income' => $money($r->gross_income), 'exempt_income' => $money($r->exempt_income), 'tax_withheld' => $money($r->tax_withheld),
            'monthly_detail' => $r->monthly_detail === null ? null : json_decode((string) $r->monthly_detail, true),
            'this_employer_withholds' => $r->this_employer_withholds === null ? null : (bool) $r->this_employer_withholds,
            'document_id' => $r->document_id === null ? null : (string) $r->document_id,
            'received_on' => $date($r->received_on), 'validated_on' => $date($r->validated_on),
            'applies_from_month' => $r->applies_from_month === null ? null : (int) $r->applies_from_month,
            'rejection_reason' => $r->rejection_reason === null ? null : (string) $r->rejection_reason,
            'superseded_by_id' => $r->superseded_by_id === null ? null : (string) $r->superseded_by_id,
            'status' => (string) $r->status, 'allowed_actions' => array_map(fn (string $a): string => str_replace('_', '-', $a), $this->machine->allowedActions((string) $r->status)),
            'created_at' => CarbonImmutable::parse((string) $r->created_at)->toIso8601ZuluString(),
            'updated_at' => CarbonImmutable::parse((string) $r->updated_at)->toIso8601ZuluString(),
        ], fn (mixed $v, string $k): bool => $v !== null || ! in_array($k, ['gross_income', 'exempt_income', 'tax_withheld'], true), ARRAY_FILTER_USE_BOTH);
    }
}
