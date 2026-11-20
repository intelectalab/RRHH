<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Web\DemoScheduling;
use App\Modules\Platform\Application\Web\SiteLeads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WEB-5 a WEB-7 · Consola → Sitio web: leads (con el paso en que quedó un registro a medias), demos agendadas y
 * cuántas empresas esperan cada módulo.
 */
final class WebLeadController
{
    public function __construct(
        private readonly SiteLeads $leads,
        private readonly DemoScheduling $demos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filter.kind' => ['sometimes', 'nullable', Rule::in(['demo', 'contact', 'partner', 'newsletter', 'calculator', 'waitlist', 'signup'])],
            'filter.status' => ['sometimes', 'nullable', Rule::in(['new', 'contacted', 'qualified', 'converted', 'discarded'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->leads->list(
            $data['filter']['kind'] ?? null, $data['filter']['status'] ?? null, $data['q'] ?? null,
            (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25),
        ));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->leads->show($id)]);
    }

    public function waitlist(): JsonResponse
    {
        return response()->json(['data' => $this->leads->waitlist()]);
    }

    public function demos(Request $request): JsonResponse
    {
        $data = $request->validate(['filter.status' => ['sometimes', 'nullable', Rule::in(['scheduled', 'rescheduled', 'cancelled', 'done', 'no_show'])]]);

        return response()->json($this->demos->list($data['filter']['status'] ?? null));
    }
}
