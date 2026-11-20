<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Modules\Documents\Application\DocumentAccess;
use App\Modules\Documents\Application\SignatureService;
use App\Modules\Documents\Domain\SignatureRequestStateMachine as M;
use App\Modules\Documents\Infrastructure\Models\SignatureRequest;
use App\Modules\Documents\Infrastructure\Models\SignatureRequestSigner;
use App\Shared\Exceptions\AyniException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * DOC-6 · DOC-7 · DOC-8 · Solicitudes de firma. RRHH las crea, envía, recuerda y cancela sobre documentos de su
 * alcance (fuera de él, 404) y ve la evidencia. El firmante usa /me/signature-requests: ve lo suyo, abre el
 * documento, pide el código y firma o rechaza (desde el portal y el teléfono).
 */
final class SignatureRequestController
{
    private const STATUSES = [M::DRAFT, M::SENT, M::VIEWED, M::IN_PROGRESS, M::COMPLETED, M::DECLINED, M::EXPIRED, M::CANCELLED];

    public function __construct(
        private readonly SignatureService $signatures,
        private readonly DocumentAccess $access,
        private readonly M $machine,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', 'string', 'max:200'],
            'filter.document_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $statuses = isset($input['filter']['status']) ? explode(',', (string) $input['filter']['status']) : [];
        if (array_diff($statuses, self::STATUSES) !== []) {
            throw new AyniException('invalid_filter', 400, (string) trans('documents.documents.invalid_filter'));
        }
        $query = $this->scoped($request)
            ->when($statuses !== [], fn ($q) => $q->whereIn('status', $statuses))
            ->when(isset($input['filter']['document_id']), fn ($q) => $q->where('document_id', $input['filter']['document_id']));
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 25);
        $total = (clone $query)->count();
        $rows = $query->with('signers')->orderByDesc('created_at')->forPage($page, $perPage)->get();

        return response()->json(['data' => $rows->map(fn (SignatureRequest $r) => $this->present($r, null))->values(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'next_cursor' => null]]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'document_id' => ['required', 'uuid'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'signing_order' => ['sometimes', 'in:sequential,parallel'],
            'signers' => ['required', 'array', 'min:1', 'max:10'],
            'signers.*.user_id' => ['required', 'uuid'],
            'signers.*.role' => ['sometimes', 'nullable', 'string', 'max:60'],
            'send' => ['sometimes', 'boolean'],
        ]);
        $this->access->find($request, (string) $input['document_id'], 'documents.signature_requests.create');
        if (($input['send'] ?? false) === true && ! $this->access->permissions($request)->has('documents.signature_requests.send')) {
            throw new AyniException('forbidden', 403);
        }
        /** @var array{document_id: string, title?: string|null, signing_order?: string|null, signers: list<array{user_id: string, role?: string|null}>, send?: bool} $input */
        $created = $this->signatures->create($input, $this->access->userId($request));

        return response()->json(['data' => $this->present($created->load('signers'), null)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($request, $id), null)]);
    }

    public function send(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->signatures->send($this->find($request, $id), $this->access->userId($request)));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $r = $this->find($request, $id);
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->respond($this->signatures->cancel($r, (string) $input['reason'], $this->access->userId($request)));
    }

    public function remind(Request $request, string $id): JsonResponse
    {
        $r = $this->find($request, $id);
        $this->signatures->remind($r, $this->access->userId($request));

        return $this->respond($r);
    }

    public function evidence(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->signatures->evidenceOf($this->find($request, $id))]);
    }

    public function document(Request $request, string $id): Response
    {
        return $this->file($this->signatures->signedVersion($this->find($request, $id), $this->access->userId($request)));
    }

    public function certificate(Request $request, string $id): Response
    {
        $file = $this->signatures->certificate($this->find($request, $id), $this->access->userId($request));
        abort_if($file === null, 404);

        return $this->file($file);
    }

    // ---------- Firmante (portal) ----------

    public function mine(Request $request): JsonResponse
    {
        $userId = (string) $this->access->userId($request);
        $rows = SignatureRequest::query()->whereIn('id', SignatureRequestSigner::query()->where('user_id', $userId)->select('signature_request_id'))
            ->where('status', '<>', M::DRAFT)->with('signers')
            ->orderByRaw("CASE WHEN status IN ('sent','viewed','in_progress') THEN 0 ELSE 1 END")->orderByDesc('sent_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (SignatureRequest $r) => $this->present($r, $userId))->values()]);
    }

    public function mineShow(Request $request, string $id): JsonResponse
    {
        $r = $this->mineFind($request, $id);

        return response()->json(['data' => $this->present($r, (string) $this->access->userId($request))]);
    }

    public function mineDocument(Request $request, string $id): Response
    {
        $r = $this->mineFind($request, $id);

        $userId = $this->access->userId($request);
        $certificate = $r->status === M::COMPLETED ? $this->signatures->certificate($r, $userId) : null;

        return $this->file($certificate ?? $this->signatures->signedVersion($r, $userId));
    }

    public function mineView(Request $request, string $id): JsonResponse
    {
        $userId = (string) $this->access->userId($request);
        $r = $this->signatures->view($this->mineFind($request, $id), $userId, $request->ip(), $request->userAgent());

        return response()->json(['data' => $this->present($r->load('signers'), $userId)]);
    }

    public function mineCode(Request $request, string $id): JsonResponse
    {
        $userId = (string) $this->access->userId($request);
        $sent = $this->signatures->sendCode($this->mineFind($request, $id), $userId, $request->ip(), $request->userAgent());

        return response()->json(['data' => $sent]);
    }

    public function mineSign(Request $request, string $id): JsonResponse
    {
        $found = $this->mineFind($request, $id);
        $input = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $userId = (string) $this->access->userId($request);
        $r = $this->signatures->sign($found, $userId, (string) $input['code'], $request->ip(), $request->userAgent());

        return response()->json(['data' => $this->present($r->load('signers'), $userId)]);
    }

    public function mineDecline(Request $request, string $id): JsonResponse
    {
        $found = $this->mineFind($request, $id);
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $userId = (string) $this->access->userId($request);
        $r = $this->signatures->decline($found, $userId, (string) $input['reason'], $request->ip(), $request->userAgent());

        return response()->json(['data' => $this->present($r->load('signers'), $userId)]);
    }

    /** @return Builder<SignatureRequest> */
    private function scoped(Request $request): Builder
    {
        return SignatureRequest::query()->whereIn('document_id', $this->access->scoped($request, 'documents.signature_requests.view')->select('documents.id'));
    }

    private function find(Request $request, string $id): SignatureRequest
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $r = $this->scoped($request)->whereKey($id)->with('signers')->first();
        abort_unless($r instanceof SignatureRequest, 404);

        return $r;
    }

    private function mineFind(Request $request, string $id): SignatureRequest
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $r = SignatureRequest::query()->whereKey($id)->where('status', '<>', M::DRAFT)
            ->whereIn('id', SignatureRequestSigner::query()->where('user_id', (string) $this->access->userId($request))->select('signature_request_id'))
            ->with('signers')->first();
        abort_unless($r instanceof SignatureRequest, 404);

        return $r;
    }

    private function respond(SignatureRequest $r): JsonResponse
    {
        return response()->json(['data' => $this->present($r->load('signers'), null)]);
    }

    /** @param array{name: string, mime: string, contents: string} $file */
    private function file(array $file): Response
    {
        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $file['name']).'"',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(SignatureRequest $r, ?string $viewerUserId): array
    {
        $doc = DB::table('documents as d')->leftJoin('document_categories as c', 'c.id', '=', 'd.document_category_id')
            ->where('d.id', $r->document_id)->first(['d.title', 'd.employment_id', 'd.subject_type', 'c.name as category_name']);
        $turn = array_map(fn (SignatureRequestSigner $s) => $s->id, $this->signatures->turn($r));
        $out = [
            'id' => $r->id, 'document_id' => $r->document_id, 'document_title' => $doc?->title, 'category_name' => $doc?->category_name,
            'employment_id' => $doc?->employment_id, 'subject_type' => $doc?->subject_type, 'title' => (string) $r->title,
            'signing_order' => $r->signing_order, 'signature_kind' => $r->provider === 'external' ? 'certificate' : 'electronic',
            'signature_level' => $r->signature_level, 'status' => $r->status, 'sent_at' => $r->sent_at?->toIso8601String(),
            'expires_at' => $r->expires_at?->toIso8601String(), 'completed_at' => $r->completed_at?->toIso8601String(),
            'final_hash' => $r->final_hash, 'cancellation_reason' => $r->cancellation_reason, 'has_certificate' => $r->final_file_id !== null,
            'signers' => $r->signers->map(fn (SignatureRequestSigner $s) => [
                'id' => $s->id, 'user_id' => $s->user_id, 'name' => $s->signer_name, 'role' => $s->signer_role, 'order' => $s->sort_order,
                'status' => $s->status, 'viewed_at' => $s->viewed_at?->toIso8601String(), 'signed_at' => $s->signed_at?->toIso8601String(),
                'declined_reason' => $s->declined_reason, 'is_turn' => in_array($s->id, $turn, true),
            ])->values()->all(),
            'allowed_actions' => array_values(array_intersect($this->machine->allowedActions($r->status), ['send', 'cancel', 'remind'])),
            'version' => $r->lock_version,
        ];
        if ($viewerUserId !== null) {
            $mine = $r->signers->first(fn (SignatureRequestSigner $s) => $s->user_id === $viewerUserId);
            $out['my_turn'] = $mine instanceof SignatureRequestSigner && in_array($mine->id, $turn, true) && in_array($r->status, M::OPEN, true);
            $out['my_status'] = $mine?->status;
        }

        return $out;
    }
}
