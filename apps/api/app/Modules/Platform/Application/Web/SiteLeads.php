<?php

namespace App\Modules\Platform\Application\Web;

use App\Modules\Platform\Application\PlatformSettings;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * WEB-6 · WEB-7 · Leads del sitio (contacto, partner, boletín, calculadora y lista de espera) con su página de origen,
 * campaña y consentimiento (RN-WEB-09), y la lista de espera de lo que está en «Próximamente»: al publicar el módulo
 * en la consola se avisa una sola vez a cada inscrito (RN-WEB-08). La consola ve los leads, los registros a medias
 * con el paso en que quedaron y cuántas empresas esperan cada módulo.
 */
final class SiteLeads
{
    public const PUBLIC_KINDS = ['contact', 'partner', 'newsletter', 'calculator', 'waitlist'];

    public function __construct(
        private readonly PublicFormGuard $guard,
        private readonly WebMessages $messages,
        private readonly PlatformSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input, ?string $ip, ?string $idempotencyKey): array
    {
        $this->guard->check($input, $ip);
        if ($idempotencyKey !== null) {
            $existing = DB::table('web_leads')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $this->present($existing);
            }
        }
        $kind = (string) $input['kind'];
        $email = mb_strtolower(trim((string) $input['email']));
        $productId = null;
        if (! empty($input['product_code'])) {
            $productId = DB::table('web_products')->where('code', (string) $input['product_code'])->value('id');
            if ($productId === null) {
                throw ValidationException::withMessages(['product_code' => [(string) trans('validation.exists', ['attribute' => 'product_code'])]]);
            }
        }
        $module = null;
        if ($kind === 'waitlist') {
            $module = DB::table('modules')->where('code', (string) ($input['module_code'] ?? ''))->first();
            if ($module === null) {
                throw ValidationException::withMessages(['module_code' => [(string) trans('validation.exists', ['attribute' => 'module_code'])]]);
            }
            if ((bool) $module->is_published) {
                throw new BusinessRuleViolatedException('module_available', (string) trans('platform.web.module_available'));
            }
            // Una sola inscripción por correo y módulo mientras no se haya avisado.
            $already = DB::table('web_leads')->where('kind', 'waitlist')->where('email', $email)->where('module_code', $module->code)->whereNull('notified_at')->first();
            if ($already !== null) {
                return $this->present($already);
            }
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $input, $kind, $email, $productId, $module, $idempotencyKey): void {
            DB::table('web_leads')->insert([
                'id' => $id, 'kind' => $kind, 'full_name' => $input['full_name'] ?? null, 'email' => $email,
                'phone' => $input['phone'] ?? null, 'company_name' => $input['company_name'] ?? null, 'ruc' => $input['ruc'] ?? null,
                'employees_range' => $input['employees_range'] ?? null, 'web_product_id' => $productId, 'module_code' => $module?->code,
                'source_page' => $input['source_page'] ?? null, 'utm' => isset($input['utm']) ? json_encode($input['utm'], JSON_THROW_ON_ERROR) : null,
                'consent_purpose' => (string) $input['consent_purpose'], 'consent_at' => now(),
                'message' => $input['message'] ?? null, 'idempotency_key' => $idempotencyKey,
            ]);
            $name = (string) ($input['full_name'] ?? '');
            if ($kind === 'waitlist' && $module !== null) {
                $this->messages->queue('waitlist_joined', $email, $input['locale'] ?? 'es', 'platform.web.mail.waitlist_subject', 'platform.web.mail.waitlist_body',
                    ['name' => $name, 'module' => (string) $module->name], 'web_lead', $id);
            } elseif ($kind !== 'newsletter') {
                $this->messages->queue('lead_received', $email, $input['locale'] ?? 'es', 'platform.web.mail.lead_subject', 'platform.web.mail.lead_body',
                    ['name' => $name], 'web_lead', $id);
            }
        });

        $row = DB::table('web_leads')->where('id', $id)->first();
        if (! $row instanceof \stdClass) {
            throw new AyniException('not_found', 404);
        }

        return $this->present($row);
    }

    /**
     * RN-WEB-08 · Avisa a la lista de espera de un módulo recién publicado. notified_at se marca en la misma
     * transacción que encola el correo: publicar otra vez no vuelve a avisar.
     */
    public function notifyWaitlist(string $moduleCode): int
    {
        $module = DB::table('modules')->where('code', $moduleCode)->first();
        if ($module === null) {
            return 0;
        }
        $product = DB::table('web_product_modules AS pm')->join('web_products AS p', 'p.id', '=', 'pm.web_product_id')
            ->where('pm.module_code', $moduleCode)->where('p.is_suite', false)->orderBy('p.sort_order')->value('p.slug');
        $link = rtrim((string) config('platform.site_url'), '/').'/'.($product ?? 'precios');
        $coupons = (array) ($this->settings->value('web.launch_coupons') ?? []);
        $coupon = isset($coupons[$moduleCode]) && is_string($coupons[$moduleCode]) ? $coupons[$moduleCode] : null;

        $count = 0;
        $leads = DB::table('web_leads')->where('kind', 'waitlist')->where('module_code', $moduleCode)->whereNull('notified_at')->get();
        foreach ($leads as $lead) {
            DB::transaction(function () use ($lead, $module, $link, $coupon, &$count): void {
                $marked = DB::table('web_leads')->where('id', $lead->id)->whereNull('notified_at')->update(['notified_at' => now()]);
                if ($marked === 0) {
                    return;
                }
                $append = $coupon === null ? '' : (string) trans('platform.web.mail.launch_coupon', ['coupon' => $coupon]);
                $this->messages->queue('waitlist_launch', (string) $lead->email, 'es', 'platform.web.mail.launch_subject', 'platform.web.mail.launch_body',
                    ['name' => (string) ($lead->full_name ?? ''), 'module' => (string) $module->name, 'link' => $link], 'web_lead', (string) $lead->id, $append);
                $count++;
            });
        }

        return $count;
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(?string $kind, ?string $status, ?string $q, int $page, int $perPage): array
    {
        $query = DB::table('web_leads')
            ->when($kind !== null && $kind !== '', fn ($w) => $w->where('kind', $kind))
            ->when($status !== null && $status !== '', fn ($w) => $w->where('status', $status))
            ->when($q !== null && $q !== '', fn ($w) => $w->where(fn ($x) => $x->where('email', 'ilike', '%'.$q.'%')->orWhere('company_name', 'ilike', '%'.$q.'%')));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->forPage($page, $perPage)->get();

        return ['data' => array_values($rows->map(fn ($r): array => $this->present($r))->all()), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        $row = Str::isUuid($id) ? DB::table('web_leads')->where('id', $id)->first() : null;
        if (! $row instanceof \stdClass) {
            throw new AyniException('not_found', 404);
        }

        return $this->present($row);
    }

    /**
     * WEB-7 · Cuántas empresas esperan cada módulo (y cuántas ya fueron avisadas).
     *
     * @return list<array{module_code: string, module_name: string, published: bool, waiting: int, notified: int}>
     */
    public function waitlist(): array
    {
        $rows = DB::table('web_leads AS l')->join('modules AS m', 'm.code', '=', 'l.module_code')->where('l.kind', 'waitlist')
            ->groupBy('l.module_code', 'm.name', 'm.is_published')
            ->selectRaw('l.module_code, m.name, m.is_published, count(*) FILTER (WHERE l.notified_at IS NULL) AS waiting, count(*) FILTER (WHERE l.notified_at IS NOT NULL) AS notified')
            ->orderByDesc('waiting')->get();

        return array_values($rows->map(fn ($r): array => [
            'module_code' => (string) $r->module_code, 'module_name' => (string) $r->name, 'published' => (bool) $r->is_published,
            'waiting' => (int) $r->waiting, 'notified' => (int) $r->notified,
        ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $row): array
    {
        $signup = $row->kind === 'signup' ? DB::table('web_signups')->where('web_lead_id', $row->id)->first(['step', 'completed_at']) : null;

        return [
            'id' => (string) $row->id,
            'kind' => (string) $row->kind,
            'full_name' => $row->full_name,
            'email' => (string) $row->email,
            'phone' => $row->phone,
            'company_name' => $row->company_name,
            'ruc' => $row->ruc,
            'employees_range' => $row->employees_range,
            'product_code' => $row->web_product_id === null ? null : DB::table('web_products')->where('id', $row->web_product_id)->value('code'),
            'module_code' => $row->module_code,
            'source_page' => $row->source_page,
            'utm' => $row->utm === null ? null : json_decode((string) $row->utm, true),
            'consent_purpose' => (string) $row->consent_purpose,
            'consent_at' => (string) $row->consent_at,
            'message' => $row->message ?? null,
            'status' => (string) $row->status,
            'signup_step' => $signup?->step,
            'notified_at' => $row->notified_at,
            'created_at' => (string) $row->created_at,
        ];
    }
}
