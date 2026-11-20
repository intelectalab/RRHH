<?php

namespace App\Modules\Platform\Application\Web;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Application\PlatformSettings;
use App\Modules\Platform\Application\Subscriptions\SubscriptionService;
use App\Modules\Platform\Application\Tenants\TenantAdministration;
use App\Modules\Platform\Domain\Web\Ruc;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Tenancy\CrossTenantMaintenance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * WEB-5 · Registro de prueba desde el sitio en tres pasos (cuenta, empresa, plan).
 *
 *  - Cuenta: nombre y correo con consentimiento; el correo se confirma con un código de un solo uso (antiabuso).
 *    No se pide contraseña aquí: el administrador la crea desde la invitación de la organización (nunca en claro).
 *  - Empresa: RUC con dígito verificador, razón social, colaboradores, régimen y subdominio (RN-WEB-06: un RUC o un
 *    subdominio en uso se rechazan antes de crear nada).
 *  - Plan: plan público y complementos elegidos en /precios.
 *  - Completar: crea la organización con el mismo servicio que la consola (RN-WEB-05), la suscripción en prueba y
 *    sus módulos, y deja el lead convertido. Repetir el completar devuelve la misma organización.
 *
 * El registro a medias queda como lead «signup» con el paso alcanzado (RN-WEB-07) y se borra según la retención.
 * El acceso al registro es por un token aleatorio que solo vuelve al navegador; en base solo vive su hash.
 */
final class SiteSignups
{
    public const CODE_TTL_MINUTES = 15;

    public const MAX_CODE_ATTEMPTS = 5;

    private const SUBDOMAIN = '/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/';

    public function __construct(
        private readonly PublicFormGuard $guard,
        private readonly WebMessages $messages,
        private readonly TenantAdministration $tenants,
        private readonly SubscriptionService $subscriptions,
        private readonly PlatformSettings $settings,
        private readonly ConsoleAudit $audit,
        private readonly CrossTenantMaintenance $cross,
    ) {}

    /**
     * @return array{ruc: string, valid: bool, registered: bool}
     */
    public function checkRuc(string $ruc): array
    {
        $valid = Ruc::isValid($ruc);

        return ['ruc' => $ruc, 'valid' => $valid, 'registered' => $valid && $this->rucTaken($ruc)];
    }

    /**
     * @return array{slug: string, available: bool, reason: string|null}
     */
    public function checkSubdomain(string $slug): array
    {
        $slug = mb_strtolower($slug);
        $reason = $this->subdomainProblem($slug);

        return ['slug' => $slug, 'available' => $reason === null, 'reason' => $reason];
    }

    /**
     * Paso cuenta.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function start(array $input, ?string $ip, ?string $idempotencyKey): array
    {
        $this->guard->check($input, $ip);
        $email = mb_strtolower(trim((string) $input['email']));
        $token = Str::random(48);
        $code = $this->newCode();

        $existing = $idempotencyKey === null ? null : DB::table('web_signups')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            if ((string) $existing->email !== $email) {
                throw new AyniException('idempotency_conflict', 409, 'La clave de idempotencia ya fue utilizada con un cuerpo de petición diferente.');
            }
            // Repetir la petición no crea otro registro: renueva el token (solo se guarda su hash) del mismo.
            DB::table('web_signups')->where('id', $existing->id)->update(['token_hash' => $this->hash($token)]);

            return $this->present($this->row((string) $existing->id)) + ['token' => $token];
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $input, $email, $token, $code, $idempotencyKey): void {
            $leadId = (string) Str::uuid();
            DB::table('web_leads')->insert([
                'id' => $leadId, 'kind' => 'signup', 'full_name' => (string) $input['full_name'], 'email' => $email,
                'source_page' => $input['source_page'] ?? null, 'utm' => isset($input['utm']) ? json_encode($input['utm'], JSON_THROW_ON_ERROR) : null,
                'consent_purpose' => 'signup', 'consent_at' => now(),
            ]);
            DB::table('web_signups')->insert([
                'id' => $id, 'token_hash' => $this->hash($token), 'email' => $email, 'full_name' => (string) $input['full_name'],
                'step' => 'account', 'web_lead_id' => $leadId, 'locale' => $input['locale'] ?? 'es', 'idempotency_key' => $idempotencyKey,
                'verification_code_hash' => $this->hash($code), 'code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            ]);
            $this->queueCode($id, $email, (string) $input['full_name'], $input['locale'] ?? 'es', $code);
        });

        return $this->present($this->row($id)) + ['token' => $token];
    }

    /**
     * Guarda un paso: confirmar el código, pedir otro, empresa o plan.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $token, array $input): array
    {
        $signup = $this->byToken($token);
        if ($signup->step === 'done') {
            throw new AyniException('signup_done', 409, (string) trans('platform.web.signup_done'));
        }

        if (! empty($input['resend_code'])) {
            $code = $this->newCode();
            DB::transaction(function () use ($signup, $code): void {
                DB::table('web_signups')->where('id', $signup->id)->update([
                    'verification_code_hash' => $this->hash($code), 'code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES), 'code_attempts' => 0,
                ]);
                $this->queueCode((string) $signup->id, (string) $signup->email, (string) $signup->full_name, $signup->locale, $code);
            });

            return $this->present($this->row((string) $signup->id));
        }

        if (isset($input['verification_code'])) {
            $this->verify($signup, (string) $input['verification_code']);
            $signup = $this->row((string) $signup->id);
        }

        if (isset($input['company'])) {
            $this->requireVerified($signup);
            $company = (array) $input['company'];
            $ruc = (string) $company['ruc'];
            $slug = mb_strtolower((string) $company['subdomain']);
            $this->assertCompanyFree($ruc, $slug);
            DB::transaction(function () use ($signup, $company, $ruc, $slug): void {
                DB::table('web_signups')->where('id', $signup->id)->update([
                    'ruc' => $ruc, 'company_name' => (string) $company['company_name'], 'employees_range' => (string) $company['employees_range'],
                    'labor_regime' => (string) $company['labor_regime'], 'subdomain' => $slug,
                    'step' => in_array($signup->step, ['account', 'company'], true) ? 'plan' : $signup->step,
                ]);
                DB::table('web_leads')->where('id', $signup->web_lead_id)->update([
                    'ruc' => $ruc, 'company_name' => (string) $company['company_name'], 'employees_range' => (string) $company['employees_range'],
                ]);
            });
            $signup = $this->row((string) $signup->id);
        }

        if (isset($input['plan'])) {
            $this->requireVerified($signup);
            $plan = $this->publicPlan((string) $input['plan']);
            $addons = array_values(array_unique(array_map('strval', (array) ($input['addons'] ?? []))));
            $known = DB::table('addons')->where('status', 'active')->where('kind', 'module')->whereNull('deleted_at')->whereIn('code', $addons)->pluck('code')->all();
            if (count($known) !== count($addons)) {
                throw ValidationException::withMessages(['addons' => [(string) trans('platform.web.plan_unknown')]]);
            }
            DB::table('web_signups')->where('id', $signup->id)->update([
                'plan_id' => $plan->id, 'addon_codes' => '{'.implode(',', $addons).'}',
                'billing_cycle' => $input['billing_cycle'] ?? $plan->billing_cycle,
            ]);
        }

        return $this->present($this->row((string) $signup->id));
    }

    /**
     * Crea la organización. Solo prueba sin tarjeta: el pago se activa después desde la organización (decisión anotada).
     *
     * @return array<string, mixed>
     */
    public function complete(string $token, string $mode): array
    {
        $signup = $this->byToken($token);
        if ($signup->step === 'done' && $signup->tenant_id !== null) {
            return $this->present($signup);
        }
        if ($mode !== 'trial') {
            throw new BusinessRuleViolatedException('paid_signup_unavailable', (string) trans('platform.web.paid_unavailable'));
        }
        $this->requireVerified($signup);
        if ($signup->ruc === null || $signup->subdomain === null || $signup->company_name === null || $signup->labor_regime === null) {
            throw new BusinessRuleViolatedException('company_missing', (string) trans('platform.web.company_missing'));
        }
        if ($signup->plan_id === null) {
            throw new BusinessRuleViolatedException('plan_missing', (string) trans('platform.web.plan_missing'));
        }
        $this->assertCompanyFree((string) $signup->ruc, (string) $signup->subdomain);
        $plan = DB::table('plans')->where('id', $signup->plan_id)->first();
        if ($plan === null) {
            throw new BusinessRuleViolatedException('plan_unknown', (string) trans('platform.web.plan_unknown'));
        }
        $trialDays = $plan->trial_days !== null ? (int) $plan->trial_days : (int) $this->settings->value('web.signup_trial_days');
        $addonCodes = TenantAdministration::pgArray($signup->addon_codes);

        DB::transaction(function () use ($signup, $plan, $trialDays, $addonCodes): void {
            // Bloquea el registro para que dos «completar» simultáneos no creen dos organizaciones.
            $locked = DB::table('web_signups')->where('id', $signup->id)->lockForUpdate()->first();
            if ($locked === null || $locked->step === 'done') {
                return;
            }
            $tenant = $this->tenants->create([
                'name' => (string) $signup->company_name, 'slug' => (string) $signup->subdomain, 'country' => 'PE',
                'trial_ends_on' => now()->addDays($trialDays)->toDateString(),
                'contact_name' => (string) $signup->full_name, 'contact_email' => (string) $signup->email,
                'admin_name' => (string) $signup->full_name, 'admin_email' => (string) $signup->email,
                'legal_name' => (string) $signup->company_name, 'tax_id' => (string) $signup->ruc, 'payroll_regime' => (string) $signup->labor_regime,
                'locale' => $signup->locale ?? 'es',
            ], null);
            $subscription = $this->subscriptions->create(['tenant_id' => $tenant['id'], 'plan_id' => (string) $plan->id, 'billing_cycle' => (string) $signup->billing_cycle], null);
            foreach ($addonCodes as $code) {
                $addonId = DB::table('addons')->where('code', $code)->where('status', 'active')->whereNull('deleted_at')->value('id');
                if ($addonId !== null) {
                    $this->subscriptions->addAddon((string) $subscription['id'], (string) $addonId, 1, null);
                }
            }
            DB::table('web_signups')->where('id', $signup->id)->update(['step' => 'done', 'tenant_id' => $tenant['id'], 'completed_at' => now()]);
            DB::table('web_leads')->where('id', $signup->web_lead_id)->update(['status' => 'converted', 'tenant_id' => $tenant['id']]);
            $this->audit->record('web.signup_completed', null, 'web_signup', (string) $signup->id, null, [
                'tenant_id' => $tenant['id'], 'plan' => (string) $plan->code, 'addons' => $addonCodes,
            ], (string) $tenant['id']);
            $this->messages->queue('welcome', (string) $signup->email, $signup->locale, 'platform.web.mail.welcome_subject', 'platform.web.mail.welcome_body', [
                'name' => (string) $signup->company_name, 'admin' => (string) $signup->full_name, 'url' => $this->tenantUrl((string) $signup->subdomain),
            ], 'web_signup', (string) $signup->id);
        });

        return $this->present($this->row((string) $signup->id));
    }

    /**
     * RN-WEB-07 · Borra los registros a medias más viejos que la retención, con su lead «signup» si no avanzó a otro.
     */
    public function purgeAbandoned(): int
    {
        $before = now()->subDays((int) $this->settings->value('web.abandoned_signup_retention_days'));
        $rows = DB::table('web_signups')->where('step', '!=', 'done')->where('updated_at', '<', $before)->get(['id', 'web_lead_id']);
        foreach ($rows as $row) {
            DB::transaction(function () use ($row): void {
                DB::table('web_messages')->where('ref_type', 'web_signup')->where('ref_id', $row->id)->delete();
                DB::table('web_signups')->where('id', $row->id)->delete();
                if ($row->web_lead_id !== null) {
                    DB::table('web_leads')->where('id', $row->web_lead_id)->where('kind', 'signup')->where('status', 'new')->delete();
                }
            });
        }

        return $rows->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $row): array
    {
        $tenantSlug = null;
        if ($row->tenant_id !== null) {
            $tenantSlug = (string) DB::table('tenants')->where('id', $row->tenant_id)->value('slug');
        }

        return [
            'id' => (string) $row->id,
            'step' => (string) $row->step,
            'email' => (string) $row->email,
            'email_verified' => $row->email_verified_at !== null,
            'company' => $row->ruc === null ? null : [
                'ruc' => (string) $row->ruc, 'company_name' => (string) $row->company_name, 'employees_range' => (string) $row->employees_range,
                'labor_regime' => (string) $row->labor_regime, 'subdomain' => (string) $row->subdomain,
            ],
            'plan' => $row->plan_id === null ? null : (string) DB::table('plans')->where('id', $row->plan_id)->value('code'),
            'addons' => TenantAdministration::pgArray($row->addon_codes),
            'billing_cycle' => (string) $row->billing_cycle,
            'tenant' => $tenantSlug === null ? null : ['slug' => $tenantSlug, 'url' => $this->tenantUrl($tenantSlug)],
        ];
    }

    private function verify(\stdClass $signup, string $code): void
    {
        if ($signup->email_verified_at !== null) {
            return;
        }
        if ((int) $signup->code_attempts >= self::MAX_CODE_ATTEMPTS) {
            throw new AyniException('code_locked', 429, (string) trans('platform.web.code_locked'));
        }
        if ($signup->code_expires_at === null || now()->greaterThan($signup->code_expires_at)) {
            throw new AyniException('code_expired', 422, (string) trans('platform.web.code_expired'));
        }
        if (! hash_equals((string) $signup->verification_code_hash, $this->hash($code))) {
            DB::table('web_signups')->where('id', $signup->id)->increment('code_attempts');
            throw ValidationException::withMessages(['verification_code' => [(string) trans('platform.web.code_invalid')]]);
        }
        DB::table('web_signups')->where('id', $signup->id)->update([
            'email_verified_at' => now(), 'verification_code_hash' => null, 'code_expires_at' => null,
            'step' => $signup->step === 'account' ? 'company' : $signup->step,
        ]);
    }

    private function requireVerified(\stdClass $signup): void
    {
        if ($signup->email_verified_at === null) {
            throw new BusinessRuleViolatedException('email_not_verified', (string) trans('platform.web.email_not_verified'));
        }
    }

    private function assertCompanyFree(string $ruc, string $slug): void
    {
        $errors = [];
        if (! Ruc::isValid($ruc)) {
            $errors['company.ruc'][] = (string) trans('platform.web.invalid_ruc');
        } elseif ($this->rucTaken($ruc)) {
            $errors['company.ruc'][] = (string) trans('platform.web.ruc_taken');
        }
        $problem = $this->subdomainProblem($slug);
        if ($problem !== null) {
            $errors['company.subdomain'][] = (string) trans($problem === 'invalid' ? 'platform.web.subdomain_invalid' : 'platform.web.subdomain_taken');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Un RUC está en uso si alguna organización activa ya tiene una empresa con ese RUC. */
    private function rucTaken(string $ruc): bool
    {
        return (bool) $this->cross->readAcrossTenants(fn (): bool => DB::table('legal_entities AS le')->join('tenants AS t', 't.id', '=', 'le.tenant_id')
            ->where('le.country_code', 'PE')->where('le.tax_id', $ruc)->whereNull('le.deleted_at')->whereNull('t.deleted_at')
            ->whereNotIn('t.status', ['cancelled', 'deleted'])->exists());
    }

    private function subdomainProblem(string $slug): ?string
    {
        if (preg_match(self::SUBDOMAIN, $slug) !== 1) {
            return 'invalid';
        }
        if (in_array($slug, (array) config('tenancy.reserved_subdomains', []), true)) {
            return 'reserved';
        }
        // «taken» = existe una organización con esa dirección (el enlace «Ingresar» del sitio la usa).
        if (DB::table('tenants')->where('slug', $slug)->whereNull('deleted_at')->exists()) {
            return 'taken';
        }

        return null;
    }

    private function publicPlan(string $code): \stdClass
    {
        $plan = DB::table('plans')->where('code', $code)->where('status', 'active')->where('is_public', true)
            ->whereNull('deleted_at')->whereNull('superseded_by_plan_id')->first();
        if (! $plan instanceof \stdClass) {
            throw ValidationException::withMessages(['plan' => [(string) trans('platform.web.plan_unknown')]]);
        }

        return $plan;
    }

    private function byToken(string $token): \stdClass
    {
        $row = DB::table('web_signups')->where('token_hash', $this->hash($token))->first();
        if (! $row instanceof \stdClass) {
            throw new AyniException('not_found', 404, (string) trans('platform.web.signup_not_found'));
        }

        return $row;
    }

    private function row(string $id): \stdClass
    {
        $row = DB::table('web_signups')->where('id', $id)->first();
        if (! $row instanceof \stdClass) {
            throw new AyniException('not_found', 404, (string) trans('platform.web.signup_not_found'));
        }

        return $row;
    }

    private function queueCode(string $signupId, string $email, string $name, ?string $locale, string $code): void
    {
        $this->messages->queue('signup_code', $email, $locale, 'platform.web.mail.code_subject', 'platform.web.mail.code_body', [
            'name' => $name, 'code' => $code, 'minutes' => self::CODE_TTL_MINUTES,
        ], 'web_signup', $signupId);
    }

    private function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function tenantUrl(string $slug): string
    {
        return rtrim(str_replace('{slug}', $slug, (string) config('tenancy.app_url', 'http://{slug}.localhost:3000')), '/');
    }
}
