<?php

namespace App\Modules\Documents\Application;

use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * DOC-4 · Catálogo de variables seguras de las plantillas y su resolución con los datos vigentes de una relación
 * laboral (ficha, empresa, contrato y compensación). Solo existen estas variables: una plantilla no puede pedir
 * otro dato.
 *
 * RN-DOC-10 · Las variables sensibles (número de documento, sueldo) exigen el permiso del campo a quien genera o
 * previsualiza; sin él no se resuelven (y la generación se rechaza). Cada lectura de un dato sensible se registra.
 */
final class TemplateVariables
{
    /**
     * Variable => [grupo, permiso de campo o null, categoría del registro de accesos, ejemplo].
     *
     * @var array<string, array{0: string, 1: string|null, 2: string, 3: string}>
     */
    public const CATALOG = [
        'empleado.nombre_completo' => ['empleado', null, '', 'Ana María Quispe Rojas'],
        'empleado.nombres' => ['empleado', null, '', 'Ana María'],
        'empleado.apellidos' => ['empleado', null, '', 'Quispe Rojas'],
        'empleado.documento_tipo' => ['empleado', null, '', 'DNI'],
        'empleado.documento_numero' => ['empleado', 'people.people.personal_data.view', 'identity_document', '45678912'],
        'empleado.codigo' => ['empleado', null, '', 'E-0012'],
        'empleado.cargo' => ['empleado', null, '', 'Analista Contable'],
        'empleado.area' => ['empleado', null, '', 'Finanzas'],
        'empleado.sede' => ['empleado', null, '', 'Sede Ate'],
        'empleado.modalidad_trabajo' => ['empleado', null, '', 'Presencial'],
        'empleado.fecha_ingreso' => ['empleado', null, '', '01/03/2022'],
        'empleado.fecha_cese' => ['empleado', null, '', '31/10/2026'],
        'empleado.correo_trabajo' => ['empleado', null, '', 'ana.quispe@empresa.pe'],
        'empresa.razon_social' => ['empresa', null, '', 'Comercial Los Andes S.A.C.'],
        'empresa.nombre_comercial' => ['empresa', null, '', 'Los Andes'],
        'empresa.ruc' => ['empresa', null, '', '20999999901'],
        'empresa.direccion' => ['empresa', null, '', 'Av. Nicolás Ayllón 4520, Ate, Lima'],
        'empresa.representante_legal' => ['empresa', null, '', 'Carlos Mendoza Paredes'],
        'contrato.tipo' => ['contrato', null, '', 'Plazo fijo por inicio de actividad'],
        'contrato.numero' => ['contrato', null, '', 'C-2026-0042'],
        'contrato.inicio' => ['contrato', null, '', '01/03/2026'],
        'contrato.fin' => ['contrato', null, '', '28/02/2027'],
        'contrato.causa_objetiva' => ['contrato', null, '', 'Inicio de una nueva línea de negocio'],
        'contrato.jornada_semanal' => ['contrato', null, '', '48'],
        'contrato.fin_periodo_prueba' => ['contrato', null, '', '31/05/2026'],
        'compensacion.sueldo_basico' => ['compensacion', 'people.employments.salary.view', 'salary', 'PEN 3,500.00'],
        'compensacion.moneda' => ['compensacion', null, '', 'PEN'],
        'compensacion.periodicidad' => ['compensacion', null, '', 'Mensual'],
        'fecha.hoy' => ['fecha', null, '', '15/11/2026'],
        'fecha.hoy_texto' => ['fecha', null, '', '15 de noviembre de 2026'],
    ];

    /**
     * @return list<array{name: string, group: string, label: string, example: string, sensitive: bool, permission: string|null, allowed: bool}>
     */
    public function catalog(EffectivePermissions $permissions): array
    {
        $out = [];
        foreach (self::CATALOG as $name => [$group, $permission, , $example]) {
            $out[] = [
                'name' => $name, 'group' => $group, 'label' => (string) trans('documents.variables.'.str_replace('.', '_', $name)),
                'example' => $example, 'sensitive' => $permission !== null, 'permission' => $permission,
                'allowed' => $permission === null || $permissions->has($permission),
            ];
        }

        return $out;
    }

    /**
     * Regla 7 · La lectura de variables sensibles (vista previa o documento generado) queda en el registro de accesos.
     *
     * @param  list<string>  $categories
     * @param  list<string>  $fields
     */
    public function logRead(?string $userId, ?string $personId, string $entityType, string $entityId, array $categories, array $fields, ?string $ip, string $kind = 'view'): void
    {
        foreach ($categories as $category) {
            DB::table('data_access_logs')->insert([
                'tenant_id' => TenancyScope::requireTenant(), 'user_id' => $userId, 'access_kind' => $kind, 'data_category' => $category,
                'subject_person_id' => $personId, 'entity_type' => $entityType, 'entity_id' => $entityId,
                'fields' => '{'.implode(',', $fields).'}', 'purpose' => 'document_template', 'ip' => $ip, 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function unknown(array $names): array
    {
        return array_values(array_filter($names, fn (string $n): bool => ! array_key_exists($n, self::CATALOG)));
    }

    /**
     * Variables usadas que exigen un permiso de campo que no se tiene (RN-DOC-10).
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function forbidden(array $names, EffectivePermissions $permissions): array
    {
        return array_values(array_filter($names, function (string $n) use ($permissions): bool {
            $permission = self::CATALOG[$n][1] ?? null;

            return $permission !== null && ! $permissions->has($permission);
        }));
    }

    /**
     * Valores vigentes de las variables pedidas para la relación laboral. Las sensibles sin permiso quedan fuera.
     *
     * @param  list<string>  $names
     * @return array{values: array<string, string|null>, withheld: list<string>, sensitive: list<string>, person_id: string|null, sensitive_fields: list<string>}
     */
    public function resolve(string $employmentId, array $names, EffectivePermissions $permissions, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $today = CarbonImmutable::now();
        /** @var CarbonImmutable $local */
        $local = $today->locale($locale);
        $e = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->join('legal_entities as l', 'l.id', '=', 'e.legal_entity_id')
            ->where('e.id', $employmentId)->whereNull('e.deleted_at')
            ->first(['e.person_id', 'e.employee_code', 'e.hire_date', 'e.termination_date', 'e.work_email', 'p.first_names', 'p.paternal_surname',
                'p.maternal_surname', 'l.legal_name', 'l.trade_name', 'l.tax_id', 'l.address', 'l.legal_representative_name']);
        if ($e === null) {
            return ['values' => [], 'withheld' => [], 'sensitive' => [], 'person_id' => null, 'sensitive_fields' => []];
        }
        $groups = array_unique(array_map(fn (string $n): string => explode('.', $n)[0], $names));
        $date = fn ($d): ?string => $d === null ? null : CarbonImmutable::parse((string) $d)->format('d/m/Y');
        $values = [
            'empleado.nombre_completo' => trim($e->first_names.' '.$e->paternal_surname.' '.($e->maternal_surname ?? '')),
            'empleado.nombres' => (string) $e->first_names,
            'empleado.apellidos' => trim($e->paternal_surname.' '.($e->maternal_surname ?? '')),
            'empleado.codigo' => (string) $e->employee_code,
            'empleado.fecha_ingreso' => $date($e->hire_date),
            'empleado.fecha_cese' => $date($e->termination_date),
            'empleado.correo_trabajo' => $e->work_email === null ? null : (string) $e->work_email,
            'empresa.razon_social' => (string) $e->legal_name,
            'empresa.nombre_comercial' => $e->trade_name === null ? null : (string) $e->trade_name,
            'empresa.ruc' => (string) $e->tax_id,
            'empresa.direccion' => $e->address === null ? null : (string) $e->address,
            'empresa.representante_legal' => $e->legal_representative_name === null ? null : (string) $e->legal_representative_name,
            'fecha.hoy' => $today->format('d/m/Y'),
            'fecha.hoy_texto' => $local->translatedFormat((string) trans('documents.templates.long_date')),
        ];
        if (in_array('empleado.documento_tipo', $names, true) || in_array('empleado.documento_numero', $names, true)) {
            $doc = DB::table('person_documents')->where('person_id', $e->person_id)->whereNull('deleted_at')->orderByDesc('is_primary')->first(['doc_type', 'number_enc']);
            $values['empleado.documento_tipo'] = $doc === null ? null : strtoupper((string) $doc->doc_type);
            $values['empleado.documento_numero'] = $doc === null ? null : FieldCipher::decrypt((string) $doc->number_enc);
        }
        if (array_intersect(['empleado.cargo', 'empleado.area', 'empleado.sede', 'empleado.modalidad_trabajo'], $names) !== []) {
            $a = DB::table('employment_assignments as a')->leftJoin('job_profiles as j', 'j.id', '=', 'a.job_profile_id')
                ->leftJoin('org_units as u', 'u.id', '=', 'a.org_unit_id')->leftJoin('sites as s', 's.id', '=', 'a.site_id')
                ->where('a.employment_id', $employmentId)->where('a.valid_from', '<=', $today->toDateString())
                ->where(fn ($q) => $q->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $today->toDateString()))
                ->orderByDesc('a.valid_from')->first(['j.name as job', 'u.name as unit', 's.name as site', 'a.work_modality']);
            $values['empleado.cargo'] = $a?->job;
            $values['empleado.area'] = $a?->unit;
            $values['empleado.sede'] = $a?->site;
            $values['empleado.modalidad_trabajo'] = $a === null ? null : (string) trans('documents.templates.modality_'.$a->work_modality);
        }
        if (in_array('contrato', $groups, true)) {
            $c = DB::table('contracts as c')->join('contract_types as t', 't.id', '=', 'c.contract_type_id')
                ->where('c.employment_id', $employmentId)->whereNull('c.deleted_at')
                ->whereNotIn('c.status', ['draft', 'pending_approval', 'superseded', 'cancelled'])->where('c.valid_from', '<=', $today->toDateString())
                ->orderByDesc('c.valid_from')->first(['t.name', 'c.number', 'c.valid_from', 'c.valid_to', 'c.objective_cause', 'c.weekly_hours', 'c.trial_period_ends_on']);
            $values += [
                'contrato.tipo' => $c?->name, 'contrato.numero' => $c?->number, 'contrato.inicio' => $date($c?->valid_from),
                'contrato.fin' => $date($c?->valid_to), 'contrato.causa_objetiva' => $c?->objective_cause,
                'contrato.jornada_semanal' => $c?->weekly_hours === null ? null : rtrim(rtrim((string) $c->weekly_hours, '0'), '.'),
                'contrato.fin_periodo_prueba' => $date($c?->trial_period_ends_on),
            ];
        }
        if (in_array('compensacion', $groups, true)) {
            $m = DB::table('employment_compensations')->where('employment_id', $employmentId)->where('valid_from', '<=', $today->toDateString())
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today->toDateString()))
                ->orderByDesc('valid_from')->first(['base_salary', 'currency', 'pay_frequency']);
            $values += [
                'compensacion.sueldo_basico' => $m === null ? null : $m->currency.' '.self::amount((string) $m->base_salary),
                'compensacion.moneda' => $m?->currency,
                'compensacion.periodicidad' => $m === null ? null : (string) trans('documents.templates.frequency_'.$m->pay_frequency),
            ];
        }

        $out = [];
        $withheld = [];
        $sensitive = [];
        foreach ($names as $name) {
            if (! array_key_exists($name, self::CATALOG)) {
                continue;
            }
            [, $permission, $category] = self::CATALOG[$name];
            if ($permission !== null && ! $permissions->has($permission)) {
                $withheld[] = $name;

                continue;
            }
            $value = $values[$name] ?? null;
            $out[$name] = $value === null ? null : (string) $value;
            if ($permission !== null && $value !== null) {
                $sensitive[$name] = $category;
            }
        }

        return ['values' => $out, 'withheld' => $withheld, 'sensitive' => array_values(array_unique($sensitive)), 'person_id' => (string) $e->person_id,
            'sensitive_fields' => array_keys($sensitive)];
    }

    /** Decimal exacto con separador de miles, sin pasar por float: «3500.5» → «3,500.50». */
    private static function amount(string $decimal): string
    {
        [$int, $frac] = array_pad(explode('.', $decimal, 2), 2, '');
        $sign = str_starts_with($int, '-') ? '-' : '';
        $int = ltrim($int, '-');

        return $sign.strrev(implode(',', str_split(strrev($int === '' ? '0' : $int), 3))).'.'.str_pad(substr($frac, 0, 2), 2, '0');
    }
}
