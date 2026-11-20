<?php

namespace App\Modules\Payroll\Application\Declarations;

use App\Modules\Payroll\Application\FifthCategoryLedger;
use App\Shared\Audit\AuditLogger;
use App\Shared\Documents\DocumentArchive;
use App\Shared\Documents\SimplePdf;
use App\Shared\Exceptions\AyniException;
use App\Shared\Files\FileService;
use App\Shared\Tenancy\TenancyScope;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * DEC-6 · Certificado de rentas y retenciones de quinta categoría por trabajador y ejercicio, desde el libro de
 * retenciones (PER-11). Su entrega no es obligatoria desde 2017: se emite al cese, al cierre del ejercicio o a
 * solicitud, sin alerta de incumplimiento, y puede haber más de uno por ejercicio; cada uno guarda su motivo y su
 * huella. Los montos salen del libro (retenido acumulado, incluido lo traído del sistema anterior, menos las
 * devoluciones), nunca se recalculan aquí. El PDF va al legajo, visible en el portal del colaborador.
 * Por validar: el diseño del certificado (no hay formato oficial obligatorio).
 */
final class WithholdingCertificates
{
    public const REASONS = ['annual', 'termination', 'on_request'];

    public function __construct(
        private readonly FifthCategoryLedger $ledger,
        private readonly DocumentArchive $archive,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function issue(string $employmentId, int $year, string $reason, ?string $userId): array
    {
        $last = DB::table('fifth_category_ledger AS g')->leftJoin('payroll_runs AS r', 'r.id', '=', 'g.payroll_run_id')
            ->where('g.employment_id', $employmentId)->where('g.fiscal_year', $year)
            ->where(fn ($q) => $q->whereNull('g.payroll_run_id')->orWhere('r.status', '<>', 'discarded'))
            ->orderByDesc('g.period_month')->orderByDesc('g.sequence')->orderByDesc('g.created_at')->first();
        if ($last === null) {
            throw new AyniException('no_ledger', 409, (string) trans('payroll.declarations.no_ledger'));
        }
        $summary = $this->ledger->summary($employmentId, $year);
        $refunded = BigDecimal::of((string) (DB::table('fifth_category_refunds')->where('employment_id', $employmentId)->where('fiscal_year', $year)
            ->whereIn('status', ['refunded', 'compensated', 'closed'])->sum('amount') ?: '0'));
        $withheld = BigDecimal::of($summary['accumulated'])->plus($summary['opening']['withheld']);
        $emp = DB::table('employments AS e')->join('people AS p', 'p.id', '=', 'e.person_id')->join('legal_entities AS l', 'l.id', '=', 'e.legal_entity_id')
            ->where('e.id', $employmentId)->first(['e.person_id', 'e.legal_entity_id', 'e.hire_date', 'e.termination_date', 'p.first_names', 'p.paternal_surname', 'p.maternal_surname', 'l.legal_name', 'l.tax_id']);
        if ($emp === null) {
            throw new AyniException('not_found', 404);
        }
        $from = max(sprintf('%04d-01-01', $year), substr((string) $emp->hire_date, 0, 10));
        $to = $emp->termination_date !== null && substr((string) $emp->termination_date, 0, 4) === (string) $year ? substr((string) $emp->termination_date, 0, 10) : sprintf('%04d-12-31', $year);
        $values = [
            'total_income' => (string) BigDecimal::of((string) $last->gross_annual_income)->toScale(2),
            'external_income' => (string) BigDecimal::of((string) $last->external_income)->toScale(2),
            'deduction_amount' => (string) BigDecimal::of((string) $last->deduction_amount)->toScale(2),
            'annual_tax' => (string) BigDecimal::of((string) $last->annual_tax)->toScale(2),
            'total_withheld' => (string) $withheld->toScale(2),
            'refunded_amount' => (string) $refunded->toScale(2),
        ];
        $currency = (string) $last->currency;
        $name = trim($emp->paternal_surname.' '.($emp->maternal_surname ?? '').' '.$emp->first_names);
        $body = implode("\n", [
            (string) trans('payroll.declarations.certificate.employer', ['name' => $emp->legal_name, 'ruc' => $emp->tax_id]),
            (string) trans('payroll.declarations.certificate.worker', ['name' => $name]),
            (string) trans('payroll.declarations.certificate.period', ['year' => $year, 'from' => $from, 'to' => $to]),
            (string) trans('payroll.declarations.certificate.reason_'.$reason),
            '',
            (string) trans('payroll.declarations.certificate.income', ['amount' => $values['total_income'], 'currency' => $currency]),
            (string) trans('payroll.declarations.certificate.external', ['amount' => $values['external_income'], 'currency' => $currency]),
            (string) trans('payroll.declarations.certificate.deduction', ['amount' => $values['deduction_amount'], 'currency' => $currency]),
            (string) trans('payroll.declarations.certificate.tax', ['amount' => $values['annual_tax'], 'currency' => $currency]),
            (string) trans('payroll.declarations.certificate.withheld', ['amount' => $values['total_withheld'], 'currency' => $currency]),
            (string) trans('payroll.declarations.certificate.refunded', ['amount' => $values['refunded_amount'], 'currency' => $currency]),
        ]);
        $hash = hash('sha256', json_encode([$employmentId, $year, $reason, $values, $currency, $from, $to]) ?: '');
        $pdf = SimplePdf::render((string) trans('payroll.declarations.certificate.title', ['year' => $year]), $body,
            (string) trans('payroll.declarations.certificate.footer', ['hash' => substr($hash, 0, 16)]));

        return DB::transaction(function () use ($employmentId, $year, $reason, $values, $currency, $from, $to, $hash, $pdf, $emp, $userId): array {
            $category = $this->archive->category('withholding_certificate', (string) trans('payroll.declarations.certificate.category'), 'payroll.fifth_category_ledger.view');
            $documentId = $this->archive->create([
                'category_id' => $category, 'title' => (string) trans('payroll.declarations.certificate.title', ['year' => $year]),
                'employment_id' => $employmentId, 'person_id' => (string) $emp->person_id, 'legal_entity_id' => (string) $emp->legal_entity_id,
                'subject_type' => 'withholding_certificate', 'subject_id' => null, 'source' => 'generated', 'status' => 'active',
                'issued_on' => CarbonImmutable::today()->toDateString(), 'visible_to_employee' => true,
            ], $pdf, sprintf('certificado-quinta-%d.pdf', $year), 'application/pdf', $userId);
            $id = (string) DB::table('withholding_certificates')->insertGetId([
                'tenant_id' => TenancyScope::requireTenant(), 'employment_id' => $employmentId, 'fiscal_year' => $year, 'currency' => $currency,
                'document_id' => $documentId, 'issued_on' => CarbonImmutable::today()->toDateString(), 'reason' => $reason, 'period_from' => $from, 'period_to' => $to,
                'content_hash' => $hash,
            ] + $values);
            DB::table('documents')->where('id', $documentId)->update(['subject_id' => $id]);
            $this->audit->record('withholding_certificate.issued', 'withholding_certificate', $id, null, ['fiscal_year' => $year, 'reason' => $reason, 'content_hash' => $hash]);

            return $this->show($id);
        });
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        $r = DB::table('withholding_certificates')->where('id', $id)->first();
        if ($r === null) {
            throw new AyniException('not_found', 404);
        }
        $m = fn (mixed $v): ?array => $v === null ? null : ['amount' => (string) BigDecimal::of((string) $v)->toScale(2), 'currency' => (string) $r->currency];

        return [
            'id' => (string) $r->id, 'employment_id' => (string) $r->employment_id, 'fiscal_year' => (int) $r->fiscal_year, 'reason' => (string) $r->reason,
            'issued_on' => $r->issued_on === null ? null : substr((string) $r->issued_on, 0, 10),
            'period_from' => $r->period_from === null ? null : substr((string) $r->period_from, 0, 10),
            'period_to' => $r->period_to === null ? null : substr((string) $r->period_to, 0, 10),
            'total_income' => $m($r->total_income), 'external_income' => $m($r->external_income), 'deduction_amount' => $m($r->deduction_amount),
            'annual_tax' => $m($r->annual_tax), 'total_withheld' => $m($r->total_withheld), 'refunded_amount' => $m($r->refunded_amount),
            'document_id' => $r->document_id === null ? null : (string) $r->document_id, 'content_hash' => $r->content_hash === null ? null : (string) $r->content_hash,
        ];
    }

    /**
     * @param  list<string>|null  $employments  relaciones visibles (null = todas)
     * @return list<array<string, mixed>>
     */
    public function list(?array $employments, ?string $employmentId, ?int $year): array
    {
        $q = DB::table('withholding_certificates')->orderByDesc('fiscal_year')->orderByDesc('created_at')->limit(500);
        if ($employments !== null) {
            $q->whereIn('employment_id', $employments);
        }
        if ($employmentId !== null) {
            $q->where('employment_id', $employmentId);
        }
        if ($year !== null) {
            $q->where('fiscal_year', $year);
        }

        return array_values($q->pluck('id')->map(fn ($id): array => $this->show((string) $id))->all());
    }

    public function employmentOf(string $id): ?string
    {
        $e = DB::table('withholding_certificates')->where('id', $id)->value('employment_id');

        return $e === null ? null : (string) $e;
    }

    /** @return array{name: string, mime: string, contents: string} */
    public function download(string $id, ?string $userId): array
    {
        $doc = DB::table('withholding_certificates AS w')->join('documents AS d', 'd.id', '=', 'w.document_id')->join('document_versions AS v', 'v.id', '=', 'd.current_version_id')
            ->where('w.id', $id)->first(['v.stored_file_id', 'w.content_hash']);
        if ($doc === null) {
            throw new AyniException('not_found', 404);
        }
        $this->audit->record('withholding_certificate.downloaded', 'withholding_certificate', $id, null, ['content_hash' => $doc->content_hash]);

        return app(FileService::class)->contentsForDownload((string) $doc->stored_file_id, null, $userId);
    }
}
