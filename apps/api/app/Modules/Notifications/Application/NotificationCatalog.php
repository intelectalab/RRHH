<?php

namespace App\Modules\Notifications\Application;

use App\Shared\Settings\ParameterResolver;
use Illuminate\Support\Carbon;

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
        $message = $this->messages()[$code] ?? null;
        if ($message !== null) {
            return ['variables' => $message['variables'], 'link' => '/', 'channels' => $this->messageChannels(), 'mandatory' => true, 'urgent' => true];
        }

        return $this->categories()[$code] ?? null;
    }

    /**
     * NOT-5 · Mensajes directos (códigos de ingreso y activación) que se envían por SMS o WhatsApp.
     *
     * @return array<string, array{variables: array<int, string>}>
     */
    public function messages(): array
    {
        /** @var array<string, array{variables: array<int, string>}> */
        return (array) config('notifications.messages', []);
    }

    public function isMessage(string $code): bool
    {
        return array_key_exists($code, $this->messages());
    }

    /**
     * @return array<int, string>
     */
    public function messageChannels(): array
    {
        return array_values((array) config('notifications.message_channels', ['sms', 'whatsapp']));
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
        return app(ParameterResolver::class)->value($code);
    }
}
