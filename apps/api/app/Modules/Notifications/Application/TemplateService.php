<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Infrastructure\Models\NotificationTemplate;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * NOT-1 · Plantillas por tipo de aviso, canal e idioma, editables por organización.
 */
final class TemplateService
{
    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly TemplateRenderer $renderer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Copia en la organización los textos del producto que aún no tiene, para poder editarlos.
     */
    public function ensureDefaults(): void
    {
        $existing = NotificationTemplate::withTrashed()->get(['code', 'channel', 'locale'])->map(fn ($t) => "{$t->code}|{$t->channel}|{$t->locale}")->flip();
        $rows = [];
        foreach (array_keys($this->catalog->categories()) as $code) {
            foreach ($this->catalog->channels() as $channel) {
                foreach (NotificationCatalog::LOCALES as $locale) {
                    if (! $existing->has("{$code}|{$channel}|{$locale}")) {
                        [$subject, $body] = $this->renderer->default($code, $channel, $locale);
                        $rows[] = ['code' => $code, 'channel' => $channel, 'locale' => $locale, 'subject' => $subject, 'body' => $body, 'variables' => $this->catalog->variables($code), 'is_system' => true];
                    }
                }
            }
        }
        if ($rows !== []) {
            DB::transaction(fn () => array_map(fn ($r) => NotificationTemplate::create($r), $rows));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function present(NotificationTemplate $t): array
    {
        [$subject, $body] = $this->renderer->default($t->code, $t->channel, $t->locale);

        return [
            'id' => $t->id,
            'key' => $t->code,
            'channel' => $t->channel,
            'locale' => $t->locale,
            'subject' => $t->subject,
            'body' => $t->body,
            'variables' => $this->catalog->variables($t->code),
            'mandatory' => $this->catalog->isMandatory($t->code),
            'customized' => $t->subject !== $subject || $t->body !== $body,
            'default_subject' => $subject,
            'default_body' => $body,
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array{key: string, channel: string, locale: string, subject?: string|null, body: string}  $input
     */
    public function create(array $input): NotificationTemplate
    {
        $this->validateText($input['key'], (string) ($input['subject'] ?? ''), $input['body']);
        if (NotificationTemplate::query()->where('code', $input['key'])->where('channel', $input['channel'])->where('locale', $input['locale'])->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('notifications.template_exists'));
        }

        return DB::transaction(function () use ($input): NotificationTemplate {
            $t = NotificationTemplate::create(['code' => $input['key'], 'channel' => $input['channel'], 'locale' => $input['locale'], 'subject' => $input['subject'] ?? null, 'body' => $input['body'], 'variables' => $this->catalog->variables($input['key']), 'is_system' => false]);
            $this->audit->record('notification_template.created', 'notification_template', $t->id, null, $this->present($t));

            return $t;
        });
    }

    /**
     * @param  array{subject?: string|null, body?: string}  $input
     */
    public function update(NotificationTemplate $t, array $input): NotificationTemplate
    {
        $subject = array_key_exists('subject', $input) ? (string) $input['subject'] : (string) $t->subject;
        $body = $input['body'] ?? $t->body;
        $this->validateText($t->code, $subject, $body);

        return DB::transaction(function () use ($t, $subject, $body): NotificationTemplate {
            $before = ['subject' => $t->subject, 'body' => $t->body];
            $t->forceFill(['subject' => $subject, 'body' => $body])->save();
            $this->audit->record('notification_template.updated', 'notification_template', $t->id, $before, ['subject' => $subject, 'body' => $body]);

            return $t;
        });
    }

    private function validateText(string $code, string $subject, string $body): void
    {
        if ($this->catalog->category($code) === null) {
            throw ValidationException::withMessages(['key' => [(string) trans('notifications.unknown_category')]]);
        }
        $allowed = $this->catalog->variables($code);
        $unknown = array_diff(array_merge($this->renderer->placeholders($subject), $this->renderer->placeholders($body)), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['body' => [(string) trans('notifications.invalid_variables', ['vars' => implode(', ', $unknown), 'allowed' => implode(', ', $allowed)])]]);
        }
    }
}
