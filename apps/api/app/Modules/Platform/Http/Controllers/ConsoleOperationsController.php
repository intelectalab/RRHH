<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Backups\Backups;
use App\Modules\Platform\Application\Console\ConsoleLogs;
use App\Modules\Platform\Application\Monitoring\PlatformHealth;
use App\Modules\Platform\Application\Settings\GlobalSettings;
use App\Modules\Platform\Application\Settings\PlatformNotices;
use App\Modules\Platform\Application\Support\SupportSessions;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SAS-8 · SAS-9 · SAS-12 · SAS-13 · Operación de la plataforma desde la consola: soporte auditado, monitoreo,
 * copias de seguridad, configuración global, avisos y registros.
 */
final class ConsoleOperationsController
{
    public function __construct(
        private readonly SupportSessions $support,
        private readonly PlatformHealth $health,
        private readonly Backups $backups,
        private readonly GlobalSettings $settings,
        private readonly PlatformNotices $notices,
        private readonly ConsoleLogs $logs,
    ) {}

    // ── SAS-8 · Soporte ─────────────────────────────────────────────────────────────────────────

    public function supportSessions(Request $request): JsonResponse
    {
        $request->validate($this->paging() + [
            'tenant_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', Rule::in(['pending', 'active', 'ended', 'expired'])],
        ]);

        return response()->json($this->support->list($this->q($request, 'tenant_id'), $this->q($request, 'status'), ...$this->page($request)));
    }

    public function supportSession(string $id): JsonResponse
    {
        return response()->json(['data' => $this->support->show($id)]);
    }

    public function createSupportSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'string'],
            'target_user_id' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'ticket_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer'],
        ]);

        return response()->json(['data' => $this->support->create([
            'tenant_id' => (string) $data['tenant_id'], 'target_user_id' => (string) $data['target_user_id'],
            'reason' => (string) $data['reason'], 'ticket_reference' => isset($data['ticket_reference']) ? (string) $data['ticket_reference'] : null,
            'duration_minutes' => isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : null,
        ], $this->actor($request), $request)], 201);
    }

    public function endSupportSession(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->support->end($id, $this->actor($request), isset($data['reason']) ? (string) $data['reason'] : null)]);
    }

    public function tenantUsers(string $id): JsonResponse
    {
        return response()->json(['data' => $this->support->tenantUsers($id)]);
    }

    // ── SAS-9 · Monitoreo ───────────────────────────────────────────────────────────────────────

    public function health(Request $request): JsonResponse
    {
        $request->validate(['tenant_id' => ['sometimes', 'nullable', 'uuid']]);

        return response()->json(['data' => $this->health->summary($this->q($request, 'tenant_id'))]);
    }

    public function failedJobs(Request $request): JsonResponse
    {
        $request->validate($this->paging() + ['tenant_id' => ['sometimes', 'nullable', 'uuid']]);

        return response()->json($this->health->failedJobs($this->q($request, 'tenant_id'), ...$this->page($request)));
    }

    public function retryFailedJob(Request $request, string $id): JsonResponse
    {
        $this->health->retryFailedJob($id, $this->actor($request));

        return response()->json(['data' => ['id' => $id, 'retried' => true]]);
    }

    public function outboxEvents(Request $request): JsonResponse
    {
        $request->validate([
            'tenant_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', Rule::in(['failed', 'dead'])],
        ]);

        return response()->json(['data' => $this->health->outboxEvents($this->q($request, 'tenant_id'), $this->q($request, 'status'))]);
    }

    public function retryOutboxEvent(Request $request, string $id): JsonResponse
    {
        $this->health->retryOutboxEvent($id, $this->actor($request));

        return response()->json(['data' => ['id' => $id, 'status' => 'pending']]);
    }

    // ── SAS-12 · Copias de seguridad ────────────────────────────────────────────────────────────

    public function backupList(Request $request): JsonResponse
    {
        $request->validate($this->paging() + [
            'tenant_id' => ['sometimes', 'nullable', 'uuid'],
            'kind' => ['sometimes', 'nullable', Rule::in(['automatic', 'manual'])],
            'status' => ['sometimes', 'nullable', Rule::in(['running', 'completed', 'failed', 'expired'])],
        ]);

        return response()->json($this->backups->list($this->q($request, 'tenant_id'), $this->q($request, 'kind'), $this->q($request, 'status'), ...$this->page($request)));
    }

    public function backupCreate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'coverage' => ['required', Rule::in(['platform', 'tenant'])],
            'tenant_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return response()->json(['data' => $this->backups->run('manual', (string) $data['coverage'], isset($data['tenant_id']) ? (string) $data['tenant_id'] : null, $this->actor($request))], 201);
    }

    public function backupShow(string $id): JsonResponse
    {
        return response()->json(['data' => $this->backups->show($id)]);
    }

    public function backupRestore(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'target' => ['sometimes', Rule::in(['sandbox', 'production'])],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return response()->json(['data' => $this->backups->requestRestore($id, [
            'target' => (string) ($data['target'] ?? 'sandbox'), 'reason' => (string) $data['reason'],
        ], $this->actor($request))], 201);
    }

    public function restoreList(Request $request): JsonResponse
    {
        $request->validate($this->paging() + [
            'status' => ['sometimes', 'nullable', Rule::in(['requested', 'approved', 'running', 'completed', 'failed', 'cancelled'])],
        ]);

        return response()->json($this->backups->restores($this->q($request, 'status'), ...$this->page($request)));
    }

    public function restoreApprove(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->backups->approve($id, $this->actor($request))]);
    }

    public function restoreCancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->backups->cancel($id, $this->actor($request), isset($data['reason']) ? (string) $data['reason'] : null)]);
    }

    // ── SAS-13 · Configuración global, avisos y registros ───────────────────────────────────────

    public function settingsIndex(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all()]);
    }

    public function settingsUpdate(Request $request, string $key): JsonResponse
    {
        $request->validate(['value' => ['present']]);

        return response()->json(['data' => $this->settings->update($key, $request->input('value'), $this->actor($request))]);
    }

    public function noticeIndex(): JsonResponse
    {
        return response()->json(['data' => $this->notices->list()]);
    }

    public function noticeStore(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->notices->create($this->noticeInput($request, true), $this->actor($request))], 201);
    }

    public function noticeUpdate(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->notices->update($id, $this->noticeInput($request, false), $this->actor($request))]);
    }

    public function noticeCancel(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->notices->cancel($id, $this->actor($request))]);
    }

    public function activity(Request $request): JsonResponse
    {
        $request->validate($this->paging() + [
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
            'user_id' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);

        return response()->json($this->logs->activity((string) $request->query('tenant_id'), $this->q($request, 'user_id'), $this->q($request, 'from'), $this->q($request, 'to'), ...$this->page($request)));
    }

    public function technicalLogs(Request $request): JsonResponse
    {
        $request->validate([
            'tenant_id' => ['sometimes', 'nullable', 'uuid'],
            'user_id' => ['sometimes', 'nullable', 'uuid'],
            'level' => ['sometimes', 'nullable', Rule::in(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'])],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        return response()->json($this->logs->technical($this->q($request, 'tenant_id'), $this->q($request, 'user_id'), $this->q($request, 'level'),
            $this->q($request, 'from'), $this->q($request, 'to'), (int) $request->query('limit', '200')));
    }

    /**
     * @return array<string, mixed>
     */
    private function noticeInput(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$req, 'string', 'max:160'],
            'message' => [$req, 'string', 'max:2000'],
            'severity' => ['sometimes', Rule::in(['info', 'warning', 'critical'])],
            'audience' => ['sometimes', Rule::in(['all', 'selected'])],
            'tenant_ids' => ['sometimes', 'array', 'max:500'],
            'tenant_ids.*' => ['string'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    private function paging(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function page(Request $request): array
    {
        return [(int) $request->query('page', '1'), (int) $request->query('per_page', '25')];
    }

    private function q(Request $request, string $key): ?string
    {
        $v = $request->query($key);

        return $v === null || $v === '' ? null : (string) $v;
    }

    private function actor(Request $request): PlatformUser
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }
}
