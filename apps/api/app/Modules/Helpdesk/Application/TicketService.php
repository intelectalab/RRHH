<?php

namespace App\Modules\Helpdesk\Application;

use App\Modules\Helpdesk\Domain\TicketStateMachine;
use App\Modules\Helpdesk\Infrastructure\Models\Ticket;
use App\Modules\Helpdesk\Infrastructure\Models\TicketCategory;
use App\Modules\Helpdesk\Infrastructure\Models\TicketMessage;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Files\FileService;
use App\Shared\Files\Models\StoredFile;
use App\Shared\Notifications\Notifier;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ESS-5 · Solicitudes a RRHH desde el portal (ME-31).
 *
 * - Alcance en la consulta (regla 6): «own» = las que pidió; «assigned» = las que pidió, las que tiene
 *   asignadas y las sin asignar de un tipo que atiende uno de sus roles; los demás alcances, por la relación
 *   laboral; «organization» = todas. Las de un tipo confidencial solo las ven el solicitante, el asignado y
 *   quien tiene helpdesk.tickets.confidential.view.
 * - Cada cambio de estado y cada mensaje queda en el historial (ticket_messages) y en la auditoría.
 * - Avisos: a RRHH al crearse y cuando el solicitante responde; al solicitante cuando su solicitud cambia.
 */
final class TicketService
{
    private const CONFIDENTIAL = 'helpdesk.tickets.confidential.view';

    /** Acción de la máquina => [permiso, hito del historial, nombre en la API]. */
    private const ACTIONS = [
        'assign' => ['helpdesk.tickets.assign', 'assigned', 'assign'],
        'request_info' => ['helpdesk.tickets.request_info', 'info_requested', 'request-info'],
        'resolve' => ['helpdesk.tickets.resolve', 'resolved', 'resolve'],
        'close' => ['helpdesk.tickets.close', 'closed', 'close'],
        'reopen' => ['helpdesk.tickets.reopen', 'reopened', 'reopen'],
    ];

    public function __construct(
        private readonly TicketStateMachine $machine,
        private readonly TicketCategoryService $categories,
        private readonly DataScope $scope,
        private readonly FileService $files,
        private readonly ParameterResolver $parameters,
        private readonly Notifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    /** @return Builder<Ticket> */
    public function scoped(Request $request, string $permission, string $userId, EffectivePermissions $permissions): Builder
    {
        $query = Ticket::query()->select('tickets.*');
        $scope = $permissions->scopeFor($permission);
        $roles = $this->roleIds($userId);
        $mine = function (Builder $w) use ($userId, $roles, $scope): void {
            $w->where('tickets.requester_user_id', $userId);
            if ($scope !== 'own') {
                $w->orWhere('tickets.assignee_user_id', $userId)->orWhere(function (Builder $u) use ($roles): void {
                    $u->whereNull('tickets.assignee_user_id')->whereIn('tickets.ticket_category_id', TicketCategory::query()->withTrashed()
                        ->whereIn('default_assignee_role_id', $roles === [] ? ['00000000-0000-0000-0000-000000000000'] : $roles)->select('id'));
                });
            }
        };
        if ($scope === 'own' || $scope === 'assigned') {
            $query->where($mine);
        } elseif ($scope !== 'organization') {
            $query->where(function (Builder $w) use ($mine, $request, $permission): void {
                $w->where($mine)->orWhere(function (Builder $e) use ($request, $permission): void {
                    $e->whereNotNull('tickets.employment_id');
                    $this->scope->applyEmployment($e, $request, $permission, 'tickets.employment_id');
                });
            });
        }
        if (! $permissions->has(self::CONFIDENTIAL)) {
            $query->where(function (Builder $w) use ($userId): void {
                $w->whereIn('tickets.ticket_category_id', TicketCategory::query()->withTrashed()->where('is_confidential', false)->select('id'))
                    ->orWhere('tickets.requester_user_id', $userId)->orWhere('tickets.assignee_user_id', $userId);
            });
        }

        return $query;
    }

    /**
     * @param  array{category_id: string, subject: string, description?: string|null, priority?: string|null}  $data
     */
    public function create(array $data, string $userId, ?string $employmentId, ?UploadedFile $file): Ticket
    {
        $category = $this->categories->find($data['category_id']);
        if ($category->status !== 'active') {
            throw ValidationException::withMessages(['category_id' => [(string) trans('helpdesk.category_archived')]]);
        }
        if ($category->requires_attachment && $file === null) {
            throw ValidationException::withMessages(['file' => [(string) trans('helpdesk.attachment_required')]]);
        }
        $ticket = DB::transaction(function () use ($data, $userId, $employmentId, $file, $category): Ticket {
            $tenant = TenancyScope::requireTenant();
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['tickets:'.$tenant]);
            $n = Ticket::query()->withTrashed()->count() + 1;
            $respond = (int) ($this->parameters->value('mesa_ayuda.sla_respuesta_horas') ?? 0);
            $resolve = (int) ($this->parameters->value('mesa_ayuda.sla_resolucion_horas') ?? 0);
            $t = Ticket::create([
                'number' => 'SOL-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'ticket_category_id' => $category->id,
                'requester_user_id' => $userId, 'employment_id' => $employmentId, 'subject' => $data['subject'],
                'description' => $data['description'] ?? null, 'priority' => $data['priority'] ?? 'normal', 'channel' => 'portal',
                'first_response_due_at' => $respond > 0 ? now()->addHours($respond) : null,
                'resolution_due_at' => $resolve > 0 ? now()->addHours($resolve) : null,
            ]);
            $this->history($t, $userId, 'created', (string) ($data['description'] ?? ''), false, $file);
            $this->audit->record('ticket.created', 'ticket', $t->id, null, ['number' => $t->number, 'category_id' => $category->id, 'subject' => $t->subject]);

            return $t;
        });
        $ticket->refresh();
        $this->notifyStaff($ticket, 'tickets.created');

        return $ticket;
    }

    /**
     * Mensaje del solicitante o de RRHH. Si la solicitud espera información y responde el solicitante, vuelve a
     * «en atención» (ME-31 responder). Una cerrada no admite mensajes.
     */
    public function message(Ticket $t, string $userId, EffectivePermissions $permissions, string $body, bool $internal, ?UploadedFile $file): TicketMessage
    {
        $isRequester = $t->requester_user_id === $userId;
        $staff = $permissions->has('helpdesk.tickets.update');
        abort_unless($isRequester || $staff || $t->assignee_user_id === $userId, 403);
        if (! $this->machine->isOpen($t->status) && $t->status !== TicketStateMachine::RESOLVED) {
            throw new InvalidTransitionException($t->status, $this->allowedActions($t, $userId, $permissions), (string) trans('helpdesk.closed_no_messages'));
        }
        if ($internal && (! $staff || $isRequester)) {
            throw ValidationException::withMessages(['is_internal_note' => [(string) trans('helpdesk.internal_not_allowed')]]);
        }

        [$message, $notify] = DB::transaction(function () use ($t, $userId, $isRequester, $body, $internal, $file): array {
            /** @var Ticket $locked */
            $locked = Ticket::query()->lockForUpdate()->findOrFail($t->id);
            $before = $locked->status;
            $event = 'comment';
            if ($isRequester && $locked->status === TicketStateMachine::WAITING_INFO) {
                $locked->status = $this->machine->getNextState($locked->status, 'reply');
                $event = 'replied';
            }
            if (! $isRequester && ! $internal && $locked->first_responded_at === null) {
                $locked->first_responded_at = now();
            }
            $locked->save();
            $m = $this->history($locked, $userId, $event, $body, $internal, $file);
            $this->audit->record('ticket.message_added', 'ticket', $locked->id, ['status' => $before], ['status' => $locked->status, 'message_id' => $m->id, 'internal' => $internal]);
            $t->setRawAttributes($locked->getAttributes(), true);

            return [$m, $internal ? null : ($isRequester ? 'staff' : 'requester')];
        });
        if ($notify === 'staff') {
            $this->notifyStaff($t, 'tickets.replied');
        } elseif ($notify === 'requester') {
            $this->notifyRequester($t);
        }

        return $message;
    }

    /**
     * Transición explícita (regla 3). assign toma la solicitud para quien actúa o la asigna a otro usuario.
     */
    public function transition(Ticket $t, string $action, string $userId, EffectivePermissions $permissions, ?string $note, ?string $assigneeId = null): Ticket
    {
        if (! in_array($action, $this->allowedActions($t, $userId, $permissions, false), true)) {
            throw new InvalidTransitionException($t->status, $this->allowedActions($t, $userId, $permissions), (string) trans('helpdesk.invalid_transition'));
        }
        if ($action === 'request_info' && ($note === null || trim($note) === '')) {
            throw ValidationException::withMessages(['message' => [(string) trans('helpdesk.message_required')]]);
        }
        if ($assigneeId !== null && ! DB::table('users')->where('id', $assigneeId)->where('status', 'active')->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['assignee_user_id' => [(string) trans('helpdesk.assignee_unknown')]]);
        }

        DB::transaction(function () use ($t, $action, $userId, $note, $assigneeId): void {
            /** @var Ticket $locked */
            $locked = Ticket::query()->lockForUpdate()->findOrFail($t->id);
            $before = $locked->only(['status', 'assignee_user_id']);
            $locked->status = $this->machine->getNextState($locked->status, $action);
            if ($action === 'assign') {
                $locked->assignee_user_id = $assigneeId ?? $userId;
                $locked->first_responded_at ??= now();
            }
            if ($action === 'request_info') {
                $locked->first_responded_at ??= now();
            }
            if ($action === 'resolve') {
                $locked->resolved_at = now();
                $locked->assignee_user_id ??= $userId;
            }
            if ($action === 'reopen') {
                $locked->resolved_at = null;
            }
            if ($action === 'close') {
                $locked->closed_at = now();
            }
            $locked->save();
            $this->history($locked, $userId, self::ACTIONS[$action][1], (string) ($note ?? ''), false, null);
            $this->audit->record('ticket.'.self::ACTIONS[$action][1], 'ticket', $locked->id, $before, $locked->only(['status', 'assignee_user_id']));
            $t->setRawAttributes($locked->getAttributes(), true);
        });

        if ($t->requester_user_id !== $userId) {
            $this->notifyRequester($t);
        } elseif ($action === 'reopen') {
            $this->notifyStaff($t, 'tickets.replied');
        }

        return $t;
    }

    /**
     * Acciones que el usuario puede ejecutar ahora (con su permiso y su alcance sobre la solicitud).
     *
     * @return list<string>
     */
    public function allowedActions(Ticket $t, string $userId, EffectivePermissions $permissions, bool $apiNames = true): array
    {
        $out = [];
        foreach ($this->machine->allowedActions($t->status) as $action) {
            if (! isset(self::ACTIONS[$action])) {
                continue;
            }
            $permission = self::ACTIONS[$action][0];
            if (! $permissions->has($permission)) {
                continue;
            }
            if ($permissions->scopeFor($permission) === 'own' && $t->requester_user_id !== $userId) {
                continue;
            }
            // El solicitante cierra o reabre lo suyo; tomar, pedir información y resolver son de RRHH.
            if (in_array($action, ['assign', 'request_info', 'resolve'], true) && $t->requester_user_id === $userId && ! $permissions->has('helpdesk.tickets.update')) {
                continue;
            }
            $out[] = $apiNames ? self::ACTIONS[$action][2] : $action;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Ticket $t, string $userId, EffectivePermissions $permissions, bool $withMessages): array
    {
        $category = TicketCategory::query()->withTrashed()->find($t->ticket_category_id);
        $names = DB::table('users')->whereIn('id', array_filter([$t->requester_user_id, $t->assignee_user_id]))->pluck('display_name', 'id');
        $person = $t->employment_id === null ? null : DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')
            ->where('e.id', $t->employment_id)->first(['p.first_names', 'p.preferred_name', 'p.paternal_surname']);
        $open = $this->machine->isOpen($t->status);
        $out = [
            'id' => $t->id, 'number' => $t->number,
            'category_id' => $t->ticket_category_id, 'category_name' => $category instanceof TicketCategory ? $this->categories->name($category) : null,
            'employment_id' => $t->employment_id,
            'person_name' => $person === null ? null : trim(($person->preferred_name ?: $person->first_names).' '.$person->paternal_surname),
            'requester_user_id' => $t->requester_user_id, 'requester_name' => $names[$t->requester_user_id] ?? null,
            'subject' => $t->subject, 'description' => $t->description, 'status' => $t->status, 'priority' => $t->priority,
            'assignee_user_id' => $t->assignee_user_id, 'assignee_name' => $t->assignee_user_id === null ? null : ($names[$t->assignee_user_id] ?? null),
            'assignee_role_id' => $category instanceof TicketCategory ? $category->default_assignee_role_id : null,
            'first_response_due_at' => $t->first_response_due_at?->toIso8601String(), 'resolution_due_at' => $t->resolution_due_at?->toIso8601String(),
            'first_responded_at' => $t->first_responded_at?->toIso8601String(), 'resolved_at' => $t->resolved_at?->toIso8601String(),
            'closed_at' => $t->closed_at?->toIso8601String(),
            'overdue' => $open && $t->status !== TicketStateMachine::WAITING_INFO && $t->resolution_due_at !== null && $t->resolution_due_at->isPast(),
            'channel' => $t->channel,
            'allowed_actions' => $this->allowedActions($t, $userId, $permissions),
            'can_message' => ($open || $t->status === TicketStateMachine::RESOLVED)
                && ($t->requester_user_id === $userId || $t->assignee_user_id === $userId || $permissions->has('helpdesk.tickets.update')),
            'version' => $t->lock_version,
            'created_at' => $t->created_at?->toIso8601String(), 'updated_at' => $t->updated_at?->toIso8601String(),
        ];
        if ($withMessages) {
            $showInternal = $permissions->has('helpdesk.tickets.update') && $t->requester_user_id !== $userId;
            $messages = TicketMessage::query()->where('ticket_id', $t->id)->when(! $showInternal, fn ($q) => $q->where('is_internal_note', false))
                ->orderBy('created_at')->orderBy('id')->get();
            $authors = DB::table('users')->whereIn('id', $messages->pluck('author_user_id')->filter()->unique()->all())->pluck('display_name', 'id');
            $out['messages'] = $messages->map(fn (TicketMessage $m) => [
                'id' => $m->id, 'event' => $m->event, 'body' => $m->body, 'is_internal_note' => $m->is_internal_note, 'via' => $m->via,
                'author_user_id' => $m->author_user_id, 'author_name' => $m->author_user_id === null ? null : ($authors[$m->author_user_id] ?? null),
                'mine' => $m->author_user_id === $userId,
                'has_file' => $m->stored_file_id !== null, 'file_name' => $m->file_name, 'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->all();
        }

        return $out;
    }

    /**
     * Adjunto de un mensaje visible para el usuario (la descarga queda registrada).
     *
     * @return array{name: string, mime: string, contents: string}
     */
    public function attachment(Ticket $t, string $messageId, string $userId, EffectivePermissions $permissions): array
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $messageId) === 1, 404);
        $showInternal = $permissions->has('helpdesk.tickets.update') && $t->requester_user_id !== $userId;
        $m = TicketMessage::query()->where('ticket_id', $t->id)->where('id', $messageId)->whereNotNull('stored_file_id')
            ->when(! $showInternal, fn ($q) => $q->where('is_internal_note', false))->first();
        abort_unless($m instanceof TicketMessage && $m->stored_file_id !== null, 404);

        return $this->files->contentsForDownload($m->stored_file_id, null, $userId);
    }

    private function history(Ticket $t, ?string $userId, string $event, string $body, bool $internal, ?UploadedFile $file): TicketMessage
    {
        $stored = $file === null ? null : $this->files->upload($file, $file->getClientOriginalName(), (string) $file->getMimeType(), null, $userId, StoredFile::CLASSIFICATION_CONFIDENTIAL);

        return TicketMessage::create([
            'ticket_id' => $t->id, 'author_user_id' => $userId, 'body' => $body, 'is_internal_note' => $internal,
            'via' => $event === 'comment' || $event === 'created' || $event === 'replied' ? 'portal' : 'system', 'event' => $event,
            'stored_file_id' => $stored?->id, 'file_name' => $stored === null ? null : (string) $stored->original_name,
        ]);
    }

    /** @return list<string> */
    private function roleIds(string $userId): array
    {
        $today = now()->toDateString();

        return array_values(DB::table('user_roles')->where('user_id', $userId)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->pluck('role_id')->map(fn ($id) => (string) $id)->all());
    }

    /** A quien atiende: el asignado o, si no hay, los usuarios del rol del tipo de solicitud. */
    private function notifyStaff(Ticket $t, string $category): void
    {
        $recipients = [];
        if ($t->assignee_user_id !== null) {
            $recipients = [$t->assignee_user_id];
        } else {
            $role = TicketCategory::query()->withTrashed()->where('id', $t->ticket_category_id)->value('default_assignee_role_id');
            $role ??= DB::table('roles')->where('system_role_code', 'rrhh')->whereNull('deleted_at')->value('id');
            if (is_string($role)) {
                $recipients = $this->notifier->usersWithRole($role);
            }
        }
        $recipients = array_values(array_diff($recipients, [$t->requester_user_id]));
        if ($recipients === []) {
            return;
        }
        $category_ = TicketCategory::query()->withTrashed()->find($t->ticket_category_id);
        $requester = (string) (DB::table('users')->where('id', $t->requester_user_id)->value('display_name') ?? '');
        $this->notifier->notify($category, $recipients, [
            'number' => $t->number, 'subject' => $t->subject, 'employee' => $requester,
            'category' => $category_ instanceof TicketCategory ? $this->categories->name($category_) : '',
        ], 'ticket', $t->id);
    }

    private function notifyRequester(Ticket $t): void
    {
        $this->notifier->notify('tickets.updated', [$t->requester_user_id], [
            'number' => $t->number, 'subject' => $t->subject, 'status' => (string) trans('helpdesk.statuses.'.$t->status),
        ], 'ticket', $t->id);
    }
}
