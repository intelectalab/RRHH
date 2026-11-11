<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Infrastructure\Models\NotificationTemplate;
use Illuminate\Support\Carbon;

/**
 * Aplica la plantilla de la organización (o la del producto) en el idioma del destinatario.
 */
final class TemplateRenderer
{
    /**
     * @param  array<string, string|int|null>  $variables
     * @return array{subject: string, body: string}
     */
    public function render(string $category, string $channel, string $locale, array $variables): array
    {
        $locale = in_array($locale, NotificationCatalog::LOCALES, true) ? $locale : 'es';
        [$subject, $body] = $this->source($category, $channel, $locale);

        // El tipo de trámite se muestra con su nombre en el idioma del destinatario.
        if (isset($variables['type']) && is_string($variables['type'])) {
            $key = 'notifications.subject_types.'.$variables['type'];
            $label = trans($key, [], $locale);
            $variables['type'] = $label === $key ? (string) trans('notifications.subject_types.other', [], $locale) : (string) $label;
        }

        // Fechas laborales (AAAA-MM-DD) en el formato del idioma: «13 de noviembre de 2026».
        foreach ($variables as $k => $v) {
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
                $date = Carbon::parse($v);
                $date->setLocale($locale);
                $variables[$k] = $date->isoFormat('LL');
            }
        }

        return ['subject' => $this->fill($subject, $variables), 'body' => $this->fill($body, $variables)];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function source(string $category, string $channel, string $locale): array
    {
        /** @var NotificationTemplate|null $custom */
        $custom = NotificationTemplate::query()->where('code', $category)->where('channel', $channel)->where('locale', $locale)->first();
        if ($custom !== null) {
            return [(string) $custom->subject, $custom->body];
        }

        return $this->default($category, $channel, $locale);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function default(string $category, string $channel, string $locale): array
    {
        // NOT-5: los mensajes directos (códigos) tienen su propio texto corto por canal.
        if (array_key_exists($category, (array) config('notifications.messages', []))) {
            $messages = trans('notifications.messages', [], $locale);
            $text = is_array($messages) ? ($messages[$category][$channel] ?? $messages[$category]['sms'] ?? null) : null;

            return ['', is_string($text) ? $text : ''];
        }

        $base = $channel === 'in_app' ? 'in_app' : 'email';
        // Las claves de tipo llevan punto (approval.pending): se lee el arreglo completo, no con notación de puntos.
        $all = trans('notifications.templates', [], $locale);
        $entry = is_array($all) ? ($all[$category][$base] ?? []) : [];
        $subject = $entry['subject'] ?? null;
        $body = $entry['body'] ?? null;

        return [is_string($subject) ? $subject : $category, is_string($body) ? $body : ''];
    }

    /**
     * @return array<int, string> variables usadas en un texto
     */
    public function placeholders(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * @param  array<string, string|int|null>  $variables
     */
    private function fill(string $text, array $variables): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => (string) ($variables[$m[1]] ?? ''), $text);
    }
}
