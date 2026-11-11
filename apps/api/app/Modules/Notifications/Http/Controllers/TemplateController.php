<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Application\NotificationCatalog;
use App\Modules\Notifications\Application\TemplateService;
use App\Modules\Notifications\Infrastructure\Models\NotificationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * NOT-1 · Plantillas de la organización (Configuración → Mensajes).
 */
final class TemplateController
{
    public function __construct(
        private readonly TemplateService $templates,
        private readonly NotificationCatalog $catalog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->templates->ensureDefaults();
        $query = NotificationTemplate::query()->orderBy('code')->orderBy('channel')->orderBy('locale');
        foreach (['channel', 'locale'] as $f) {
            $v = $request->input("filter.{$f}", $request->query($f));
            if (is_string($v) && $v !== '') {
                $query->where($f, $v);
            }
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('code', 'ilike', $like)->orWhere('subject', 'ilike', $like)->orWhere('body', 'ilike', $like));
        }

        return response()->json([
            'data' => $query->limit(300)->get()->map(fn (NotificationTemplate $t) => $this->templates->present($t))->all(),
            'meta' => ['per_page' => 300, 'next_cursor' => null],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->templates->present($this->find($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', Rule::in(array_keys($this->catalog->categories()))],
            'channel' => ['required', Rule::in($this->catalog->channels())],
            'locale' => ['required', Rule::in(NotificationCatalog::LOCALES)],
            'subject' => ['sometimes', 'nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        /** @var array{key: string, channel: string, locale: string, subject?: string|null, body: string} $data */
        return response()->json(['data' => $this->templates->present($this->templates->create($data))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $template = $this->find($id);
        $data = $request->validate(['subject' => ['sometimes', 'nullable', 'string', 'max:300'], 'body' => ['sometimes', 'string', 'min:1', 'max:10000']]);

        /** @var array{subject?: string|null, body?: string} $data */
        return response()->json(['data' => $this->templates->present($this->templates->update($template, $data)->refresh())]);
    }

    /** NOT-5 · Envía una plantilla de WhatsApp a aprobación del proveedor (409 si el estado no lo permite). */
    public function requestApproval(string $id): JsonResponse
    {
        return response()->json(['data' => $this->templates->present($this->templates->requestApproval($this->find($id))->refresh())]);
    }

    private function find(string $id): NotificationTemplate
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);

        /** @var NotificationTemplate */
        return NotificationTemplate::query()->whereKey($id)->firstOrFail();
    }
}
