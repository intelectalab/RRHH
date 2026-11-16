<?php

namespace App\Modules\Imports\Application;

use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Outbox\OutboxService;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Setup\SetupStep;
use App\Shared\Setup\SetupStepRegistry;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * IMP-3 · Asistente de primeros pasos (E-27).
 *
 * - Cada paso lo declara su módulo (SetupStep) y lleva a la pantalla real. Se da por hecho cuando su dato
 *   existe (lo decide la organización con sus datos, no un clic); el avance se guarda en
 *   setup_checklist_items para retomarlo donde quedó.
 * - Un paso pendiente se puede saltar y uno saltado, retomar; uno hecho no se salta (409). Solo aparecen
 *   los pasos de los módulos contratados.
 * - El asistente se oculta cuando todo está hecho u omitido, o cuando la organización decide omitirlo
 *   (parámetro puesta_en_marcha.omitida, con vigencia y auditoría); se puede volver a mostrar.
 * - Al quedar todo hecho u omitido se emite onboarding_setup.completed una sola vez.
 */
final class SetupChecklistService
{
    public const DISMISSED_PARAMETER = 'puesta_en_marcha.omitida';

    public function __construct(
        private readonly SetupStepRegistry $steps,
        private readonly ModuleGate $modules,
        private readonly ParameterResolver $parameters,
        private readonly AuditLogger $audit,
        private readonly OutboxService $outbox,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function checklist(): array
    {
        return DB::transaction(fn (): array => $this->sync());
    }

    /**
     * @return array<string, mixed>
     */
    public function skip(string $code, ?string $userId): array
    {
        return $this->move($code, 'skip', $userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function reopen(string $code, ?string $userId): array
    {
        return $this->move($code, 'reopen', $userId);
    }

    /**
     * Ocultar (true) o volver a mostrar (false) el asistente.
     *
     * @return array<string, mixed>
     */
    public function dismiss(bool $hidden, ?string $reason): array
    {
        $this->parameters->set(self::DISMISSED_PARAMETER, $hidden, 'organization', null, now()->toDateString(), $reason);

        return $this->checklist();
    }

    /**
     * @return array<string, mixed>
     */
    private function move(string $code, string $action, ?string $userId): array
    {
        return DB::transaction(function () use ($code, $action, $userId): array {
            $step = $this->steps->get($code);
            if (! $step instanceof SetupStep || ! $this->modules->isEnabled($step->moduleCode())) {
                throw new AyniException('not_found', 404);
            }
            $this->sync();
            $row = DB::table('setup_checklist_items')->where('code', $code)->lockForUpdate()->first();
            $status = $row instanceof \stdClass ? (string) $row->status : 'pending';
            $allowed = $action === 'skip' ? $status === 'pending' : $status === 'skipped';
            if (! $allowed) {
                throw new InvalidTransitionException($status, $status === 'pending' ? ['skip'] : ($status === 'skipped' ? ['reopen'] : []));
            }
            $to = $action === 'skip' ? 'skipped' : 'pending';
            DB::table('setup_checklist_items')->where('code', $code)->update([
                'status' => $to, 'completed_by_user_id' => $to === 'skipped' ? $userId : null,
                'completed_at' => $to === 'skipped' ? now() : null, 'updated_at' => now(),
            ]);
            $this->audit->record('setup_step.'.($action === 'skip' ? 'skipped' : 'reopened'), 'setup_checklist_item', $row instanceof \stdClass ? (string) $row->id : null,
                ['code' => $code, 'status' => $status], ['code' => $code, 'status' => $to]);

            return $this->sync();
        });
    }

    /**
     * Pone al día el avance guardado con los datos de la organización y lo presenta.
     *
     * @return array<string, mixed>
     */
    private function sync(): array
    {
        $steps = array_values(array_filter($this->steps->all(), fn (SetupStep $s) => $this->modules->isEnabled($s->moduleCode())));
        $rows = DB::table('setup_checklist_items')->get()->keyBy('code');
        $tenant = TenancyScope::requireTenant();
        $items = [];
        $finished = 0;
        foreach ($steps as $step) {
            $code = $step->code();
            $row = $rows->get($code);
            $stored = $row instanceof \stdClass ? (string) $row->status : null;
            $done = $step->isDone();
            $status = $done ? 'done' : ($stored === 'skipped' ? 'skipped' : 'pending');
            if ($row === null) {
                DB::table('setup_checklist_items')->insert(['tenant_id' => $tenant, 'code' => $code, 'title' => (string) trans('imports.setup.steps.'.$code.'.title'),
                    'module_code' => $step->moduleCode(), 'status' => $status, 'sort_order' => $step->order(), 'completed_at' => $status === 'pending' ? null : now()]);
            } elseif ($stored !== $status) {
                DB::table('setup_checklist_items')->where('code', $code)->update(['status' => $status,
                    'completed_at' => $status === 'pending' ? null : now(), 'updated_at' => now()]);
            }
            if ($status !== 'pending') {
                $finished++;
            }
            $items[] = [
                'code' => $code,
                'title' => (string) trans('imports.setup.steps.'.$code.'.title'),
                'description' => (string) trans('imports.setup.steps.'.$code.'.description'),
                'module_code' => $step->moduleCode(),
                'status' => $status,
                'link' => $step->link(),
                'completed_at' => $status === 'pending' ? null : ($row instanceof \stdClass && $stored === $status ? (string) $row->completed_at : now()->toIso8601String()),
                'allowed_actions' => $status === 'pending' ? ['skip'] : ($status === 'skipped' ? ['reopen'] : []),
            ];
        }
        $total = count($items);
        $progress = $total === 0 ? 100 : intdiv(100 * $finished, $total);
        $completed = $total > 0 && $finished === $total;
        if ($completed && ! OutboxEvent::query()->where('event_type', DomainEventTypes::ONBOARDING_SETUP_COMPLETED)->exists()) {
            $this->outbox->record(DomainEventTypes::ONBOARDING_SETUP_COMPLETED, 'tenant', $tenant,
                ['steps' => array_map(fn (array $i) => ['code' => $i['code'], 'status' => $i['status']], $items)]);
        }
        $dismissed = (bool) ($this->parameters->value(self::DISMISSED_PARAMETER) ?? false);

        return [
            'progress' => $progress,
            'total' => $total,
            'done' => count(array_filter($items, fn (array $i) => $i['status'] === 'done')),
            'skipped' => count(array_filter($items, fn (array $i) => $i['status'] === 'skipped')),
            'completed' => $completed,
            'dismissed' => $dismissed,
            'visible' => ! $dismissed && ! $completed,
            'steps' => $items,
        ];
    }
}
