<?php

namespace App\Modules\Organization\Infrastructure\Imports;

use App\Modules\Organization\Application\LegalEntityService;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\CustomFields\HasCustomFields;
use App\Shared\Imports\ImportColumn;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\Importer;
use App\Shared\Imports\RowCheck;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IMP-1 · Importador de empresas (razones sociales). Cada alta pasa por LegalEntityService::create,
 * igual que el alta manual: país, moneda, régimen, formato y unicidad del RUC y límite del plan
 * (RN-IMP-06). País, moneda y zona horaria toman los de la organización si vienen vacíos; el
 * régimen acepta el código (pe_general) o el nombre («Régimen general»).
 */
final class LegalEntitiesImporter implements HasCustomFields, Importer
{
    /** Tablas que, si apuntan a la empresa, impiden deshacer su importación (RN-IMP-07). */
    private const DEPENDENTS = ['employments', 'sites', 'org_units', 'positions', 'contracts', 'payroll_periods'];

    public function __construct(private readonly LegalEntityService $entities) {}

    /** CFG-5 · Sus columnas custom.clave las maneja el tratamiento común (CustomFieldsImporter). */
    public function customFieldEntity(): string
    {
        return 'legal_entity';
    }

    public function entity(): string
    {
        return 'legal_entities';
    }

    public function permission(): string
    {
        return 'org.legal_entities.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('name', true, 'Andina Servicios S.A.C.', ['razon_social', 'legal_name', 'empresa']),
            new ImportColumn('tax_id', true, '20123456789', ['ruc', 'nif', 'cnpj', 'numero_fiscal']),
            new ImportColumn('trade_name', false, 'Andina', ['nombre_comercial']),
            new ImportColumn('payroll_regime', true, 'pe_general', ['regimen', 'regimen_laboral']),
            new ImportColumn('country', false, 'PE', ['pais']),
            new ImportColumn('currency', false, 'PEN', ['moneda']),
            new ImportColumn('timezone', false, 'America/Lima', ['zona_horaria']),
        ];
    }

    public function naturalKey(array $data): ?string
    {
        $tax = preg_replace('/\s+/', '', $data['tax_id'] ?? '');

        return $tax === '' ? null : strtoupper(($data['country'] ?? '') ?: 'default').':'.$tax;
    }

    public function validate(array $data, ImportContext $context): RowCheck
    {
        $tenant = $this->tenantDefaults($context);
        $country = strtoupper(($data['country'] ?? '') ?: $tenant['country']);
        $parsed = [
            'name' => $data['name'] ?? '',
            'trade_name' => ($data['trade_name'] ?? '') === '' ? null : $data['trade_name'],
            'tax_id' => (string) preg_replace('/\s+/', '', $data['tax_id'] ?? ''),
            'country' => $country,
            'currency' => strtoupper(($data['currency'] ?? '') ?: $tenant['currency']),
            'timezone' => ($data['timezone'] ?? '') ?: $tenant['timezone'],
            'payroll_regime' => $this->regimeCode($data['payroll_regime'] ?? '', $country),
        ];
        $check = new RowCheck($parsed);

        $length = mb_strlen($parsed['name']);
        if ($length < 2 || $length > 200) {
            $check->error('name', 'invalid', (string) trans('organization.name_length', [], $context->locale));
        }
        if (! in_array($parsed['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $check->error('timezone', 'invalid', (string) trans('organization.invalid_timezone', [], $context->locale));
        }
        foreach ($this->entities->creationErrors($parsed) as $field => $messages) {
            foreach ($messages as $message) {
                $check->error($field === 'payroll_regime' || $field === 'country' || $field === 'currency' || $field === 'tax_id' ? $field : null, 'invalid', $message);
            }
        }

        // Límite del plan: las filas válidas de esta pasada no pueden superar el cupo restante.
        if ($check->ok()) {
            $remaining = $context->counters['legal_entities.remaining'] ??= ($this->entities->remainingSlots() ?? -1);
            if ($remaining >= 0) {
                $used = ($context->counters['legal_entities.used'] ?? 0) + 1;
                if ($used > $remaining) {
                    $check->error(null, 'plan_limit', (string) trans('organization.plan_limit', [], $context->locale));
                } else {
                    $context->counters['legal_entities.used'] = $used;
                }
            }
        }

        return $check;
    }

    public function import(array $parsed, ImportContext $context): array
    {
        /** @var array{name: string, trade_name?: string|null, tax_id: string, country: string, currency: string, timezone: string, payroll_regime: string} $input */
        $input = $parsed;
        $entity = $this->entities->create($input);

        return ['entity_id' => $entity->id, 'previous' => null];
    }

    public function blockingReason(string $entityId, ImportContext $context): ?string
    {
        /** @var LegalEntity|null $entity */
        $entity = LegalEntity::query()->whereKey($entityId)->first();
        if ($entity === null) {
            return null;
        }
        $used = [];
        foreach (self::DEPENDENTS as $table) {
            if (Schema::hasColumn($table, 'legal_entity_id') && DB::table($table)->where('legal_entity_id', $entityId)->exists()) {
                $used[] = $table;
            }
        }
        if ($used !== []) {
            return (string) trans('organization.in_use', ['what' => implode(', ', $used)], $context->locale);
        }
        if ($entity->status !== 'active' || ($entity->lock_version ?? 1) > 1) {
            return (string) trans('organization.changed_after_import', [], $context->locale);
        }

        return null;
    }

    public function revert(string $entityId, ?array $previous, ImportContext $context): void
    {
        /** @var LegalEntity|null $entity */
        $entity = LegalEntity::query()->whereKey($entityId)->first();
        if ($entity !== null) {
            $this->entities->removeImported($entity, $context->jobId);
        }
    }

    /**
     * @return array{country: string, currency: string, timezone: string}
     */
    private function tenantDefaults(ImportContext $context): array
    {
        static $cache = [];
        $id = TenancyScope::current() ?? $context->tenantId;
        if (! isset($cache[$id])) {
            $row = DB::table('current_tenant')->first(['country_code', 'default_currency', 'timezone']);
            $cache[$id] = [
                'country' => (string) ($row->country_code ?? ''),
                'currency' => (string) ($row->default_currency ?? ''),
                'timezone' => (string) ($row->timezone ?? ''),
            ];
        }

        return $cache[$id];
    }

    private function regimeCode(string $value, string $country): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $code = DB::table('labor_regimes')->where('country_code', $country)
            ->where(fn ($q) => $q->where('code', $value)->orWhereRaw('lower(name) = lower(?)', [$value]))->value('code');

        return is_string($code) ? $code : $value;
    }
}
