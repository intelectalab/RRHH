<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\CostCenterStateMachine;
use App\Modules\Organization\Infrastructure\Models\CostCenter;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Exceptions\VersionConflictException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ORG-4 · Centros de costo por empresa (o compartidos) con código, jerarquía y vigencia.
 *
 * - Jerarquía sin ciclos; el padre es de la misma empresa o compartido y no está archivado.
 * - Cerrar fija closed_on (último día que admite imputaciones). Cerrado o archivado no admite nuevas
 *   asignaciones ni distribuciones; no se cierra ni archiva mientras haya subcentros abiertos o
 *   asignaciones y distribuciones que sigan vigentes después del cierre.
 * - El estado cambia solo por acciones (close / reopen / archive / restore).
 */
final class CostCenterService
{
    public const FIELDS = ['legal_entity_id', 'parent_id', 'code', 'name', 'accounting_code', 'opened_on'];

    public function __construct(
        private readonly CostCenterStateMachine $machine,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(CostCenter $center): array
    {
        return [
            'id' => $center->id,
            'code' => $center->code,
            'name' => $center->name,
            'legal_entity_id' => $center->legal_entity_id,
            'parent_id' => $center->parent_id,
            'path' => $this->path($center),
            'accounting_code' => $center->accounting_code,
            'opened_on' => $center->opened_on?->toDateString(),
            'closed_on' => $center->closed_on?->toDateString(),
            'status' => match ($center->status) {
                'inactive' => 'archived',
                'closed' => 'closed',
                default => 'active',
            },
            'allowed_actions' => $this->machine->allowedActions($center->status),
            'version' => $center->lock_version,
            'created_at' => $center->created_at->toIso8601String(),
            'updated_at' => $center->updated_at->toIso8601String(),
        ];
    }

    /**
     * Códigos de los ancestros, de la raíz al propio centro.
     *
     * @return list<string>
     */
    public function path(CostCenter $center): array
    {
        $path = [$center->code];
        $seen = [$center->id => true];
        $parent = $center->parent_id;
        while (is_string($parent) && ! isset($seen[$parent]) && count($path) < 50) {
            $seen[$parent] = true;
            $row = DB::table('cost_centers')->where('id', $parent)->first(['code', 'parent_id']);
            if ($row === null) {
                break;
            }
            array_unshift($path, (string) $row->code);
            $parent = $row->parent_id;
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): CostCenter
    {
        $values = array_intersect_key($input, array_flip(self::FIELDS));
        $this->check($values, null);

        return DB::transaction(function () use ($values): CostCenter {
            $center = CostCenter::create($values + ['status' => 'active']);
            $this->audit->record('cost_center.created', 'cost_center', $center->id, null, $this->present($center->refresh()));

            return $center;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(CostCenter $center, array $input, ?int $expectedVersion): CostCenter
    {
        if ($expectedVersion !== null && $expectedVersion !== $center->lock_version) {
            throw new VersionConflictException($expectedVersion, $center->lock_version);
        }
        if ($center->status === 'inactive') {
            throw new AyniException('invalid_transition', 409, null, [], ['current_status' => 'archived', 'allowed_actions' => ['restore']]);
        }
        $values = array_intersect_key($input, array_flip(self::FIELDS));
        $merged = array_merge(['legal_entity_id' => $center->legal_entity_id, 'parent_id' => $center->parent_id, 'opened_on' => $center->opened_on?->toDateString()], $values);
        $this->check($merged + (isset($values['code']) ? ['code' => $values['code']] : []), $center);

        return DB::transaction(function () use ($center, $values): CostCenter {
            $before = $this->present($center);
            $center->fill($values);
            if ($center->isDirty()) {
                $center->save();
            }
            $center->refresh();
            $this->audit->record('cost_center.updated', 'cost_center', $center->id, $before, $this->present($center));

            return $center;
        });
    }

    public function transition(CostCenter $center, string $action, ?string $reason, ?string $closedOn = null): CostCenter
    {
        $next = $this->machine->getNextState($center->status, $action);
        $changes = ['status' => $next];
        if ($action === 'close') {
            $closedOn ??= now()->toDateString();
            if ($center->opened_on !== null && $closedOn < $center->opened_on->toDateString()) {
                throw ValidationException::withMessages(['closed_on' => [(string) trans('organization.cost_centers.close_before_open')]]);
            }
            $this->guardNothingOpenAfter($center, $closedOn);
            $changes['closed_on'] = $closedOn;
        } elseif ($action === 'reopen') {
            $changes['closed_on'] = null;
        } elseif ($action === 'archive') {
            $this->guardNothingOpenAfter($center, $center->closed_on?->toDateString() ?? now()->subDay()->toDateString());
        } elseif ($action === 'restore') {
            $changes['status'] = $center->closed_on === null ? 'active' : 'closed';
            $this->guardParentUsable($center->parent_id, 'parent_id');
        }

        return DB::transaction(function () use ($center, $action, $changes, $reason): CostCenter {
            $before = $this->present($center);
            $center->forceFill($changes)->save();
            $center->refresh();
            $events = ['close' => 'closed', 'reopen' => 'reopened', 'archive' => 'archived', 'restore' => 'restored'];
            $this->audit->record('cost_center.'.$events[$action], 'cost_center', $center->id, $before, $this->present($center) + ['reason' => $reason]);

            return $center;
        });
    }

    /**
     * No se cierra (ni se archiva) con subcentros abiertos ni con asignaciones o distribuciones que
     * sigan vigentes después del último día.
     */
    private function guardNothingOpenAfter(CostCenter $center, string $lastDay): void
    {
        $children = CostCenter::query()->where('parent_id', $center->id)->where('status', 'active')->count();
        if ($children > 0) {
            throw new BusinessRuleViolatedException('ORG-4', (string) trans('organization.cost_centers.has_open_children', ['count' => $children]));
        }
        $distributions = DB::table('employment_cost_distribution_lines as l')
            ->join('employment_cost_distributions as d', 'd.id', '=', 'l.employment_cost_distribution_id')
            ->where('l.cost_center_id', $center->id)
            ->where(fn ($q) => $q->whereNull('d.valid_to')->orWhere('d.valid_to', '>', $lastDay))
            ->distinct()->count('d.employment_id');
        $assignments = DB::table('employment_assignments')->where('cost_center_id', $center->id)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $lastDay))
            ->distinct()->count('employment_id');
        if ($distributions + $assignments > 0) {
            throw new BusinessRuleViolatedException('ORG-4', (string) trans('organization.cost_centers.in_use_after_close', ['count' => $distributions + $assignments]));
        }
    }

    /**
     * @param  array<string, mixed>  $v
     */
    private function check(array $v, ?CostCenter $center): void
    {
        $errors = [];
        $entityId = $v['legal_entity_id'] ?? null;
        if (is_string($entityId) && (! $center instanceof CostCenter || $entityId !== $center->legal_entity_id)
            && ! LegalEntity::query()->whereKey($entityId)->where('status', 'active')->exists()) {
            $errors['legal_entity_id'] = [(string) trans('organization.cost_centers.company_not_active')];
        }
        $code = $v['code'] ?? null;
        if (is_string($code) && CostCenter::query()->whereRaw('lower(code) = lower(?)', [$code])
            ->when($center instanceof CostCenter, fn ($q) => $q->whereKeyNot($center?->id))->exists()) {
            $errors['code'] = [(string) trans('organization.cost_centers.duplicate_code')];
        }
        $parentId = $v['parent_id'] ?? null;
        if (is_string($parentId)) {
            $parent = CostCenter::query()->find($parentId);
            if (! $parent instanceof CostCenter || $parent->status === 'inactive') {
                $errors['parent_id'] = [(string) trans('organization.cost_centers.parent_not_found')];
            } elseif ($parent->legal_entity_id !== null && $parent->legal_entity_id !== $entityId) {
                $errors['parent_id'] = [(string) trans('organization.cost_centers.parent_other_company')];
            } elseif ($center instanceof CostCenter && $this->isDescendantOrSelf($parentId, $center->id)) {
                $errors['parent_id'] = [(string) trans('organization.cost_centers.parent_cycle')];
            }
        }
        if ($center instanceof CostCenter && array_key_exists('legal_entity_id', $v) && $v['legal_entity_id'] !== $center->legal_entity_id
            && CostCenter::query()->where('parent_id', $center->id)->whereNotNull('legal_entity_id')->where('legal_entity_id', '!=', $v['legal_entity_id'] ?? '')->exists()) {
            $errors['legal_entity_id'] = [(string) trans('organization.cost_centers.children_other_company')];
        }
        $opened = $v['opened_on'] ?? null;
        if ($center instanceof CostCenter && is_string($opened) && $center->closed_on !== null && $opened > $center->closed_on->toDateString()) {
            $errors['opened_on'] = [(string) trans('organization.cost_centers.close_before_open')];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function guardParentUsable(?string $parentId, string $field): void
    {
        if ($parentId !== null && ! CostCenter::query()->whereKey($parentId)->where('status', '!=', 'inactive')->exists()) {
            throw ValidationException::withMessages([$field => [(string) trans('organization.cost_centers.parent_archived')]]);
        }
    }

    /** ¿$candidate es $centerId o uno de sus descendientes? (mover bajo uno de ellos crearía un ciclo). */
    private function isDescendantOrSelf(string $candidate, string $centerId): bool
    {
        $current = $candidate;
        for ($i = 0; $i < 50 && is_string($current); $i++) {
            if ($current === $centerId) {
                return true;
            }
            $next = DB::table('cost_centers')->where('id', $current)->value('parent_id');
            $current = is_string($next) ? $next : null;
        }

        return false;
    }
}
