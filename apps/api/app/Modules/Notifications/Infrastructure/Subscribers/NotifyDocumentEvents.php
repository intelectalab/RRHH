<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * DOC-2 · RN-DOC-09 · Un documento por vencer o vencido se avisa a su responsable y, si está publicado para él, al
 * titular. ME-12: un documento rechazado se avisa a quien lo subió, con el motivo. De un documento confidencial
 * el aviso nombra solo su tipo, no su título.
 */
final class NotifyDocumentEvents implements DomainEventSubscriber
{
    public const EXPIRING = 'documents.expiring';

    public const EXPIRED = 'documents.expired';

    public const REJECTED = 'documents.rejected';

    public function __construct(private readonly Notifier $notifier) {}

    public function consumerName(): string
    {
        return 'notifications.notify_document_events';
    }

    public function handles(): array
    {
        return [DomainEventTypes::DOCUMENT_EXPIRING, DomainEventTypes::DOCUMENT_EXPIRED, DomainEventTypes::DOCUMENT_REJECTED];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            $p = $event->payload;
            $doc = DB::table('documents')->where('id', (string) ($p['document_id'] ?? ''))->first([
                'id', 'title', 'classification', 'document_category_id', 'owner_person_id', 'employment_id', 'responsible_user_id', 'visible_to_employee',
            ]);
            if ($doc === null) {
                return;
            }
            $type = (string) (DB::table('document_categories')->where('id', $doc->document_category_id)->value('name') ?? '');
            $confidential = in_array($doc->classification, ['confidential', 'restricted'], true);
            $person = $doc->owner_person_id === null ? null : DB::table('people')->where('id', $doc->owner_person_id)->first(['first_names', 'preferred_name', 'paternal_surname']);
            $employee = $person === null ? '' : trim(($person->preferred_name ?: $person->first_names).' '.$person->paternal_surname);
            $titular = $doc->owner_person_id === null ? null
                : DB::table('users')->where('person_id', $doc->owner_person_id)->where('status', 'active')->whereNull('deleted_at')->value('id');

            if ($event->event_type === DomainEventTypes::DOCUMENT_REJECTED) {
                $uploader = $p['uploaded_by_user_id'] ?? null;
                $recipients = is_string($uploader) && $uploader !== '' ? [$uploader] : [];
                $category = self::REJECTED;
                $vars = ['reason' => (string) ($p['reason'] ?? '')];
            } else {
                $recipients = [];
                if ($doc->responsible_user_id !== null) {
                    $recipients[] = (string) $doc->responsible_user_id;
                }
                if ($titular !== null && (bool) $doc->visible_to_employee) {
                    $recipients[] = (string) $titular;
                }
                $category = $event->event_type === DomainEventTypes::DOCUMENT_EXPIRED ? self::EXPIRED : self::EXPIRING;
                $vars = ['expires_on' => (string) ($p['expires_on'] ?? ''), 'days_left' => (string) ($p['days_left'] ?? '')];
            }
            $recipients = array_values(array_unique($recipients));
            if ($recipients === []) {
                return;
            }
            $this->notifier->notify($category, $recipients, [
                'document' => $confidential ? $type : (string) $doc->title, 'type' => $type, 'employee' => $employee,
                'employment_id' => (string) ($doc->employment_id ?? ''),
            ] + $vars, 'document', (string) $doc->id);
        });
    }
}
