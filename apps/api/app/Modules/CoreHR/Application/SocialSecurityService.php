<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\CoreHR\Infrastructure\Models\HealthProvider;
use App\Modules\CoreHR\Infrastructure\Models\HealthRegime;
use App\Modules\CoreHR\Infrastructure\Models\InsuranceCoverage;
use App\Modules\CoreHR\Infrastructure\Models\InsurancePolicy;
use App\Modules\CoreHR\Infrastructure\Models\LifeInsuranceBeneficiary;
use App\Modules\CoreHR\Infrastructure\Models\PensionAffiliation;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Files\FileService;
use App\Shared\Files\Models\StoredFile;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * COL-7 · Régimen pensionario, salud y seguros del trabajador.
 *
 * - Previsión y salud se guardan con vigencia (HIS-1): un cambio de AFP o de tipo de comisión cierra la
 *   fila vigente el día anterior e inserta otra desde la fecha efectiva; nunca se sobrescribe.
 * - Las tasas (aporte, comisión, prima, tope asegurable) no viven aquí: son parámetros legales y
 *   pension_fund_rates por administradora; esta historia solo dice a qué sistema aporta y desde cuándo.
 * - SCTR y Vida Ley: pólizas del empleador por empresa y la cobertura de cada trabajador con su periodo.
 * - La declaración de beneficiarios de Vida Ley se archiva firmada; una nueva deja la anterior reemplazada.
 */
final class SocialSecurityService
{
    public const PENSION_SYSTEMS = ['afp', 'onp', 'none', 'other'];

    public const COMMISSION_SCHEMES = ['flow', 'mixed'];

    public const HEALTH_SCHEMES = ['essalud', 'eps', 'sis', 'other'];

    public const POLICY_KINDS = ['life', 'risk_health', 'risk_pension', 'health_plan', 'other'];

    public const DECLARATION_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private readonly EffectiveDated $validity,
        private readonly PaymentFormats $formats,
        private readonly FileService $files,
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
    ) {}

    // ── Previsión ────────────────────────────────────────────────────────────────

    /**
     * @return list<PensionAffiliation>
     */
    public function pensionHistory(Employment $employment): array
    {
        return array_values(PensionAffiliation::query()->where('employment_id', $employment->id)->orderByDesc('valid_from')->get()->all());
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function changePension(Employment $employment, array $input): PensionAffiliation
    {
        $from = $this->effectiveFrom($employment, (string) $input['valid_from']);
        $system = (string) $input['pension_system'];
        $country = $this->country($employment);
        $fund = isset($input['pension_fund_code']) && is_string($input['pension_fund_code']) && $input['pension_fund_code'] !== '' ? $input['pension_fund_code'] : null;
        $scheme = isset($input['commission_scheme']) && is_string($input['commission_scheme']) ? $input['commission_scheme'] : null;
        $errors = [];
        if ($system === 'afp') {
            if ($fund === null || ! $this->fundExists($fund, 'private', $country)) {
                $errors['pension_fund_code'] = [(string) trans('people.pension.fund_required')];
            }
            if ($scheme === null) {
                $errors['commission_scheme'] = [(string) trans('people.pension.scheme_required')];
            }
        } else {
            if ($scheme !== null) {
                $errors['commission_scheme'] = [(string) trans('people.pension.scheme_only_afp')];
            }
            if ($system === 'onp') {
                $fund ??= DB::table('pension_funds')->where('country_code', $country)->where('kind', 'public')->where('status', 'active')->value('code');
                if (! is_string($fund) || ! $this->fundExists($fund, 'public', $country)) {
                    $errors['pension_fund_code'] = [(string) trans('people.pension.fund_required')];
                }
            } elseif ($fund !== null) {
                $errors['pension_fund_code'] = [(string) trans('people.pension.fund_not_applicable')];
            }
        }
        $code = null;
        if (isset($input['affiliate_code']) && is_string($input['affiliate_code']) && trim($input['affiliate_code']) !== '') {
            if ($system !== 'afp') {
                $errors['affiliate_code'] = [(string) trans('people.pension.affiliate_only_afp')];
            } else {
                $code = $this->formats->affiliateCode($input['affiliate_code'], $country);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $current = $this->currentPension($employment, $from);
        $before = $current === null ? null : $this->pensionAuditable($current);

        return DB::transaction(function () use ($employment, $from, $system, $fund, $scheme, $code, $input, $current, $before): PensionAffiliation {
            // Sin código nuevo se conserva el registrado (el CUSPP no cambia al cambiar de AFP).
            $affiliateEnc = $code !== null ? FieldCipher::encrypt($code) : ($system === 'afp' ? $current?->affiliate_code_enc : null);
            $id = $this->validity->change('employment_pension_affiliations', $this->relation($employment), [
                'pension_system' => $system,
                'pension_fund_code' => $fund,
                'commission_scheme' => $system === 'afp' ? $scheme : null,
                'affiliate_code_enc' => $affiliateEnc,
                'affiliated_on' => isset($input['affiliated_on']) && is_string($input['affiliated_on']) ? $input['affiliated_on'] : $current?->affiliated_on?->toDateString(),
            ], $from);
            /** @var PensionAffiliation $row */
            $row = PensionAffiliation::query()->findOrFail($id);
            $this->outbox->record(DomainEventTypes::PENSION_AFFILIATION_CHANGED, 'employment', $employment->id, [
                'employment_id' => $employment->id, 'pension_system' => $system, 'pension_fund_code' => $fund,
                'commission_scheme' => $row->commission_scheme, 'effective_from' => $from,
            ]);
            $this->audit->record('pension_affiliation.changed', 'pension_affiliation', $row->id, $before, $this->pensionAuditable($row), isset($input['reason']) ? (string) $input['reason'] : null);

            return $row;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPension(PensionAffiliation $p): array
    {
        $code = $p->affiliate_code_enc === null ? null : FieldCipher::decrypt($p->affiliate_code_enc);
        $row = [
            'id' => $p->id,
            'employment_id' => $p->employment_id,
            'pension_system' => $p->pension_system,
            'pension_fund_code' => $p->pension_fund_code,
            'pension_fund_name' => $p->pension_fund_code === null ? null : DB::table('pension_funds')->where('code', $p->pension_fund_code)->value('name'),
            'commission_scheme' => $p->commission_scheme,
            'affiliate_code' => $code,
            'affiliate_code_masked' => $code === null ? null : PaymentFormats::mask($code),
            'affiliated_on' => $p->affiliated_on?->toDateString(),
            'valid_from' => $p->valid_from->toDateString(),
            'valid_to' => $p->valid_to?->toDateString(),
            'created_at' => $p->created_at->toIso8601String(),
        ];
        if ($code === null) {
            unset($row['affiliate_code']);
        }

        return $row;
    }

    // ── Salud ───────────────────────────────────────────────────────────────────

    /**
     * @return list<HealthRegime>
     */
    public function healthHistory(Employment $employment): array
    {
        return array_values(HealthRegime::query()->where('employment_id', $employment->id)->orderByDesc('valid_from')->get()->all());
    }

    /**
     * Cambio de EsSalud a EPS (con plan) o al revés, con fecha efectiva. El régimen laboral de la fila vigente
     * se conserva: este cambio solo toca la salud.
     *
     * @param  array<string, mixed>  $input
     */
    public function changeHealth(Employment $employment, array $input): HealthRegime
    {
        $from = $this->effectiveFrom($employment, (string) $input['valid_from']);
        $scheme = (string) $input['health_scheme'];
        $provider = isset($input['health_provider_id']) && is_string($input['health_provider_id']) ? $input['health_provider_id'] : null;
        $plan = isset($input['health_plan_code']) && is_string($input['health_plan_code']) && trim($input['health_plan_code']) !== '' ? trim($input['health_plan_code']) : null;
        if ($scheme === 'eps') {
            $errors = [];
            if ($provider === null || ! HealthProvider::query()->whereKey($provider)->where('kind', 'eps')->exists()) {
                $errors['health_provider_id'] = [(string) trans('people.health.provider_required')];
            }
            if ($plan === null) {
                $errors['health_plan_code'] = [(string) trans('people.health.plan_required')];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        } elseif ($provider !== null || $plan !== null) {
            throw ValidationException::withMessages(['health_provider_id' => [(string) trans('people.health.provider_only_eps')]]);
        }
        /** @var HealthRegime|null $current */
        $current = HealthRegime::query()->where('employment_id', $employment->id)->where('valid_from', '<=', $from)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from))->first();
        $before = $current === null ? null : $this->healthAuditable($current);

        return DB::transaction(function () use ($employment, $from, $scheme, $provider, $plan, $current, $before, $input): HealthRegime {
            $id = $this->validity->change('employment_labor_regimes', $this->relation($employment), [
                'labor_regime_code' => $current?->labor_regime_code,
                'has_risk_insurance' => $current->has_risk_insurance ?? false,
                'has_family_allowance' => $current->has_family_allowance ?? false,
                'health_scheme' => $scheme,
                'health_provider_id' => $scheme === 'eps' ? $provider : null,
                'health_plan_code' => $scheme === 'eps' ? $plan : null,
            ], $from);
            /** @var HealthRegime $row */
            $row = HealthRegime::query()->findOrFail($id);
            $this->outbox->record(DomainEventTypes::HEALTH_REGIME_CHANGED, 'employment', $employment->id, [
                'employment_id' => $employment->id, 'health_scheme' => $scheme, 'health_provider_id' => $row->health_provider_id, 'effective_from' => $from,
            ]);
            $this->audit->record('health_regime.changed', 'employment', $employment->id, $before, $this->healthAuditable($row), isset($input['reason']) ? (string) $input['reason'] : null);

            return $row;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function presentHealth(HealthRegime $h): array
    {
        return [
            'id' => $h->id,
            'employment_id' => $h->employment_id,
            'labor_regime_code' => $h->labor_regime_code,
            'health_scheme' => $h->health_scheme,
            'health_provider_id' => $h->health_provider_id,
            'health_provider_name' => $h->health_provider_id === null ? null : HealthProvider::query()->whereKey($h->health_provider_id)->value('name'),
            'health_plan_code' => $h->health_plan_code,
            'valid_from' => $h->valid_from->toDateString(),
            'valid_to' => $h->valid_to?->toDateString(),
        ];
    }

    // ── Pólizas del empleador y coberturas (SCTR, Vida Ley) ─────────────────────

    /**
     * @param  array<string, mixed>  $input
     */
    public function createPolicy(array $input): InsurancePolicy
    {
        $this->assertPolicyProvider($input);

        return DB::transaction(function () use ($input): InsurancePolicy {
            $policy = InsurancePolicy::create([
                'legal_entity_id' => $input['legal_entity_id'], 'kind' => $input['kind'], 'insurer' => trim((string) $input['insurer']),
                'policy_number' => trim((string) $input['policy_number']), 'starts_on' => $input['starts_on'], 'ends_on' => $input['ends_on'] ?? null,
                'registered_with_authority_on' => $input['registered_with_authority_on'] ?? null, 'health_provider_id' => $input['health_provider_id'] ?? null,
                'status' => 'active',
            ]);
            $this->audit->record('insurance_policy.created', 'insurance_policy', $policy->id, null, $this->policyAuditable($policy));

            return $policy->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updatePolicy(InsurancePolicy $policy, array $input): InsurancePolicy
    {
        $this->assertPolicyProvider($input + ['kind' => $policy->kind]);
        $before = $this->policyAuditable($policy);

        return DB::transaction(function () use ($policy, $input, $before): InsurancePolicy {
            $policy->fill(array_intersect_key($input, array_flip(['insurer', 'policy_number', 'ends_on', 'registered_with_authority_on', 'health_provider_id', 'status'])));
            $policy->lock_version++;
            $policy->save();
            $this->audit->record('insurance_policy.updated', 'insurance_policy', $policy->id, $before, $this->policyAuditable($policy));

            return $policy->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPolicy(InsurancePolicy $p): array
    {
        return [
            'id' => $p->id, 'legal_entity_id' => $p->legal_entity_id,
            'legal_entity_name' => DB::table('legal_entities')->where('id', $p->legal_entity_id)->value('legal_name'),
            'kind' => $p->kind, 'insurer' => $p->insurer, 'policy_number' => $p->policy_number,
            'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on?->toDateString(),
            'registered_with_authority_on' => $p->registered_with_authority_on?->toDateString(),
            'health_provider_id' => $p->health_provider_id, 'status' => $p->status,
            'members_count' => InsuranceCoverage::query()->where('insurance_policy_id', $p->id)->whereNull('covered_to')->count(),
            'version' => $p->lock_version, 'created_at' => $p->created_at->toIso8601String(), 'updated_at' => $p->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return list<InsuranceCoverage>
     */
    public function coverages(Employment $employment): array
    {
        return array_values(InsuranceCoverage::query()->where('employment_id', $employment->id)->orderByDesc('covered_from')->get()->all());
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function enroll(Employment $employment, array $input): InsuranceCoverage
    {
        /** @var InsurancePolicy|null $policy */
        $policy = InsurancePolicy::query()->whereKey((string) $input['insurance_policy_id'])->first();
        if ($policy === null || $policy->legal_entity_id !== $employment->legal_entity_id) {
            throw ValidationException::withMessages(['insurance_policy_id' => [(string) trans('people.insurance.policy_other_entity')]]);
        }
        if ($policy->status !== 'active') {
            throw ValidationException::withMessages(['insurance_policy_id' => [(string) trans('people.insurance.policy_inactive')]]);
        }
        $from = (string) $input['covered_from'];
        if ($from < $policy->starts_on->toDateString() || ($policy->ends_on !== null && $from > $policy->ends_on->toDateString())) {
            throw ValidationException::withMessages(['covered_from' => [(string) trans('people.insurance.outside_policy')]]);
        }
        if ($from < $employment->hire_date->toDateString()) {
            throw ValidationException::withMessages(['covered_from' => [(string) trans('people.before_hire')]]);
        }

        return DB::transaction(function () use ($employment, $policy, $from, $input): InsuranceCoverage {
            $open = InsuranceCoverage::query()->where('employment_id', $employment->id)->where('insurance_policy_id', $policy->id)
                ->where(fn ($q) => $q->whereNull('covered_to')->orWhere('covered_to', '>=', $from))->lockForUpdate()->exists();
            if ($open) {
                throw new AyniException('overlap', 409, (string) trans('people.insurance.already_covered'));
            }
            $coverage = InsuranceCoverage::create([
                'employment_id' => $employment->id, 'insurance_policy_id' => $policy->id, 'covered_from' => $from,
                'plan_code' => isset($input['plan_code']) && is_string($input['plan_code']) ? $input['plan_code'] : null,
            ]);
            $this->audit->record('insurance_coverage.enrolled', 'employment', $employment->id, null, ['insurance_policy_id' => $policy->id, 'kind' => $policy->kind, 'covered_from' => $from]);

            return $coverage->refresh();
        });
    }

    public function endCoverage(Employment $employment, InsuranceCoverage $coverage, string $to): InsuranceCoverage
    {
        if ($coverage->covered_to !== null) {
            throw new InvalidTransitionException('ended', []);
        }
        if ($to < $coverage->covered_from->toDateString()) {
            throw ValidationException::withMessages(['covered_to' => [(string) trans('people.insurance.end_before_start')]]);
        }

        return DB::transaction(function () use ($employment, $coverage, $to): InsuranceCoverage {
            $coverage->forceFill(['covered_to' => $to])->save();
            $this->audit->record('insurance_coverage.ended', 'employment', $employment->id, ['insurance_policy_id' => $coverage->insurance_policy_id, 'covered_to' => null], ['insurance_policy_id' => $coverage->insurance_policy_id, 'covered_to' => $to]);

            return $coverage->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function presentCoverage(InsuranceCoverage $c): array
    {
        $policy = InsurancePolicy::query()->withTrashed()->whereKey($c->insurance_policy_id)->first(['kind', 'insurer', 'policy_number']);

        return [
            'id' => $c->id, 'employment_id' => $c->employment_id, 'insurance_policy_id' => $c->insurance_policy_id,
            'kind' => $policy?->kind, 'insurer' => $policy?->insurer, 'policy_number' => $policy?->policy_number,
            'covered_from' => $c->covered_from->toDateString(), 'covered_to' => $c->covered_to?->toDateString(),
            'plan_code' => $c->plan_code, 'is_current' => $c->covered_to === null || $c->covered_to->toDateString() >= now()->toDateString(),
        ];
    }

    // ── Beneficiarios de Vida Ley ───────────────────────────────────────────────

    /**
     * Declaraciones del trabajador, la vigente primero.
     *
     * @return list<array<string, mixed>>
     */
    public function declarations(Employment $employment): array
    {
        $rows = LifeInsuranceBeneficiary::query()->where('employment_id', $employment->id)->orderByDesc('declared_on')->orderByDesc('created_at')->get();
        $out = [];
        foreach ($rows->groupBy('declaration_id') as $declarationId => $group) {
            /** @var LifeInsuranceBeneficiary $first */
            $first = $group->first();
            $out[] = [
                'id' => (string) $declarationId,
                'employment_id' => $employment->id,
                'status' => $first->status,
                'declared_on' => $first->declared_on?->toDateString(),
                'signed_on' => $first->signed_on?->toDateString(),
                'has_signed_file' => $first->declaration_file_id !== null,
                'beneficiaries' => $group->map(fn (LifeInsuranceBeneficiary $b): array => [
                    'full_name' => FieldCipher::decrypt($b->full_name_enc),
                    'document_number_masked' => $b->document_number_enc === null ? null : IdentityDocuments::mask(FieldCipher::decrypt($b->document_number_enc)),
                    'relationship' => $b->relationship,
                    'percentage' => $b->percentage,
                ])->values()->all(),
                'created_at' => $first->created_at->toIso8601String(),
            ];
        }
        usort($out, fn (array $a, array $b): int => ($a['status'] === 'current' ? 0 : 1) <=> ($b['status'] === 'current' ? 0 : 1));

        return $out;
    }

    /**
     * Archiva una declaración firmada: sus beneficiarios suman 100 %, el archivo firmado es obligatorio y la
     * declaración anterior queda reemplazada (no se borra).
     *
     * @param  list<array<string, mixed>>  $beneficiaries
     */
    public function declare(Employment $employment, array $beneficiaries, string $signedOn, string $contents, string $name, string $mime, ?string $userId): string
    {
        if (! in_array($mime, self::DECLARATION_MIMES, true)) {
            throw ValidationException::withMessages(['file' => [(string) trans('people.dependents.proof_type')]]);
        }
        $total = 0;
        foreach ($beneficiaries as $b) {
            $total += (int) round(((float) $b['percentage']) * 100);
        }
        if ($total !== 10000) {
            throw ValidationException::withMessages(['beneficiaries' => [(string) trans('people.insurance.percentages_total')]]);
        }
        $lifeCoverage = InsuranceCoverage::query()->join('insurance_policies as p', 'p.id', '=', 'insurance_policy_members.insurance_policy_id')
            ->where('insurance_policy_members.employment_id', $employment->id)->where('p.kind', 'life')
            ->where(fn ($q) => $q->whereNull('insurance_policy_members.covered_to')->orWhere('insurance_policy_members.covered_to', '>=', $signedOn))
            ->value('insurance_policy_members.id');

        return DB::transaction(function () use ($employment, $beneficiaries, $signedOn, $contents, $name, $mime, $userId, $lifeCoverage): string {
            $file = $this->files->upload($contents, $name, $mime, $employment->tenant_id, $userId, StoredFile::CLASSIFICATION_CONFIDENTIAL);
            LifeInsuranceBeneficiary::query()->where('employment_id', $employment->id)->where('status', 'current')->update(['status' => 'superseded']);
            $declarationId = (string) Str::uuid();
            foreach ($beneficiaries as $b) {
                $doc = isset($b['document_number']) && is_string($b['document_number']) && trim($b['document_number']) !== '' ? IdentityDocuments::normalize($b['document_number']) : null;
                LifeInsuranceBeneficiary::create([
                    'employment_id' => $employment->id, 'insurance_policy_member_id' => $lifeCoverage === null ? null : (string) $lifeCoverage,
                    'full_name_enc' => FieldCipher::encrypt(trim((string) $b['full_name'])),
                    'document_number_enc' => $doc === null ? null : FieldCipher::encrypt($doc),
                    'relationship' => (string) $b['relationship'], 'percentage' => number_format((float) $b['percentage'], 2, '.', ''),
                    'declared_on' => now()->toDateString(), 'signed_on' => $signedOn, 'declaration_id' => $declarationId,
                    'declaration_file_id' => $file->id, 'status' => 'current',
                ]);
            }
            $this->audit->record('life_insurance.declared', 'employment', $employment->id, null, [
                'declaration_id' => $declarationId, 'beneficiaries' => count($beneficiaries), 'signed_on' => $signedOn, 'file_id' => $file->id,
            ]);

            return $declarationId;
        });
    }

    /**
     * @return array{name: string, mime: string, contents: string}|null
     */
    public function declarationFile(Employment $employment, string $declarationId, ?string $userId): ?array
    {
        $fileId = LifeInsuranceBeneficiary::query()->where('employment_id', $employment->id)->where('declaration_id', $declarationId)->value('declaration_file_id');

        return $fileId === null ? null : $this->files->contentsForDownload((string) $fileId, $employment->tenant_id, $userId);
    }

    // ── Apoyo ───────────────────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function relation(Employment $employment): array
    {
        return ['tenant_id' => TenancyScope::requireTenant(), 'employment_id' => $employment->id];
    }

    private function effectiveFrom(Employment $employment, string $from): string
    {
        $day = EffectiveDated::day($from);
        if ($day < $employment->hire_date->toDateString()) {
            throw ValidationException::withMessages(['valid_from' => [(string) trans('people.before_hire')]]);
        }

        return $day;
    }

    private function country(Employment $employment): string
    {
        return (string) (DB::table('legal_entities')->where('id', $employment->legal_entity_id)->value('country_code') ?? 'PE');
    }

    private function fundExists(string $code, string $kind, string $country): bool
    {
        return DB::table('pension_funds')->where('code', $code)->where('kind', $kind)->where('country_code', $country)->where('status', 'active')->exists();
    }

    private function currentPension(Employment $employment, string $day): ?PensionAffiliation
    {
        /** @var PensionAffiliation|null */
        return PensionAffiliation::query()->where('employment_id', $employment->id)->where('valid_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $day))->first();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertPolicyProvider(array $input): void
    {
        $provider = $input['health_provider_id'] ?? null;
        if ($provider !== null && ! HealthProvider::query()->whereKey((string) $provider)->exists()) {
            throw ValidationException::withMessages(['health_provider_id' => [(string) trans('people.health.provider_required')]]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pensionAuditable(PensionAffiliation $p): array
    {
        return [
            'pension_system' => $p->pension_system, 'pension_fund_code' => $p->pension_fund_code, 'commission_scheme' => $p->commission_scheme,
            'affiliate_code' => $p->affiliate_code_enc === null ? null : FieldCipher::decrypt($p->affiliate_code_enc),
            'valid_from' => $p->valid_from->toDateString(), 'valid_to' => $p->valid_to?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function healthAuditable(HealthRegime $h): array
    {
        return [
            'health_scheme' => $h->health_scheme, 'health_provider_id' => $h->health_provider_id, 'health_plan_code' => $h->health_plan_code,
            'valid_from' => $h->valid_from->toDateString(), 'valid_to' => $h->valid_to?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function policyAuditable(InsurancePolicy $p): array
    {
        return [
            'legal_entity_id' => $p->legal_entity_id, 'kind' => $p->kind, 'insurer' => $p->insurer, 'policy_number' => $p->policy_number,
            'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on?->toDateString(), 'status' => $p->status,
        ];
    }
}
