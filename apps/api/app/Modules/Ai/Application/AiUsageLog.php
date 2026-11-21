<?php

namespace App\Modules\Ai\Application;

use App\Shared\Exceptions\AyniException;
use App\Shared\Security\FieldCipher;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * IAR-2 · Bitácora de uso de IA (ai_interactions, solo inserción). Cada uso deja una fila: quién, para qué (caso
 * de uso), con qué alcance de datos y permisos, qué fuentes citó, qué proveedor y modelo, y cuántos tokens.
 * La pregunta y la respuesta se guardan cifradas y solo hasta la retención (ia.retencion_bitacora_dias); el
 * listado para el administrador y el oficial de privacidad muestra los metadatos, nunca el contenido.
 */
final class AiUsageLog
{
    private const PAGE = 50;

    public function __construct(private readonly ParameterResolver $parameters) {}

    /**
     * @param  list<string>  $dataScopes
     * @param  list<string>  $permissions
     * @param  list<string>  $citations
     */
    public function record(
        string $useCase,
        ?string $userId,
        string $question,
        ?string $response,
        array $dataScopes,
        array $permissions,
        array $citations,
        string $provider,
        ?string $model,
        int $inputTokens,
        int $outputTokens,
        ?string $blockReason = null,
    ): string {
        return (string) DB::table('ai_interactions')->insertGetId([
            'tenant_id' => TenancyScope::requireTenant(),
            'user_id' => $userId,
            'actor_kind' => 'user',
            'use_case' => $useCase,
            'prompt_enc' => FieldCipher::encrypt(mb_substr($question, 0, 2000)),
            'response_enc' => $response === null ? null : FieldCipher::encrypt($response),
            'data_scopes' => $this->pgArray($dataScopes),
            'permissions_snapshot' => $this->pgArray($permissions),
            'referenced_entities' => json_encode(['help_articles' => $citations]),
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'was_blocked' => $blockReason !== null,
            'block_reason' => $blockReason,
            'high_impact_flag' => false,
        ], 'id');
    }

    /**
     * @param  array{use_case?: string|null, user_id?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function list(array $filters, int $page = 1): array
    {
        $q = $this->query();
        foreach (['use_case' => 'i.use_case', 'user_id' => 'i.user_id'] as $key => $col) {
            if (($filters[$key] ?? null) !== null) {
                $q->where($col, $filters[$key]);
            }
        }
        if (($filters['from'] ?? null) !== null) {
            $q->where('i.occurred_at', '>=', $filters['from']);
        }
        if (($filters['to'] ?? null) !== null) {
            $q->where('i.occurred_at', '<', now()->parse((string) $filters['to'])->addDay());
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('i.occurred_at')->forPage(max(1, $page), self::PAGE)->get();

        return ['data' => array_values(array_map(fn (\stdClass $r): array => $this->present($r), $rows->all())), 'total' => $total];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        $row = Str::isUuid($id) ? $this->query()->where('i.id', $id)->first() : null;
        if ($row === null) {
            throw new AyniException('not_found', 404, (string) trans('errors.not_found'));
        }

        return $this->present($row);
    }

    /**
     * Vacía el contenido vencido de la organización activa (la fila se conserva). Devuelve cuántas filas vació.
     */
    public function purge(): int
    {
        $days = (int) ($this->parameters->value('ia.retencion_bitacora_dias') ?? 365);
        $row = DB::selectOne('SELECT purge_ai_interactions(?) AS n', [now()->subDays(max(1, $days))->toIso8601String()]);

        return (int) ($row->n ?? 0);
    }

    private function query(): Builder
    {
        return DB::table('ai_interactions as i')->leftJoin('users as u', 'u.id', '=', 'i.user_id')
            ->select(['i.id', 'i.user_id', 'u.display_name as user_name', 'i.use_case', 'i.provider', 'i.model', 'i.data_scopes',
                'i.input_tokens', 'i.output_tokens', 'i.was_blocked', 'i.block_reason', 'i.referenced_entities', 'i.occurred_at',
                DB::raw("(i.prompt_enc = '') AS content_purged")]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $r): array
    {
        $refs = is_string($r->referenced_entities) ? json_decode($r->referenced_entities, true) : null;

        return [
            'id' => (string) $r->id,
            'user_id' => $r->user_id,
            'user_name' => $r->user_name,
            'use_case' => (string) $r->use_case,
            'provider' => $r->provider,
            'model' => $r->model,
            'data_scopes' => $this->fromPg($r->data_scopes),
            'input_tokens' => $r->input_tokens === null ? null : (int) $r->input_tokens,
            'output_tokens' => $r->output_tokens === null ? null : (int) $r->output_tokens,
            'was_blocked' => (bool) $r->was_blocked,
            'block_reason' => $r->block_reason,
            'citations' => is_array($refs) && is_array($refs['help_articles'] ?? null) ? array_values($refs['help_articles']) : [],
            'content_purged' => (bool) $r->content_purged,
            'occurred_at' => now()->parse((string) $r->occurred_at)->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $values
     */
    private function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn (string $v): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"', $values)).'}';
    }

    /**
     * @return list<string>
     */
    private function fromPg(mixed $value): array
    {
        if (! is_string($value) || $value === '{}' || $value === '') {
            return [];
        }

        return array_map(fn (string $v): string => trim($v, '"'), explode(',', trim($value, '{}')));
    }
}
