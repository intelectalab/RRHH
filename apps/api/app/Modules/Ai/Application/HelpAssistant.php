<?php

namespace App\Modules\Ai\Application;

use App\Modules\Ai\Domain\AiPrompt;
use App\Modules\Ai\Domain\Ports\AiProvider;
use App\Shared\Exceptions\AyniException;
use App\Shared\Help\HelpCorpus;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * IAR-9 · Asistente de ayuda documental. Responde solo con el contenido del centro de ayuda (AYU) que el usuario
 * puede abrir, y lo cita. No consulta datos de colaboradores: sus únicas fuentes son los artículos visibles.
 *  - Sin fuente que aplique, lo dice y ofrece derivar la consulta a RRHH (AYU-6); no llama al proveedor.
 *  - Una cita fuera de las fuentes entregadas se descarta (si no queda ninguna, se responde como sin fuente).
 *  - Cada uso queda en la bitácora de IA con caso de uso «ayuda» (IAR-2).
 * IAR-10 · Solo responde si el plan lo incluye, la organización lo encendió, el rol del usuario está habilitado y
 * no llegó a su límite de preguntas; si no, la API lo rechaza (403 feature_not_enabled · 429).
 * Regla 13: la IA asiste, no decide; no ejecuta ninguna acción.
 */
final class HelpAssistant
{
    public const LOG_USE_CASE = 'ayuda';

    private const MAX_SOURCES = 3;

    private const STOPWORDS = ['como', 'cómo', 'para', 'que', 'qué', 'por', 'una', 'uno', 'los', 'las', 'del', 'con', 'mis', 'mi', 'puedo', 'debo',
        'hago', 'hacer', 'donde', 'dónde', 'cual', 'cuál', 'cuando', 'cuándo', 'este', 'esta', 'esto', 'hay', 'son', 'sus', 'the', 'how', 'what',
        'can', 'and', 'for', 'where', 'when', 'com', 'onde', 'posso', 'uma', 'meu', 'minha', 'sobre', 'quiero', 'necesito', 'tengo'];

    public function __construct(
        private readonly HelpCorpus $help,
        private readonly AiProvider $provider,
        private readonly AiUsageLog $log,
        private readonly AiUseCaseSettings $settings,
    ) {}

    /**
     * @return array{available: bool, notice: string|null, question_limit: int|null, limit_period: string|null, remaining: int|null}
     */
    public function availability(string $userId): array
    {
        $s = $this->settings->get(AiUseCaseSettings::HELP_ASSISTANT);
        $roles = DB::table('user_roles')->where('user_id', $userId)
            ->where(fn ($w) => $w->whereNull('valid_from')->orWhere('valid_from', '<=', now()->toDateString()))
            ->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>=', now()->toDateString()))
            ->pluck('role_id')->map(fn (mixed $id): string => (string) $id)->all();
        if (! $s['enabled'] || array_intersect($roles, $s['role_ids']) === []) {
            return ['available' => false, 'notice' => null, 'question_limit' => null, 'limit_period' => null, 'remaining' => null];
        }
        $remaining = null;
        if ($s['question_limit'] !== null) {
            $start = match ($s['limit_period']) {
                'week' => now()->startOfWeek(), 'month' => now()->startOfMonth(), default => now()->startOfDay()
            };
            $used = DB::table('ai_interactions')->where('user_id', $userId)->where('use_case', self::LOG_USE_CASE)
                ->where('was_blocked', false)->where('occurred_at', '>=', $start)->count();
            $remaining = max(0, $s['question_limit'] - $used);
        }

        return ['available' => true, 'notice' => $s['notice'], 'question_limit' => $s['question_limit'], 'limit_period' => $s['limit_period'], 'remaining' => $remaining];
    }

    /**
     * @param  array<int, string>  $modules
     * @return array<string, mixed>
     */
    public function ask(string $question, ?string $screenKey, string $userId, EffectivePermissions $permissions, array $modules, string $locale): array
    {
        $availability = $this->availability($userId);
        if (! $availability['available']) {
            throw new AyniException('feature_not_enabled', 403, (string) trans('errors.feature_not_enabled'), [], ['feature' => AiUseCaseSettings::USE_CASES[AiUseCaseSettings::HELP_ASSISTANT]]);
        }
        if ($availability['remaining'] === 0) {
            throw new AyniException('ai_question_limit_reached', 429, (string) trans('ai.help.limit_reached'));
        }

        $sources = $this->rank($question, $this->help->corpus($permissions, $modules, $locale));
        $byId = array_column($sources, null, 'id');
        $completion = null;
        $citations = [];
        if ($sources !== []) {
            $completion = $this->provider->answer(new AiPrompt(self::LOG_USE_CASE, $question, $locale,
                array_map(fn (array $a): array => ['id' => (string) $a['id'], 'title' => (string) $a['title'], 'body' => (string) $a['body']], $sources)));
            $citations = array_values(array_unique(array_filter($completion->citations, fn (string $id): bool => isset($byId[$id]))));
        }
        $answered = $completion !== null && $citations !== [] && trim($completion->text) !== '';
        if (! $answered) {
            DB::table('help_search_queries')->insert([
                'tenant_id' => TenancyScope::requireTenant(),
                'query_text' => mb_substr($question, 0, 200),
                'screen_key' => $screenKey,
                'role_code' => $permissions->systemRoles[0] ?? null,
                'result_count' => 0,
                'source' => 'assistant',
            ]);
        }

        $id = $this->log->record(
            useCase: self::LOG_USE_CASE,
            userId: $userId,
            question: $question,
            response: $answered ? $completion->text : null,
            dataScopes: ['help_articles'],
            permissions: array_values(array_filter(['ai.help_assistant.use', 'help.articles.view'], fn (string $p): bool => $permissions->has($p))),
            citations: $citations,
            provider: $this->provider->name(),
            model: $completion?->model,
            inputTokens: $completion->inputTokens ?? 0,
            outputTokens: $completion->outputTokens ?? 0,
        );

        return [
            'id' => $id,
            'question' => $question,
            'answered' => $answered,
            'answer' => $answered ? $completion->text : null,
            'explanation' => $answered ? $completion->explanation : (string) trans('ai.help.no_sources'),
            'citations' => array_map(fn (string $cid): array => [
                'id' => $cid,
                'slug' => $byId[$cid]['slug'] ?? null,
                'title' => (string) $byId[$cid]['title'],
                'source' => (string) ($byId[$cid]['source'] ?? 'product'),
            ], $answered ? $citations : []),
            'escalation' => ['offered' => ! $answered, 'available' => $permissions->has('help.escalations.create')],
            'provider_is_fake' => $this->provider->isFake(),
            'remaining' => $availability['remaining'] === null ? null : $availability['remaining'] - 1,
        ];
    }

    /**
     * @return array{interaction_id: string, rating: string}
     */
    public function feedback(string $interactionId, string $userId, string $rating, ?string $comment): array
    {
        $this->assertOwnAnswer($interactionId, $userId);
        $row = ['rating' => $rating, 'comment' => $comment === null ? null : mb_substr($comment, 0, 500), 'updated_at' => now()];
        $updated = DB::table('ai_feedback')->where('ai_interaction_id', $interactionId)->where('user_id', $userId)->update($row);
        if ($updated === 0) {
            DB::table('ai_feedback')->insert(['tenant_id' => TenancyScope::requireTenant(), 'ai_interaction_id' => $interactionId, 'user_id' => $userId, ...$row]);
        }

        return ['interaction_id' => $interactionId, 'rating' => $rating];
    }

    /**
     * Solo se valora una respuesta propia del asistente de ayuda (otra persona u organización: 404).
     */
    public function assertOwnAnswer(string $interactionId, string $userId): void
    {
        $exists = Str::isUuid($interactionId) && DB::table('ai_interactions')->where('id', $interactionId)->where('user_id', $userId)->where('use_case', self::LOG_USE_CASE)->exists();
        if (! $exists) {
            throw new AyniException('not_found', 404, (string) trans('errors.not_found'));
        }
    }

    /**
     * Fuentes que aplican a la pregunta: palabras clave (sin tildes ni mayúsculas) en título, resumen y texto.
     *
     * @param  list<array<string, mixed>>  $corpus
     * @return list<array<string, mixed>>
     */
    public function rank(string $question, array $corpus): array
    {
        $words = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $this->fold($question), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, array_map(fn (string $s): string => $this->fold($s), self::STOPWORDS), true),
        )));
        if ($words === []) {
            return [];
        }
        $scored = [];
        foreach ($corpus as $a) {
            $title = $this->fold((string) $a['title']);
            $summary = $this->fold((string) ($a['summary'] ?? ''));
            $body = $this->fold((string) $a['body']);
            $score = 0;
            foreach ($words as $w) {
                $stem = mb_substr($w, 0, max(4, mb_strlen($w) - 2));
                $score += (str_contains($title, $stem) ? 3 : 0) + (str_contains($summary, $stem) ? 2 : 0) + (str_contains($body, $stem) ? 1 : 0);
            }
            if ($score >= 2) {
                $scored[] = [$score, (string) ($a['slug'] ?? $a['id']), $a];
            }
        }
        usort($scored, fn (array $x, array $y): int => [$y[0], $x[1]] <=> [$x[0], $y[1]]);

        return array_slice(array_map(fn (array $x): array => $x[2], $scored), 0, self::MAX_SOURCES);
    }

    private function fold(string $text): string
    {
        return strtr(mb_strtolower(trim($text)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e',
            'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ã' => 'a', 'õ' => 'o', 'ç' => 'c']);
    }
}
