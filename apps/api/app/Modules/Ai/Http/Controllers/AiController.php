<?php

namespace App\Modules\Ai\Http\Controllers;

use App\Modules\Ai\Application\AiUsageLog;
use App\Modules\Ai\Application\AiUseCaseSettings;
use App\Modules\Ai\Application\HelpAssistant;
use App\Modules\Ai\Application\HelpAssistantQuality;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * IAR-2 · IAR-9 · IAR-10 · IAR-11 · Asistente de ayuda, su activación, la bitácora de IA y la calidad.
 */
final class AiController
{
    public function __construct(
        private readonly HelpAssistant $assistant,
        private readonly AiUseCaseSettings $settings,
        private readonly AiUsageLog $log,
        private readonly HelpAssistantQuality $quality,
        private readonly ModuleGate $modules,
        private readonly PermissionResolver $resolver,
    ) {}

    public function availability(Request $request): JsonResponse
    {
        $permissions = $this->permissions($request);
        $data = $permissions->has('ai.help_assistant.use') ? $this->assistant->availability($this->userId($request))
            : ['available' => false, 'notice' => null, 'question_limit' => null, 'limit_period' => null, 'remaining' => null];

        return response()->json(['data' => $data]);
    }

    public function ask(Request $request): JsonResponse
    {
        $data = $this->validate($request->all(), ['question' => ['required', 'string', 'min:3', 'max:500'], 'screen_key' => ['sometimes', 'nullable', 'string', 'max:80']]);
        $screen = $data['screen_key'] ?? null;

        return response()->json(['data' => $this->assistant->ask(trim((string) $data['question']), is_string($screen) ? $screen : null, $this->userId($request),
            $this->permissions($request), $this->modules->activeModules(), $this->locale($request))]);
    }

    public function feedback(Request $request, string $id): JsonResponse
    {
        $this->assistant->assertOwnAnswer($id, $this->userId($request));
        $data = $this->validate($request->all(), ['rating' => ['required', Rule::in(['up', 'down'])], 'comment' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $comment = $data['comment'] ?? null;

        return response()->json(['data' => $this->assistant->feedback($id, $this->userId($request), (string) $data['rating'], is_string($comment) ? $comment : null)]);
    }

    public function quality(): JsonResponse
    {
        return response()->json(['data' => $this->quality->summary()]);
    }

    public function settingsIndex(): JsonResponse
    {
        $rows = $this->settings->list();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function settingsShow(string $id): JsonResponse
    {
        return response()->json(['data' => $this->settings->get($id)]);
    }

    public function settingsUpdate(Request $request, string $id): JsonResponse
    {
        $this->settings->get($id);
        $data = $this->validate($request->all(), [
            'enabled' => ['sometimes', 'boolean'],
            'role_ids' => ['sometimes', 'array', 'max:50'],
            'role_ids.*' => ['uuid'],
            'question_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'limit_period' => ['sometimes', Rule::in(['day', 'week', 'month'])],
            'notice' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        /** @var array{enabled?: bool, role_ids?: list<string>, question_limit?: int|null, limit_period?: string, notice?: string|null} $data */
        if (isset($data['enabled'])) {
            $data['enabled'] = (bool) $data['enabled'];
        }

        return response()->json(['data' => $this->settings->update($id, $data, $this->userId($request))]);
    }

    public function logsIndex(Request $request): JsonResponse
    {
        $filter = $this->validate((array) ($request->query('filter') ?? []), [
            'use_case' => ['sometimes', 'string', 'max:40'], 'user_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $page = max(1, (int) $request->query('page', '1'));
        /** @var array{use_case?: string|null, user_id?: string|null, from?: string|null, to?: string|null} $filter */
        $result = $this->log->list($filter, $page);

        return response()->json(['data' => $result['data'], 'meta' => ['total' => $result['total'], 'page' => $page]]);
    }

    public function logsShow(string $id): JsonResponse
    {
        return response()->json(['data' => $this->log->show($id)]);
    }

    /**
     * @param  array<mixed>  $input
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $input, array $rules): array
    {
        /** @var array<string, mixed> */
        return Validator::make($input, $rules)->validate();
    }

    private function userId(Request $request): string
    {
        $user = $request->user('ayni');
        abort_if($user === null, 401);

        return (string) $user->getAuthIdentifier();
    }

    private function locale(Request $request): string
    {
        $user = $request->user();
        $locale = is_object($user) && isset($user->locale) && is_string($user->locale) ? $user->locale : app()->getLocale();

        return in_array($locale, (array) config('help.locales'), true) ? $locale : (string) config('help.fallback_locale', 'es');
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : $this->resolver->for($this->userId($request));
    }
}
