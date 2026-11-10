<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\LegalEntityStateMachine;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\PlanLimitReachedException;
use App\Shared\Exceptions\VersionConflictException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TEN-5 · Empresas (razones sociales) del grupo.
 *
 * - RN-TEN-05: no se eliminan; se archivan y dejan de aceptar altas.
 * - RN-TEN-07: límite de empresas del plan (tenant_limits.legal_entities).
 * - El régimen laboral por defecto se guarda con vigencia (regla 2).
 */
final class LegalEntityService
{
    public const LIMIT_CODE = 'legal_entities';

    public function __construct(
        private readonly LegalEntityStateMachine $machine,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(LegalEntity $entity): array
    {
        $regime = $entity->currentRegime();

        return [
            'id' => $entity->id,
            'name' => $entity->legal_name,
            'trade_name' => $entity->trade_name,
            'tax_id' => $entity->tax_id,
            'country' => $entity->country_code,
            'currency' => $entity->currency,
            'timezone' => $entity->timezone,
            'payroll_regime' => $regime === null ? '' : (string) $regime->labor_regime_code,
            'status' => $entity->status === 'active' ? 'active' : 'archived',
            'allowed_actions' => $this->machine->allowedActions($entity->status),
            'version' => $entity->lock_version,
            'created_at' => $entity->created_at->toIso8601String(),
            'updated_at' => $entity->updated_at->toIso8601String(),
        ];
    }

    /**
     * @param  array{name: string, trade_name?: string|null, tax_id: string, country: string, currency: string, timezone: string, payroll_regime: string}  $input
     */
    public function create(array $input): LegalEntity
    {
        $this->guardPlanLimit();
        $this->validateReferences($input['country'], $input['currency'], $input['payroll_regime'], $input['tax_id']);
        $this->guardUniqueTaxId($input['country'], $input['tax_id']);

        return DB::transaction(function () use ($input): LegalEntity {
            $entity = LegalEntity::create([
                'legal_name' => $input['name'],
                'trade_name' => $input['trade_name'] ?? null,
                'tax_id' => $input['tax_id'],
                'country_code' => $input['country'],
                'currency' => $input['currency'],
                'timezone' => $input['timezone'],
                'status' => 'active',
            ]);
            $entity->regimes()->create(['labor_regime_code' => $input['payroll_regime'], 'valid_from' => now()->toDateString()]);

            $this->outbox->record(DomainEventTypes::COMPANY_CREATED, 'legal_entity', $entity->id, [
                'legal_entity_id' => $entity->id, 'country_code' => $entity->country_code,
            ]);
            $this->audit->record('legal_entity.created', 'legal_entity', $entity->id, null, $this->present($entity->refresh()));

            return $entity;
        });
    }

    /**
     * @param  array{name?: string, trade_name?: string|null, tax_id?: string, currency?: string, timezone?: string, payroll_regime?: string}  $input
     */
    public function update(LegalEntity $entity, array $input, ?int $expectedVersion): LegalEntity
    {
        if ($expectedVersion !== null && $expectedVersion !== $entity->lock_version) {
            throw new VersionConflictException($expectedVersion, $entity->lock_version);
        }
        if ($entity->status !== 'active') {
            throw new AyniException('invalid_transition', 409, null, [], ['current_status' => 'archived', 'allowed_actions' => ['restore']]);
        }

        $taxId = $input['tax_id'] ?? $entity->tax_id;
        $this->validateReferences($entity->country_code, $input['currency'] ?? $entity->currency, $input['payroll_regime'] ?? null, $taxId);
        if ($taxId !== $entity->tax_id) {
            $this->guardUniqueTaxId($entity->country_code, $taxId, $entity->id);
        }

        return DB::transaction(function () use ($entity, $input): LegalEntity {
            $before = $this->present($entity);

            $map = ['name' => 'legal_name', 'trade_name' => 'trade_name', 'tax_id' => 'tax_id', 'currency' => 'currency', 'timezone' => 'timezone'];
            foreach ($map as $in => $column) {
                if (array_key_exists($in, $input)) {
                    $entity->setAttribute($column, $input[$in]);
                }
            }
            if ($entity->isDirty()) {
                $entity->save();
            }

            if (isset($input['payroll_regime']) && $input['payroll_regime'] !== $before['payroll_regime']) {
                $this->changeRegime($entity, $input['payroll_regime']);
            }

            $entity->refresh();
            $this->audit->record('legal_entity.updated', 'legal_entity', $entity->id, $before, $this->present($entity));

            return $entity;
        });
    }

    public function transition(LegalEntity $entity, string $action, ?string $reason): LegalEntity
    {
        $next = $this->machine->getNextState($entity->status, $action);
        if ($action === 'restore') {
            $this->guardPlanLimit();
        }

        return DB::transaction(function () use ($entity, $action, $next, $reason): LegalEntity {
            $before = $this->present($entity);
            $entity->forceFill(['status' => $next])->save();
            if ($action === 'archive') {
                $this->outbox->record(DomainEventTypes::COMPANY_ARCHIVED, 'legal_entity', $entity->id, ['legal_entity_id' => $entity->id]);
            }
            $this->audit->record("legal_entity.{$action}d", 'legal_entity', $entity->id, $before, $this->present($entity->refresh()), $reason);

            return $entity;
        });
    }

    /**
     * Cierra el régimen vigente e inserta el nuevo. Si el vigente empezó hoy, el nuevo rige desde mañana.
     */
    private function changeRegime(LegalEntity $entity, string $code): void
    {
        $current = $entity->currentRegime();
        $from = Carbon::today();
        if ($current !== null && ! $current->valid_from->lt($from)) {
            $from = $current->valid_from->copy()->addDay();
        }

        if ($current !== null) {
            $current->forceFill(['valid_to' => $from->copy()->subDay()->toDateString()])->save();
        }
        $entity->regimes()->create(['labor_regime_code' => $code, 'valid_from' => $from->toDateString()]);
    }

    private function guardPlanLimit(): void
    {
        $limit = DB::table('tenant_limits')->where('limit_code', self::LIMIT_CODE)->first(['limit_value', 'is_unlimited']);
        if ($limit === null || $limit->is_unlimited || $limit->limit_value === null) {
            return;
        }

        $current = LegalEntity::query()->where('status', 'active')->count();
        if ($current >= (int) $limit->limit_value) {
            throw new PlanLimitReachedException(self::LIMIT_CODE, $current, (int) $limit->limit_value);
        }
    }

    private function validateReferences(string $country, string $currency, ?string $regime, string $taxId): void
    {
        $errors = [];
        if (! DB::table('countries')->where('code', $country)->exists()) {
            $errors['country'][] = (string) trans('organization.unknown_country');
        }
        if (! DB::table('currencies')->where('code', $currency)->exists()) {
            $errors['currency'][] = (string) trans('organization.unknown_currency');
        }
        if ($regime !== null && ! DB::table('labor_regimes')->where('code', $regime)->where('country_code', $country)->exists()) {
            $errors['payroll_regime'][] = (string) trans('organization.unknown_regime');
        }
        $pattern = config("countries.{$country}.tax_id_pattern");
        if (is_string($pattern) && preg_match($pattern, $taxId) !== 1) {
            $errors['tax_id'][] = (string) trans('organization.invalid_tax_id', ['label' => (string) config("countries.{$country}.tax_id_label", 'ID')]);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function guardUniqueTaxId(string $country, string $taxId, ?string $exceptId = null): void
    {
        $exists = LegalEntity::query()
            ->where('country_code', $country)
            ->where('tax_id', $taxId)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
        if ($exists) {
            throw new AyniException('duplicate', 409, null, [], ['fields' => ['tax_id']]);
        }
    }
}
