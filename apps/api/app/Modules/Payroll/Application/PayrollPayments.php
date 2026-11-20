<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Domain\PaymentBatchStateMachine;
use App\Modules\Payroll\Infrastructure\Models\PayrollGroup;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use App\Modules\Payroll\Infrastructure\Payments\GenericCsvBankFile;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Files\FileService;
use App\Shared\Files\Models\StoredFile;
use App\Shared\Labor\PaymentAccounts;
use App\Shared\Payroll\BankFileFormat;
use App\Shared\Tenancy\TenancyScope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PER-10 · Pago de la planilla (RN-PER-12).
 *
 * - El lote se arma desde la corrida cerrada del periodo: un lote por banco con un abono por trabajador a su cuenta
 *   de haberes vigente a la fecha de pago (COL-8). Quien no tiene cuenta aprobada se lista aparte; lo que va en
 *   lotes más lo listado aparte suma el neto del periodo.
 * - Armar los lotes de nuevo con lotes vigentes responde 409: no se duplican pagos.
 * - El archivo bancario sale por el formato del banco (ADR 0020: genérico CSV en el primer lanzamiento), se guarda
 *   como archivo confidencial y su descarga queda registrada.
 * - Confirmar el lote registra la referencia del banco y los abonos rechazados; con todos los lotes confirmados el
 *   periodo se marca pagado (acción mark-paid del periodo).
 */
final class PayrollPayments
{
    /** @var array<string, BankFileFormat> */
    private array $formats;

    public function __construct(
        private readonly PaymentBatchStateMachine $machine,
        private readonly PaymentAccounts $accounts,
        private readonly FileService $files,
        private readonly AuditLogger $audit,
        GenericCsvBankFile $generic,
    ) {
        $this->formats = [$generic->code() => $generic];
    }

    /**
     * Arma los lotes de pago del periodo cerrado.
     *
     * @return array<string, mixed>
     */
    public function prepare(PayrollPeriod $p, ?string $userId, ?string $paymentDate): array
    {
        if ($p->status !== 'closed') {
            throw new AyniException('invalid_transition', 409, (string) trans('payroll.payments.needs_closed'));
        }
        if (DB::table('payroll_payment_batches')->where('payroll_period_id', $p->id)->where('kind', 'salary')->where('status', '<>', 'cancelled')->exists()) {
            throw new AyniException('batches_exist', 409, (string) trans('payroll.payments.batches_exist'));
        }
        $runId = DB::table('payroll_runs')->where('payroll_period_id', $p->id)->where('status', 'closed')->orderByDesc('run_number')->value('id');
        if ($runId === null) {
            throw new AyniException('no_closed_run', 409, (string) trans('payroll.payments.no_closed_run'));
        }
        $date = $paymentDate ?? (string) ($p->day('payment_date') ?? $p->day('ends_on'));
        $group = PayrollGroup::query()->findOrFail($p->payroll_group_id);
        $rows = DB::table('payroll_run_employments')->where('payroll_run_id', $runId)->where('status', 'calculated')->orderBy('employment_id')
            ->get(['employment_id', 'net_amount', 'currency']);
        $periodNet = BigDecimal::zero();
        $byBank = [];
        $unassigned = [];
        $notPayable = 0;
        foreach ($rows as $r) {
            $net = BigDecimal::of((string) ($r->net_amount ?? '0'));
            $periodNet = $periodNet->plus($net);
            if (! $net->isPositive()) {
                $notPayable++;

                continue;
            }
            $account = $this->accounts->accountAt((string) $r->employment_id, $date);
            if ($account === null) {
                $unassigned[] = ['employment_id' => (string) $r->employment_id, 'amount' => (string) $net, 'currency' => (string) $r->currency];

                continue;
            }
            $byBank[$account['bank_code']][] = ['employment_id' => (string) $r->employment_id, 'account_id' => $account['id'], 'amount' => $net, 'currency' => (string) $r->currency];
        }
        ksort($byBank);
        $batchIds = DB::transaction(function () use ($p, $byBank, $group, $date, $userId): array {
            $tenant = TenancyScope::requireTenant();
            $ids = [];
            foreach ($byBank as $bank => $items) {
                $total = array_reduce($items, fn (BigDecimal $c, array $i): BigDecimal => $c->plus($i['amount']), BigDecimal::zero());
                $batchId = (string) DB::table('payroll_payment_batches')->insertGetId([
                    'tenant_id' => $tenant, 'payroll_period_id' => $p->id, 'legal_entity_id' => $group->legal_entity_id, 'kind' => 'salary',
                    'channel' => 'bank_file', 'bank_code' => (string) $bank, 'status' => 'draft', 'total_amount' => (string) $total,
                    'currency' => $group->currency, 'payment_date' => $date, 'items_count' => count($items),
                ]);
                foreach ($items as $i) {
                    DB::table('payroll_payment_items')->insert([
                        'tenant_id' => $tenant, 'payroll_payment_batch_id' => $batchId, 'employment_id' => $i['employment_id'],
                        'person_bank_account_id' => $i['account_id'], 'amount' => (string) $i['amount'], 'currency' => $i['currency'], 'status' => 'pending',
                    ]);
                }
                $this->audit->record('payroll_payment_batch.created', 'payroll_payment_batch', $batchId, null,
                    ['payroll_period_id' => $p->id, 'bank_code' => (string) $bank, 'items' => count($items), 'total' => (string) $total, 'requested_by' => $userId]);
                $ids[] = $batchId;
            }

            return $ids;
        });
        $batched = BigDecimal::zero();
        foreach ($byBank as $items) {
            foreach ($items as $i) {
                $batched = $batched->plus($i['amount']);
            }
        }
        $pending = array_reduce($unassigned, fn (BigDecimal $c, array $u): BigDecimal => $c->plus($u['amount']), BigDecimal::zero());

        return [
            'batches' => array_map(fn (string $id): array => $this->show($id, true), $batchIds),
            'unassigned' => $this->withNames($unassigned),
            'totals' => ['period_net' => (string) $periodNet, 'batched' => (string) $batched, 'unassigned' => (string) $pending,
                'not_payable' => $notPayable, 'currency' => $group->currency, 'balanced' => $batched->plus($pending)->isEqualTo($this->payableNet($rows))],
        ];
    }

    /**
     * Genera (o regenera) el archivo bancario del lote.
     *
     * @return array<string, mixed>
     */
    public function generate(string $batchId, ?string $userId, string $format = 'generic_csv'): array
    {
        $b = $this->batch($batchId);
        $next = $this->machine->getNextState((string) $b->status, 'generate');
        $writer = $this->formats[$format] ?? null;
        if ($writer === null) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.payments.unknown_format'),
                [['field' => 'format', 'rule' => 'in', 'message' => (string) trans('payroll.payments.unknown_format')]]);
        }
        $entity = DB::table('legal_entities')->where('id', $b->legal_entity_id)->first(['tax_id', 'legal_name']);
        $items = [];
        $rows = DB::table('payroll_payment_items AS i')->join('employments AS e', 'e.id', '=', 'i.employment_id')->join('people AS pp', 'pp.id', '=', 'e.person_id')
            ->where('i.payroll_payment_batch_id', $batchId)->orderBy('pp.paternal_surname')->orderBy('i.id')
            ->get(['i.id', 'i.employment_id', 'i.amount', 'i.currency', 'e.employee_code', 'pp.first_names', 'pp.paternal_surname', 'pp.maternal_surname']);
        foreach ($rows as $r) {
            $account = $this->accounts->accountAt((string) $r->employment_id, substr((string) $b->payment_date, 0, 10));
            if ($account === null) {
                throw new AyniException('account_changed', 409, (string) trans('payroll.payments.account_changed'));
            }
            $items[] = [
                'employee_code' => $r->employee_code === null ? null : (string) $r->employee_code, 'document_number' => null,
                'name' => trim($r->paternal_surname.' '.($r->maternal_surname ?? '').' '.$r->first_names),
                'account_type' => $account['account_type'], 'account_number' => $account['account_number'], 'interbank_code' => $account['interbank_code'],
                'amount' => (string) BigDecimal::of((string) $r->amount)->toScale(2), 'currency' => (string) $r->currency, 'reference' => (string) $r->id,
            ];
        }
        $content = $writer->render([
            'batch_id' => $batchId, 'legal_entity_tax_id' => $entity?->tax_id, 'legal_entity_name' => $entity?->legal_name, 'bank_code' => $b->bank_code,
            'payment_date' => substr((string) $b->payment_date, 0, 10), 'currency' => (string) $b->currency, 'total' => (string) BigDecimal::of((string) $b->total_amount)->toScale(2),
        ], $items);
        $name = sprintf('pago-%s-%s.%s', (string) ($b->bank_code ?? 'banco'), substr((string) $b->payment_date, 0, 10), $writer->extension());

        return DB::transaction(function () use ($b, $batchId, $content, $name, $writer, $next, $userId, $format): array {
            $file = $this->files->upload($content, $name, $writer->mime(), null, $userId, StoredFile::CLASSIFICATION_CONFIDENTIAL);
            DB::table('payroll_payment_batches')->where('id', $batchId)->update([
                'status' => $next, 'file_id' => $file->id, 'file_format' => $format, 'generated_by_user_id' => $userId, 'generated_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('payroll_payment_batch.generated', 'payroll_payment_batch', $batchId, ['status' => (string) $b->status],
                ['status' => $next, 'file_id' => $file->id, 'format' => $format]);

            return $this->show($batchId, true);
        });
    }

    /** @return array{name: string, mime: string, contents: string} */
    public function download(string $batchId, ?string $userId): array
    {
        $b = $this->batch($batchId);
        if ($b->file_id === null) {
            throw new AyniException('no_file', 409, (string) trans('payroll.payments.no_file'));
        }
        $this->audit->record('payroll_payment_batch.downloaded', 'payroll_payment_batch', $batchId, null, ['file_id' => (string) $b->file_id]);

        return $this->files->contentsForDownload((string) $b->file_id, null, $userId);
    }

    /**
     * Registra la respuesta del banco: referencia, fecha y los abonos rechazados (por relación laboral, con motivo).
     *
     * @param  list<array{employment_id: string, reason: string}>  $rejected
     * @return array<string, mixed>
     */
    public function confirm(string $batchId, ?string $userId, string $reference, ?string $paymentDate, array $rejected): array
    {
        $b = $this->batch($batchId);
        if ($b->status === 'confirmed' && $b->external_reference === $reference) {
            return $this->show($batchId, true); // Regla 9: repetir la confirmación con la misma referencia no duplica el efecto.
        }
        $next = $this->machine->getNextState((string) $b->status, 'confirm');
        $reasons = [];
        foreach ($rejected as $r) {
            $reasons[$r['employment_id']] = $r['reason'];
        }
        $known = DB::table('payroll_payment_items')->where('payroll_payment_batch_id', $batchId)->pluck('employment_id')->map(fn ($v) => (string) $v)->all();
        if (array_diff(array_keys($reasons), $known) !== []) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.payments.unknown_item'),
                [['field' => 'rejected', 'rule' => 'exists', 'message' => (string) trans('payroll.payments.unknown_item')]]);
        }

        return DB::transaction(function () use ($b, $batchId, $next, $reference, $paymentDate, $reasons, $userId): array {
            DB::table('payroll_payment_items')->where('payroll_payment_batch_id', $batchId)->whereNotIn('employment_id', array_keys($reasons))
                ->update(['status' => 'paid', 'updated_at' => now()]);
            foreach ($reasons as $employment => $reason) {
                DB::table('payroll_payment_items')->where('payroll_payment_batch_id', $batchId)->where('employment_id', $employment)
                    ->update(['status' => 'rejected', 'rejection_reason' => $reason, 'updated_at' => now()]);
            }
            DB::table('payroll_payment_batches')->where('id', $batchId)->update([
                'status' => $next, 'external_reference' => $reference, 'payment_date' => $paymentDate ?? $b->payment_date,
                'confirmed_by_user_id' => $userId, 'confirmed_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('payroll_payment_batch.confirmed', 'payroll_payment_batch', $batchId, ['status' => (string) $b->status],
                ['status' => $next, 'external_reference' => $reference, 'rejected' => count($reasons)]);

            return $this->show($batchId, true);
        });
    }

    /** @return array<string, mixed> */
    public function cancel(string $batchId, ?string $reason): array
    {
        $b = $this->batch($batchId);
        $next = $this->machine->getNextState((string) $b->status, 'cancel');
        if ($reason === null || trim($reason) === '') {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.periods.reason_required'),
                [['field' => 'reason', 'rule' => 'required', 'message' => (string) trans('payroll.periods.reason_required')]]);
        }
        DB::transaction(function () use ($b, $batchId, $next, $reason): void {
            DB::table('payroll_payment_batches')->where('id', $batchId)->update(['status' => $next, 'cancel_reason' => trim($reason), 'updated_at' => now()]);
            $this->audit->record('payroll_payment_batch.cancelled', 'payroll_payment_batch', $batchId, ['status' => (string) $b->status], ['status' => $next], trim($reason));
        });

        return $this->show($batchId, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forPeriod(string $periodId, bool $amounts): array
    {
        return array_values(DB::table('payroll_payment_batches')->where('payroll_period_id', $periodId)->orderBy('bank_code')->orderBy('id')->pluck('id')
            ->map(fn ($id): array => $this->show((string) $id, $amounts, false))->all());
    }

    /** @return array<string, mixed> */
    public function show(string $batchId, bool $amounts, bool $withItems = true): array
    {
        $b = $this->batch($batchId);
        $out = [
            'id' => (string) $b->id, 'payroll_period_id' => $b->payroll_period_id === null ? null : (string) $b->payroll_period_id,
            'legal_entity_id' => (string) $b->legal_entity_id, 'kind' => (string) $b->kind, 'channel' => (string) $b->channel, 'bank_code' => $b->bank_code,
            'status' => (string) $b->status, 'currency' => (string) $b->currency, 'payment_date' => $b->payment_date === null ? null : substr((string) $b->payment_date, 0, 10),
            'items_count' => (int) $b->items_count, 'file_format' => $b->file_format, 'has_file' => $b->file_id !== null,
            'generated_at' => $b->generated_at === null ? null : (string) $b->generated_at, 'confirmed_at' => $b->confirmed_at === null ? null : (string) $b->confirmed_at,
            'external_reference' => $b->external_reference, 'cancel_reason' => $b->cancel_reason,
            'allowed_actions' => $this->machine->allowedActions((string) $b->status),
        ];
        if ($amounts) {
            $out['total'] = ['amount' => (string) $b->total_amount, 'currency' => (string) $b->currency];
        }
        if ($withItems) {
            $rows = DB::table('payroll_payment_items AS i')->join('employments AS e', 'e.id', '=', 'i.employment_id')->join('people AS pp', 'pp.id', '=', 'e.person_id')
                ->where('i.payroll_payment_batch_id', $batchId)->orderBy('pp.paternal_surname')->orderBy('i.id')
                ->get(['i.id', 'i.employment_id', 'i.amount', 'i.currency', 'i.status', 'i.rejection_reason', 'e.employee_code', 'pp.first_names', 'pp.paternal_surname', 'pp.maternal_surname']);
            $out['items'] = array_values($rows->map(fn ($r): array => array_filter([
                'id' => (string) $r->id, 'employment_id' => (string) $r->employment_id, 'employee_code' => $r->employee_code,
                'employee_name' => trim($r->first_names.' '.$r->paternal_surname.' '.($r->maternal_surname ?? '')), 'status' => (string) $r->status,
                'rejection_reason' => $r->rejection_reason, 'amount' => $amounts ? ['amount' => (string) $r->amount, 'currency' => (string) $r->currency] : null,
            ], fn ($v) => $v !== null))->all());
        }

        return array_filter($out, fn ($v) => $v !== null);
    }

    public function periodOf(string $batchId): ?string
    {
        $id = DB::table('payroll_payment_batches')->where('id', $batchId)->value('payroll_period_id');

        return $id === null ? null : (string) $id;
    }

    /** @param Collection<int, \stdClass> $rows */
    private function payableNet($rows): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($rows as $r) {
            $net = BigDecimal::of((string) ($r->net_amount ?? '0'));
            if ($net->isPositive()) {
                $sum = $sum->plus($net);
            }
        }

        return $sum;
    }

    /**
     * @param  list<array{employment_id: string, amount: string, currency: string}>  $rows
     * @return list<array<string, mixed>>
     */
    private function withNames(array $rows): array
    {
        $names = DB::table('employments AS e')->join('people AS pp', 'pp.id', '=', 'e.person_id')->whereIn('e.id', array_column($rows, 'employment_id'))
            ->get(['e.id', 'e.employee_code', 'pp.first_names', 'pp.paternal_surname', 'pp.maternal_surname'])->keyBy('id');

        return array_map(function (array $r) use ($names): array {
            $n = $names[$r['employment_id']] ?? null;

            return ['employment_id' => $r['employment_id'], 'employee_code' => $n?->employee_code,
                'employee_name' => $n === null ? '' : trim($n->first_names.' '.$n->paternal_surname.' '.($n->maternal_surname ?? '')),
                'amount' => ['amount' => $r['amount'], 'currency' => $r['currency']]];
        }, $rows);
    }

    private function batch(string $batchId): \stdClass
    {
        /** @var \stdClass|null $b */
        $b = DB::table('payroll_payment_batches')->where('id', $batchId)->first();
        if ($b === null) {
            throw new AyniException('not_found', 404);
        }

        return $b;
    }
}
