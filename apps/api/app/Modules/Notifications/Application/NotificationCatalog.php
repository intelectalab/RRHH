<?php

namespace App\Modules\Notifications\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de tipos de aviso (config/notifications.php) más las decisiones de la organización:
 * avisos que declara obligatorios y horario de silencio (parámetros notificaciones.*).
 */
final class NotificationCatalog
{
    public const LOCALES = ['es', 'en', 'pt'];

    /**
     * @return array<string, array{variables: array<int, string>, link: string, channels: array<int, string>, mandatory: bool, urgent: bool}>
     */
    public function categories(): array
    {
        /** @var array<string, array{variables: array<int, string>, link: string, channels: array<int, string>, mandatory: bool, urgent: bool}> */
        return (array) config('notifications.categories', []);
    }

    /**
     * @return array{variables: array<int, string>, link: string, channels: array<int, string>, mandatory: bool, urgent: bool}|null
     */
    public function category(string $code): ?array
    {
        return $this->categories()[$code] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function channels(): array
    {
        // Canales con adaptador en el producto ∩ canales que la organización activó.
        $active = $this->setting('notificaciones.canales_activos');
        $channels = (array) config('notifications.channels', ['in_app']);
        if (is_array($active)) {
            $channels = array_filter($channels, fn ($c) => $c === 'in_app' || in_array($c, $active, true));
        }

        return array_values($channels);
    }

    /**
     * Variables que admite la plantilla de un tipo: las propias más las comunes.
     *
     * @return array<int, string>
     */
    public function variables(string $code): array
    {
        return array_values(array_unique(array_merge((array) ($this->category($code)['variables'] ?? []), (array) config('notifications.common_variables', []))));
    }

    /** RN-NOT-05: obligatorio por producto o porque la organización lo declaró así. */
    public function isMandatory(string $code): bool
    {
        if (($this->category($code)['mandatory'] ?? false) === true) {
            return true;
        }

        return in_array($code, (array) ($this->setting('notificaciones.obligatorias') ?? []), true);
    }

    /**
     * RN-NOT-03: si ahora cae en el horario de silencio de la organización, el momento en que termina.
     */
    public function quietUntil(\DateTimeInterface $now): ?Carbon
    {
        $from = $this->setting('notificaciones.silencio_desde');
        $to = $this->setting('notificaciones.silencio_hasta');
        if (! is_string($from) || ! is_string($to) || preg_match('/^\d{2}:\d{2}$/', $from) !== 1 || preg_match('/^\d{2}:\d{2}$/', $to) !== 1 || $from === $to) {
            return null;
        }
        $tz = (string) ($this->setting('org.zona_horaria') ?? 'UTC');
        $local = Carbon::instance(\DateTime::createFromInterface($now))->setTimezone($tz);
        $start = $local->copy()->setTimeFromTimeString($from);
        $end = $local->copy()->setTimeFromTimeString($to);

        if ($from < $to) {
            // Silencio dentro del mismo día (p. ej. 13:00–14:00).
            return $local->betweenIncluded($start, $end->copy()->subSecond()) ? $end->utc() : null;
        }
        // Silencio que cruza la medianoche (p. ej. 20:00–07:00).
        if ($local->gte($start)) {
            return $end->addDay()->utc();
        }

        return $local->lt($end) ? $end->utc() : null;
    }

    /**
     * Valor vigente de un parámetro de la organización (o su valor por defecto del catálogo).
     */
    public function setting(string $code): mixed
    {
        $today = now()->toDateString();
        $value = DB::table('tenant_settings')->where('parameter_code', $code)->where('scope_kind', 'organization')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->orderByDesc('valid_from')->value('value');
        $value ??= DB::table('parameter_definitions')->where('code', $code)->value('default_value');

        return is_string($value) ? json_decode($value, true) : $value;
    }
}
