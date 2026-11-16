<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Infrastructure\Team\CelebrationTeamDates;
use App\Shared\Labor\MasterData;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\TeamLine;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Team\TeamDateRegistry;
use App\Shared\Tenancy\TenancyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * MSS-4 · Fechas clave del equipo: cumpleaños, aniversarios, fin del periodo de prueba y contratos por vencer de
 * quienes reportan al manager (RN-MSS-01), con recordatorios por el motor de notificaciones.
 *
 * - Cada fuente exige su permiso (contratos: contracts.contracts.view) y su módulo contratado.
 * - Los cumpleaños se ocultan en toda la organización con celebraciones.mostrar_cumpleanos = no; cuando se
 *   muestran viaja solo el día (nunca el año ni la edad) y la lectura queda en el registro de accesos (regla 7).
 * - Recordatorio: equipo.aviso_fechas_clave_dias antes de un cumpleaños o aniversario se avisa al jefe
 *   (team.birthday / team.anniversary); el fin del periodo de prueba y el vencimiento ya los avisa Contratos
 *   (CON-2, CON-4). Las preferencias de cada manager las aplica el motor de notificaciones.
 */
final class TeamKeyDates
{
    public const MAX_DAYS = 90;

    public const DEFAULT_DAYS = 30;

    public function __construct(
        private readonly TeamLine $team,
        private readonly TeamDateRegistry $registry,
        private readonly ModuleGate $modules,
        private readonly ParameterResolver $parameters,
        private readonly OutboxWriter $outbox,
        private readonly MasterData $masterData,
    ) {}

    /**
     * @param  list<string>  $kinds  vacío = todas las fuentes que el usuario puede ver
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(string $userId, EffectivePermissions $permissions, string $today, string $from, string $to, array $kinds, ?string $ip): array
    {
        $members = $this->team->members($userId, $today, $permissions->has('people.team.view_indirect'));
        $ids = array_keys($members);
        $birthdays = $this->showBirthdays();

        $available = [];
        $items = [];
        foreach ($this->registry->all() as $key => $source) {
            if ($key === 'birthday' && ! $birthdays) {
                continue;
            }
            $module = $this->modules->moduleOf($source->permission());
            if (! $permissions->has($source->permission()) || ($module !== null && ! $this->modules->isEnabled($module))) {
                continue;
            }
            $available[] = $key;
            if ($kinds !== [] && ! in_array($key, $kinds, true)) {
                continue;
            }
            foreach ($source->items($ids, $from, $to) as $item) {
                $items[] = ['kind' => $key] + $item;
            }
        }

        $names = $ids === [] ? collect() : DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')
            ->whereIn('e.id', array_values(array_unique(array_column($items, 'employment_id'))))
            ->get(['e.id', 'p.first_names', 'p.preferred_name', 'p.paternal_surname'])->keyBy('id');
        $data = [];
        foreach ($items as $item) {
            $p = $names->get($item['employment_id']);
            $data[] = [
                'kind' => $item['kind'],
                'date' => $item['date'],
                'employment_id' => $item['employment_id'],
                'name' => $p === null ? null : trim(($p->preferred_name ?: $p->first_names).' '.$p->paternal_surname),
                'is_direct' => ($members[$item['employment_id']] ?? 1) === 1,
                'years' => $item['years'] ?? null,
                'link' => '/personas/colaboradores/'.$item['employment_id'].(in_array($item['kind'], ['probation_end', 'contract_end'], true) ? '?tab=contracts' : ''),
            ];
        }
        usort($data, fn (array $a, array $b): int => [$a['date'], $a['kind'], (string) $a['name']] <=> [$b['date'], $b['kind'], (string) $b['name']]);

        if (in_array('birthday', array_column($data, 'kind'), true)) {
            DB::table('data_access_logs')->insert([
                'tenant_id' => TenancyScope::requireTenant(), 'user_id' => $userId, 'access_kind' => 'api', 'data_category' => 'other',
                'subject_person_id' => null, 'entity_type' => 'team_key_dates', 'entity_id' => null, 'fields' => '{birth_date}',
                'purpose' => 'manager:team_key_dates', 'ip' => $ip, 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
        }

        return ['data' => $data, 'meta' => ['from' => $from, 'to' => $to, 'kinds' => $available, 'birthdays_hidden' => ! $birthdays, 'team_size' => count($ids)]];
    }

    /**
     * Tarea diaria (team:key-date-reminders): registra un evento por cumpleaños o aniversario que cae dentro de
     * equipo.aviso_fechas_clave_dias; el suscriptor de notificaciones avisa al jefe. Idempotente: no repite el
     * mismo aviso (relación, tipo y fecha) aunque la tarea corra dos veces.
     */
    public function remind(string $today): int
    {
        $days = max(0, (int) ($this->parameters->value('equipo.aviso_fechas_clave_dias') ?? 1));
        $target = CarbonImmutable::parse($today)->addDays($days)->toDateString();
        $open = DB::table('employments')->whereIn('status', ['active', 'suspended', 'terminating'])->whereNull('deleted_at')
            ->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $sources = $this->showBirthdays() ? [CelebrationTeamDates::birthdays(), CelebrationTeamDates::anniversaries()] : [CelebrationTeamDates::anniversaries()];
        $count = 0;
        foreach ($sources as $source) {
            foreach (array_chunk($open, 500) as $chunk) {
                foreach ($source->items($chunk, $target, $target) as $item) {
                    $already = DB::table('outbox_events')->where('event_type', DomainEventTypes::TEAM_KEY_DATE_UPCOMING)
                        ->where('aggregate_type', 'employment')->where('aggregate_id', $item['employment_id'])
                        ->whereRaw("payload->>'kind' = ? AND payload->>'date' = ?", [$source->key(), $item['date']])->exists();
                    // Sin jefe vigente no hay a quién recordarle.
                    if ($already || $this->masterData->managerAt($item['employment_id'], $today) === null) {
                        continue;
                    }
                    $this->outbox->record(DomainEventTypes::TEAM_KEY_DATE_UPCOMING, 'employment', $item['employment_id'], [
                        'employment_id' => $item['employment_id'], 'kind' => $source->key(), 'date' => $item['date'], 'years' => $item['years'] ?? null,
                    ]);
                    $count++;
                }
            }
        }

        return $count;
    }

    private function showBirthdays(): bool
    {
        return (bool) ($this->parameters->value('celebraciones.mostrar_cumpleanos') ?? true);
    }
}
