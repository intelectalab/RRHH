<?php

namespace App\Modules\Helpdesk\Http\Controllers;

use App\Modules\Helpdesk\Application\TicketCategoryService;
use App\Modules\Helpdesk\Application\TicketService;
use App\Modules\Helpdesk\Infrastructure\Models\Ticket;
use App\Modules\Helpdesk\Infrastructure\Models\TicketCategory;
use App\Shared\Audit\AuditLogger;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\SelfService\OwnEmployments;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * ESS-5 · Solicitudes a RRHH: el colaborador crea y sigue las suyas; RRHH las atiende desde su bandeja.
 * Los estados cambian solo por acciones (regla 3); PATCH edita prioridad, tipo o asunto, nunca el estado.
 */
final class TicketController
{
    private const FILE_RULES = ['sometimes', 'nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx'];

    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketCategoryService $categories,
        private readonly OwnEmployments $own,
    ) {}

    public function categories(Request $request): JsonResponse
    {
        $archived = in_array((string) $request->query('include_archived', 'false'), ['true', '1'], true)
            && $this->permissions($request)->has('helpdesk.settings.update');

        return response()->json(['data' => array_map(fn (TicketCategory $c) => $this->categories->present($c), $this->categories->all($archived))]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:120'],
        ] + $this->categoryRules());

        return response()->json(['data' => $this->categories->present($this->categories->create($data))], 201);
    }

    public function updateCategory(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120']] + $this->categoryRules() + [
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
        ]);

        return response()->json(['data' => $this->categories->present($this->categories->update($this->categories->find($id), $data))]);
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', 'string', 'max:80'],
            'filter.category_id' => ['sometimes', 'uuid'],
            'filter.mine' => ['sometimes', 'in:true,false,1,0'],
            'filter.assigned_to_me' => ['sometimes', 'in:true,false,1,0'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);
        $userId = $this->userId($request);
        $permissions = $this->permissions($request);
        $q = $this->tickets->scoped($request, 'helpdesk.tickets.view', $userId, $permissions);
        if (isset($input['filter']['status'])) {
            $q->whereIn('tickets.status', explode(',', $input['filter']['status']));
        }
        if (isset($input['filter']['category_id'])) {
            $q->where('tickets.ticket_category_id', $input['filter']['category_id']);
        }
        if (in_array($input['filter']['mine'] ?? 'false', ['true', '1'], true)) {
            $q->where('tickets.requester_user_id', $userId);
        }
        if (in_array($input['filter']['assigned_to_me'] ?? 'false', ['true', '1'], true)) {
            $q->where('tickets.assignee_user_id', $userId);
        }
        if (isset($input['q'])) {
            $like = '%'.mb_strtolower($input['q']).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(tickets.subject) LIKE ?', [$like])->orWhereRaw('lower(tickets.number) LIKE ?', [$like]));
        }
        /** @var list<Ticket> $rows */
        $rows = $q->orderByDesc('tickets.created_at')->limit(200)->get()->all();

        return response()->json([
            'data' => array_map(fn (Ticket $t) => $this->tickets->present($t, $userId, $permissions, false), $rows),
            'meta' => ['can_manage_categories' => $permissions->has('helpdesk.settings.update'), 'is_staff' => $permissions->has('helpdesk.tickets.update')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'uuid'],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'employment_id' => ['sometimes', 'nullable', 'uuid'],
            'file' => self::FILE_RULES,
        ]);
        $userId = $this->userId($request);
        // RN-ESS-01 · La solicitud se asocia a una relación laboral propia (una ajena responde 404).
        $picked = $this->own->pick($userId, $data['employment_id'] ?? null);
        $file = $request->file('file');
        $t = $this->tickets->create($data, $userId, $picked['id'] ?? null, $file instanceof UploadedFile ? $file : null);

        return response()->json(['data' => $this->tickets->present($t, $userId, $this->permissions($request), true)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $userId = $this->userId($request);

        return response()->json(['data' => $this->tickets->present($this->find($request, $id), $userId, $this->permissions($request), true)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'category_id' => ['sometimes', 'uuid'],
            'subject' => ['sometimes', 'string', 'max:200'],
        ]);
        $t = $this->find($request, $id);
        $userId = $this->userId($request);
        // El estado no se edita (regla 3): se ignora si llega.
        $changes = array_filter([
            'priority' => $data['priority'] ?? null, 'subject' => $data['subject'] ?? null,
            'ticket_category_id' => isset($data['category_id']) ? $this->categories->find($data['category_id'])->id : null,
        ], fn ($v) => $v !== null);
        if ($changes !== []) {
            DB::transaction(function () use ($t, $changes): void {
                $before = $t->only(array_keys($changes));
                $t->fill($changes);
                $t->save();
                app(AuditLogger::class)->record('ticket.updated', 'ticket', $t->id, $before, $t->only(array_keys($changes)));
            });
        }

        return response()->json(['data' => $this->tickets->present($t, $userId, $this->permissions($request), true)]);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['assignee_user_id' => ['sometimes', 'nullable', 'uuid'], 'message' => ['sometimes', 'nullable', 'string', 'max:5000']]);

        return $this->act($request, $id, 'assign', $data['message'] ?? null, $data['assignee_user_id'] ?? null);
    }

    public function requestInfo(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id);
        $data = $request->validate(['message' => ['required', 'string', 'max:5000']]);

        return $this->act($request, $id, 'request_info', $data['message']);
    }

    public function resolve(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'resolve', $this->note($request));
    }

    public function close(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'close', $this->note($request));
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'reopen', $this->note($request));
    }

    public function message(Request $request, string $id): JsonResponse
    {
        $t = $this->find($request, $id);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'is_internal_note' => ['sometimes', 'in:true,false,1,0'],
            'file' => self::FILE_RULES,
        ]);
        $userId = $this->userId($request);
        $file = $request->file('file');
        $this->tickets->message($t, $userId, $this->permissions($request), $data['body'],
            in_array($data['is_internal_note'] ?? 'false', ['true', '1'], true), $file instanceof UploadedFile ? $file : null);

        return response()->json(['data' => $this->tickets->present($t, $userId, $this->permissions($request), true)], 201);
    }

    public function file(Request $request, string $id, string $message_id): Response
    {
        $t = $this->find($request, $id);
        $userId = $this->userId($request);
        $file = $this->tickets->attachment($t, $message_id, $userId, $this->permissions($request));
        $confidential = (bool) TicketCategory::query()->withTrashed()->where('id', $t->ticket_category_id)->value('is_confidential');
        if ($confidential) {
            // Regla 7 · El adjunto de una solicitud confidencial queda en el registro de accesos.
            DB::table('data_access_logs')->insert([
                'tenant_id' => TenancyScope::requireTenant(), 'user_id' => $userId, 'access_kind' => 'download', 'data_category' => 'other',
                'entity_type' => 'ticket_message', 'entity_id' => $message_id, 'fields' => '{file}', 'purpose' => 'ticket',
                'ip' => $request->ip(), 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
        }

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.addcslashes($file['name'], '"\\').'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function act(Request $request, string $id, string $action, ?string $note, ?string $assignee = null): JsonResponse
    {
        $t = $this->find($request, $id);
        $userId = $this->userId($request);
        $t = $this->tickets->transition($t, $action, $userId, $this->permissions($request), $note, $assignee);

        return response()->json(['data' => $this->tickets->present($t, $userId, $this->permissions($request), true)]);
    }

    private function note(Request $request): ?string
    {
        $data = $request->validate(['message' => ['sometimes', 'nullable', 'string', 'max:5000'], 'reason' => ['sometimes', 'nullable', 'string', 'max:5000']]);

        return $data['message'] ?? $data['reason'] ?? null;
    }

    private function find(Request $request, string $id): Ticket
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $t = $this->tickets->scoped($request, 'helpdesk.tickets.view', $this->userId($request), $this->permissions($request))->where('tickets.id', $id)->first();
        abort_unless($t instanceof Ticket, 404);

        return $t;
    }

    /** @return array<string, list<mixed>> */
    private function categoryRules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'default_assignee_role_id' => ['sometimes', 'nullable', 'uuid'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'is_confidential' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        return (string) $user->getAuthIdentifier();
    }
}
