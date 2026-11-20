<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Web\DemoScheduling;
use App\Modules\Platform\Application\Web\SiteLeads;
use App\Modules\Platform\Application\Web\SiteSignups;
use App\Modules\Platform\Application\Web\WebMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WEB-5 a WEB-7 · Formularios públicos del sitio gestiahr.com (sin sesión): registro de prueba en tres pasos,
 * leads y lista de espera, y demos. El sitio los llama desde su servidor; las rutas limitan peticiones por IP.
 * Los correos que dispara cada formulario se entregan al terminar la respuesta (y si fallan, los reintenta la
 * tarea web:deliver).
 */
final class PublicSiteController
{
    public function __construct(
        private readonly SiteSignups $signups,
        private readonly SiteLeads $leads,
        private readonly DemoScheduling $demos,
        private readonly WebMessages $messages,
    ) {}

    public function ruc(string $ruc): JsonResponse
    {
        return response()->json(['data' => $this->signups->checkRuc($ruc)]);
    }

    public function subdomain(string $slug): JsonResponse
    {
        return response()->json(['data' => $this->signups->checkSubdomain($slug)]);
    }

    public function startSignup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['required', 'email', 'max:254'],
            'locale' => ['sometimes', Rule::in(['es', 'en', 'pt'])],
            'consent' => ['required', 'boolean'],
            'source_page' => ['sometimes', 'nullable', 'string', 'max:500'],
            'utm' => ['sometimes', 'nullable', 'array', 'max:10'],
            'utm.*' => ['nullable', 'string', 'max:200'],
            'captcha_token' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'website' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $result = $this->signups->start($data, $request->ip(), $this->key($request));
        $this->deliverAfterResponse();

        return response()->json(['data' => $result], 201);
    }

    public function updateSignup(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'verification_code' => ['sometimes', 'string', 'size:6'],
            'resend_code' => ['sometimes', 'boolean'],
            'company' => ['sometimes', 'array'],
            'company.ruc' => ['required_with:company', 'string', 'size:11'],
            'company.company_name' => ['required_with:company', 'string', 'min:2', 'max:200'],
            'company.employees_range' => ['required_with:company', Rule::in(['1-10', '11-50', '51-200', '201-500', '501+'])],
            'company.labor_regime' => ['required_with:company', 'string', 'max:40', Rule::exists('labor_regimes', 'code')->where('country_code', 'PE')],
            'company.subdomain' => ['required_with:company', 'string', 'max:40'],
            'plan' => ['sometimes', 'string', 'max:50'],
            'addons' => ['sometimes', 'array', 'max:20'],
            'addons.*' => ['string', 'max:50'],
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'annual'])],
        ]);
        $result = $this->signups->update($token, $data);
        $this->deliverAfterResponse();

        return response()->json(['data' => $result]);
    }

    public function completeSignup(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['mode' => ['sometimes', Rule::in(['trial', 'paid'])]]);
        $result = $this->signups->complete($token, (string) ($data['mode'] ?? 'trial'));
        $this->deliverAfterResponse();

        return response()->json(['data' => $result]);
    }

    public function storeLead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(SiteLeads::PUBLIC_KINDS)],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'ruc' => ['sometimes', 'nullable', 'string', 'size:11'],
            'employees_range' => ['sometimes', 'nullable', Rule::in(['1-10', '11-50', '51-200', '201-500', '501+'])],
            'product_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'module_code' => ['required_if:kind,waitlist', 'nullable', 'string', 'max:40'],
            'message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'locale' => ['sometimes', Rule::in(['es', 'en', 'pt'])],
            'consent' => ['required', 'boolean'],
            'consent_purpose' => ['required', 'string', 'max:200'],
            'source_page' => ['sometimes', 'nullable', 'string', 'max:500'],
            'utm' => ['sometimes', 'nullable', 'array', 'max:10'],
            'utm.*' => ['nullable', 'string', 'max:200'],
            'captcha_token' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'website' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $lead = $this->leads->create($data, $request->ip(), $this->key($request));
        $this->deliverAfterResponse();

        return response()->json(['data' => ['id' => $lead['id'], 'kind' => $lead['kind'], 'status' => $lead['status']]], 201);
    }

    public function demoSlots(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->demos->slots(isset($data['from']) ? (string) $data['from'] : null)]);
    }

    public function bookDemo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'string', 'max:40'],
            'full_name' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'employees_range' => ['sometimes', 'nullable', Rule::in(['1-10', '11-50', '51-200', '201-500', '501+'])],
            'product_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'channel' => ['sometimes', Rule::in(['video', 'phone'])],
            'message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'locale' => ['sometimes', Rule::in(['es', 'en', 'pt'])],
            'consent' => ['required', 'boolean'],
            'consent_purpose' => ['sometimes', 'string', 'max:200'],
            'source_page' => ['sometimes', 'nullable', 'string', 'max:500'],
            'utm' => ['sometimes', 'nullable', 'array', 'max:10'],
            'utm.*' => ['nullable', 'string', 'max:200'],
            'captcha_token' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'website' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $booking = $this->demos->book($data, $request->ip(), $this->key($request));
        $this->deliverAfterResponse();

        return response()->json(['data' => $booking], 201);
    }

    public function showDemo(string $token): JsonResponse
    {
        return response()->json(['data' => $this->demos->showByToken($token)]);
    }

    public function manageDemo(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'action' => ['sometimes', Rule::in(['cancel', 'reschedule'])],
            'starts_at' => ['required_if:action,reschedule', 'nullable', 'string', 'max:40'],
            'locale' => ['sometimes', Rule::in(['es', 'en', 'pt'])],
        ]);
        $booking = $this->demos->manage($token, (string) ($data['action'] ?? 'cancel'), $data['starts_at'] ?? null, $data['locale'] ?? null);
        $this->deliverAfterResponse();

        return response()->json(['data' => $booking]);
    }

    private function key(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');

        return is_string($key) && $key !== '' ? mb_substr($key, 0, 200) : null;
    }

    private function deliverAfterResponse(): void
    {
        app()->terminating(fn () => $this->messages->deliver(20));
    }
}
