<?php

namespace App\Modules\HelpCenter\Application;

use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Help\HrRequests;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\SelfService\OwnEmployments;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AYU-6 · RN-AYU-06 · Si la ayuda no resuelve la duda, el usuario la deriva:
 *  - a RRHH: se abre una solicitud (ESS-5) con la pregunta, la pantalla y los artículos consultados, y RRHH la
 *    atiende en su bandeja; el estado de la consulta sigue al de esa solicitud. Requiere relación laboral.
 *  - al soporte de GestiaHR: solo el administrador (help.escalations.support); la incidencia llega a la consola.
 */
final class HelpEscalations
{
    public function __construct(
        private readonly HrRequests $requests,
        private readonly OwnEmployments $employments,
        private readonly HelpLibrary $help,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{question: string, target?: string|null, screen_key?: string|null, consulted_articles?: list<string>|null}  $input
     * @param  array<int, string>  $modules
     * @return array<string, mixed>
     */
    public function create(array $input, string $userId, EffectivePermissions $permissions, array $modules, string $locale): array
    {
        $target = $input['target'] ?? 'hr';
        if ($target === 'platform_support' && ! $permissions->has('help.escalations.support')) {
            throw new AyniException('forbidden', 403, (string) trans('help.support_admin_only'));
        }
        $employment = null;
        if ($target === 'hr') {
            $employment = $this->employments->pick($userId, null);
            if ($employment === null) {
                throw ValidationException::withMessages(['target' => [(string) trans('help.no_employment')]]);
            }
        }

        // Solo se guardan los artículos consultados que el usuario realmente puede ver.
        $consulted = [];
        foreach (array_slice(array_values(array_unique($input['consulted_articles'] ?? [])), 0, 10) as $id) {
            try {
                $a = $this->help->article((string) $id, $permissions, $modules, $locale);
                $consulted[] = ['id' => (string) $a['id'], 'title' => (string) $a['title'], 'source' => (string) $a['source']];
            } catch (\Throwable) {
                continue;
            }
        }
        $question = trim($input['question']);
        $screen = $input['screen_key'] ?? null;

        $id = DB::transaction(function () use ($userId, $target, $question, $screen, $consulted, $employment): string {
            $id = (string) DB::table('help_escalations')->insertGetId([
                'tenant_id' => TenancyScope::requireTenant(),
                'user_id' => $userId,
                'target' => $target,
                'question' => $question,
                'screen_key' => $screen,
                'consulted_articles' => json_encode($consulted, JSON_UNESCAPED_UNICODE),
                'status' => 'open',
            ]);
            $subject = (string) trans('help.escalation_subject', ['question' => mb_strimwidth($question, 0, 80, '…')]);
            $body = $this->body($question, $screen, $consulted);
            if ($target === 'hr') {
                $this->requests->open($id, $userId, $employment['id'] ?? null, $subject, $body);
            } else {
                // El correo del usuario está cifrado en la organización: la incidencia lleva su nombre en el texto.
                $name = (string) DB::table('users')->where('id', $userId)->value('display_name');
                $body .= "\n\n".(string) trans('help.escalation_from', ['name' => $name]);
                $ticket = DB::selectOne('SELECT open_support_ticket(?, ?, ?) AS id', [$subject, $body, null]);
                DB::table('help_escalations')->where('id', $id)->update(['support_ticket_id' => $ticket?->id]);
            }
            $this->audit->record('help.escalated', 'help_escalation', $id, null, ['target' => $target, 'screen_key' => $screen, 'consulted' => count($consulted)]);

            return $id;
        });

        return $this->list($userId, $id)[0];
    }

    /**
     * Consultas propias con su estado (el de la solicitud a RRHH o el de la incidencia de soporte).
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $userId, ?string $only = null): array
    {
        $rows = DB::table('help_escalations')->where('user_id', $userId)
            ->when($only !== null, fn ($w) => $w->where('id', $only))
            ->orderByDesc('created_at')->limit(50)->get();
        $requests = $this->requests->forEscalations(array_values(array_map(fn (object $r): string => (string) $r->id, $rows->all())));

        return array_values($rows->map(function (object $r) use ($requests): array {
            $request = $requests[(string) $r->id] ?? null;
            $raw = $r->target === 'hr'
                ? ($request['status'] ?? null)
                : ($r->support_ticket_id === null ? null : DB::selectOne('SELECT support_ticket_status(?) AS s', [$r->support_ticket_id])?->s);

            return [
                'id' => (string) $r->id,
                'target' => (string) $r->target,
                'question' => (string) $r->question,
                'screen_key' => $r->screen_key,
                'consulted_articles' => is_string($r->consulted_articles) ? (array) json_decode($r->consulted_articles, true) : [],
                'status' => $this->status(is_string($raw) ? $raw : null, (string) $r->status),
                'request_id' => $request['id'] ?? null,
                'request_number' => $request['number'] ?? null,
                'created_at' => Carbon::parse((string) $r->created_at)->toIso8601String(),
            ];
        })->all());
    }

    /** Estado de la consulta según el de su solicitud: abierta, respondida o cerrada. */
    private function status(?string $raw, string $stored): string
    {
        return match ($raw) {
            'resolved' => 'answered',
            'closed', 'cancelled' => 'closed',
            null => $stored,
            default => 'open',
        };
    }

    /**
     * @param  list<array{id: string, title: string, source: string}>  $consulted
     */
    private function body(string $question, ?string $screen, array $consulted): string
    {
        $lines = [$question];
        if ($screen !== null && $screen !== '') {
            $lines[] = '';
            $lines[] = (string) trans('help.escalation_screen', ['screen' => $screen]);
        }
        if ($consulted !== []) {
            $lines[] = '';
            $lines[] = (string) trans('help.escalation_consulted');
            foreach ($consulted as $a) {
                $lines[] = '- '.$a['title'];
            }
        }

        return implode("\n", $lines);
    }
}
