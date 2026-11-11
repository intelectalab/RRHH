<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\TemplateApprovalStateMachine;
use App\Modules\Notifications\Infrastructure\Models\NotificationTemplate;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Messaging\TemplateDecision;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
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
        private readonly TemplateApprovalStateMachine $approvals,
        private readonly OutboxWriter $outbox,
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
        // NOT-5 · Mensajes directos por SMS y WhatsApp; los de WhatsApp nacen en borrador de aprobación.
        foreach (array_keys($this->catalog->messages()) as $code) {
            foreach ($this->catalog->messageChannels() as $channel) {
                foreach (NotificationCatalog::LOCALES as $locale) {
                    if (! $existing->has("{$code}|{$channel}|{$locale}")) {
                        [, $body] = $this->renderer->default($code, $channel, $locale);
                        $rows[] = ['code' => $code, 'channel' => $channel, 'locale' => $locale, 'subject' => null, 'body' => $body, 'variables' => $this->catalog->variables($code), 'is_system' => true, 'approval_status' => $channel === 'whatsapp' ? 'draft' : 'not_required'];
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
            'customized' => (string) $t->subject !== $subject || $t->body !== $body,
            'approval_status' => $t->approval_status,
            'rejection_reason' => $t->rejection_reason,
            'approval_decided_at' => $t->approval_decided_at?->toIso8601String(),
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
            $before = ['subject' => $t->subject, 'body' => $t->body, 'approval_status' => $t->approval_status];
            $changes = ['subject' => $subject, 'body' => $body];
            // NOT-5: el texto aprobado por el proveedor es el que se envía; si cambia, se aprueba de nuevo.
            if ($body !== $t->body && $this->approvals->can($t->approval_status, TemplateApprovalStateMachine::RESET)) {
                $changes += ['approval_status' => 'draft', 'provider_template_ref' => null, 'rejection_reason' => null, 'approval_decided_at' => null];
            }
            $t->forceFill($changes)->save();
            $this->audit->record('notification_template.updated', 'notification_template', $t->id, $before, ['subject' => $subject, 'body' => $body, 'approval_status' => $t->approval_status]);

            return $t;
        });
    }

    /**
     * NOT-5 · Envía una plantilla de WhatsApp a aprobación del proveedor. La respuesta llega por el
     * outbox (regla 8); mientras tanto queda pendiente y los mensajes salen por SMS.
     */
    public function requestApproval(NotificationTemplate $t): NotificationTemplate
    {
        if ($t->channel !== 'whatsapp') {
            throw new BusinessRuleViolatedException('approval_not_required', (string) trans('notifications.approval_only_whatsapp'));
        }
        $next = $this->approvals->getNextState($t->approval_status, TemplateApprovalStateMachine::REQUEST);

        return DB::transaction(function () use ($t, $next): NotificationTemplate {
            $before = $t->approval_status;
            $t->forceFill(['approval_status' => $next, 'approval_requested_at' => now(), 'rejection_reason' => null])->save();
            $this->outbox->deliverSoon($this->outbox->record(DomainEventTypes::MESSAGE_TEMPLATE_APPROVAL_REQUESTED, 'notification_template', $t->id, ['template_id' => $t->id]));
            $this->audit->record('notification_template.approval_requested', 'notification_template', $t->id, ['approval_status' => $before], ['approval_status' => $next]);

            return $t;
        });
    }

    /**
     * NOT-5 · Aplica la respuesta del proveedor (aprobada o rechazada). Si la plantilla cambió
     * mientras esperaba, la respuesta ya no vale y se ignora.
     */
    public function applyDecision(NotificationTemplate $t, TemplateDecision $decision): void
    {
        $action = match ($decision->status) {
            'approved' => TemplateApprovalStateMachine::APPROVE,
            'rejected' => TemplateApprovalStateMachine::REJECT,
            default => null,
        };
        if ($action === null || ! $this->approvals->can($t->approval_status, $action)) {
            return;
        }
        $next = $this->approvals->getNextState($t->approval_status, $action);
        $t->forceFill([
            'approval_status' => $next,
            'provider_template_ref' => $decision->providerTemplateRef,
            'rejection_reason' => $decision->reason !== null ? mb_substr($decision->reason, 0, 500) : null,
            'approval_decided_at' => now(),
        ])->save();
        $this->audit->record('notification_template.approval_'.$next, 'notification_template', $t->id, ['approval_status' => 'pending'], ['approval_status' => $next, 'rejection_reason' => $t->rejection_reason]);
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
