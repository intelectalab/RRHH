<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Application\Push\PushDevices;
use App\Modules\Notifications\Infrastructure\Models\Notification;
use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use App\Shared\Messaging\MessageSender;
use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Time\DisconnectionWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * NOT-1 · Motor de avisos: destinatarios vigentes, preferencias, plantilla en su idioma, aviso en la
 * aplicación y entregas por canal externo vía outbox (se difieren en el horario de silencio).
 * RN-NOT-07 · Sin correo válido (no tiene o rebotó), el aviso sale como mensaje corto por el canal de la
 * organización para quien no tiene correo (SMS o WhatsApp, parámetro notificaciones.canal_sin_correo),
 * con el título y el enlace, nunca el contenido (RN-NOT-02).
 */
final class NotificationDispatcher implements Notifier
{
    /** Mensaje corto (SMS/WhatsApp) que reemplaza al correo para quien no lo tiene (RN-NOT-07). */
    public const NOTICE_TEMPLATE = 'notifications.notice';

    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly PreferenceService $preferences,
        private readonly TemplateRenderer $renderer,
        private readonly OutboxWriter $outbox,
        private readonly TenantDirectory $tenants,
        private readonly MessageSender $messages,
        private readonly PushDevices $push,
        private readonly DisconnectionWindow $disconnection,
    ) {}

    public function notify(string $category, array $userIds, array $variables, ?string $subjectType = null, ?string $subjectId = null): int
    {
        $cat = $this->catalog->category($category);
        if ($cat === null) {
            throw new InvalidArgumentException("Tipo de aviso desconocido: {$category}");
        }
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($category, $cat, $userIds, $variables, $subjectType, $subjectId): int {
            // RN-NOT-01: solo usuarios activos de la organización, calculados ahora.
            $users = DB::table('users')->whereIn('id', $userIds)->where('status', 'active')->whereNull('deleted_at')->get(['id', 'display_name', 'locale']);
            $tenant = $this->tenants->profile(TenancyScope::requireTenant()) ?? ['name' => '', 'slug' => ''];
            $organization = $tenant['name'];
            $orgLocale = $this->catalog->setting('org.idioma_defecto');
            $link = (string) preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => rawurlencode((string) ($variables[$m[1]] ?? '')), $cat['link']);
            $orgQuietUntil = $cat['urgent'] ? null : $this->catalog->quietUntil(now());
            $count = 0;

            foreach ($users as $user) {
                // AUS-5 · Desconexión digital: a un teletrabajador fuera de su jornada, lo no urgente espera.
                $quietUntil = $orgQuietUntil;
                if (! $cat['urgent']) {
                    $resume = $this->disconnection->resumeAt((string) $user->id, CarbonImmutable::now());
                    if ($resume !== null && ($quietUntil === null || $resume->greaterThan($quietUntil))) {
                        $quietUntil = $resume->toMutable();
                    }
                }
                $locale = (string) ($user->locale ?? (is_string($orgLocale) ? $orgLocale : 'es'));
                $vars = array_intersect_key($variables, array_flip($this->catalog->variables($category)))
                    + ['name' => (string) $user->display_name, 'organization' => $organization, 'link' => $this->absoluteLink($tenant['slug'], $link)];

                $notificationId = null;
                if ($this->preferences->enabled((string) $user->id, $category, 'in_app')) {
                    $text = $this->renderer->render($category, 'in_app', $locale, $vars);
                    $notificationId = Notification::create([
                        'recipient_user_id' => $user->id,
                        'category' => $category,
                        'title' => mb_substr($text['subject'], 0, 300),
                        'body' => $text['body'],
                        'subject_type' => $subjectType,
                        'subject_id' => $subjectId,
                        'action_url' => $link,
                        'priority' => $cat['urgent'] ? 'high' : 'normal',
                    ])->id;
                    $count++;
                }

                foreach (array_diff($this->catalog->channels(), ['in_app']) as $channel) {
                    if (! $this->preferences->enabled((string) $user->id, $category, $channel)) {
                        continue;
                    }
                    if ($channel === 'push') {
                        // NOT-4: un aviso por dispositivo registrado; el contenido no lleva datos (RN-NOT-02).
                        foreach ($this->push->activeIds((string) $user->id) as $deviceId) {
                            $delivery = NotificationDelivery::create([
                                'notification_id' => $notificationId, 'channel' => 'push',
                                'recipient_address_enc' => FieldCipher::encrypt($deviceId), 'status' => 'queued',
                            ]);
                            $event = $this->outbox->record(DomainEventTypes::NOTIFICATION_DELIVERY_REQUESTED, 'notification_delivery', $delivery->id, [
                                'delivery_id' => $delivery->id, 'category' => $category, 'locale' => $locale, 'variables' => ['organization' => $organization], 'link' => $link,
                            ]);
                            if ($quietUntil !== null) {
                                $event->forceFill(['next_attempt_at' => $quietUntil])->save();
                            }
                        }

                        continue;
                    }
                    $address = $this->address((string) $user->id, $channel);
                    if ($address === null) {
                        if ($channel === 'email') {
                            $this->noticeWithoutEmail($category, (string) $user->id, $locale, $vars, $notificationId, $quietUntil);
                        }

                        continue;
                    }
                    $delivery = NotificationDelivery::create([
                        'notification_id' => $notificationId,
                        'channel' => $channel,
                        'recipient_address_enc' => FieldCipher::encrypt($address),
                        'status' => 'queued',
                    ]);
                    $event = $this->outbox->record(DomainEventTypes::NOTIFICATION_DELIVERY_REQUESTED, 'notification_delivery', $delivery->id, [
                        'delivery_id' => $delivery->id, 'category' => $category, 'locale' => $locale, 'variables' => $vars,
                    ]);
                    if ($quietUntil !== null) {
                        $event->forceFill(['next_attempt_at' => $quietUntil])->save(); // RN-NOT-03
                    }
                }
            }

            return $count;
        });
    }

    public function usersWithRole(string $roleId): array
    {
        $today = now()->toDateString();

        return DB::table('user_roles as ur')->join('users as u', 'u.id', '=', 'ur.user_id')
            ->where('ur.role_id', $roleId)->where('u.status', 'active')->whereNull('u.deleted_at')
            ->where(fn ($q) => $q->whereNull('ur.valid_from')->orWhere('ur.valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ur.valid_to')->orWhere('ur.valid_to', '>=', $today))
            ->distinct()->pluck('u.id')->map(fn ($id) => (string) $id)->all();
    }

    private function address(string $userId, string $channel): ?string
    {
        if ($channel !== 'email') {
            return null;
        }
        // NOT-3: una dirección que rebotó no se vuelve a usar hasta que RRHH la reactive.
        $enc = DB::table('user_identities')->where('user_id', $userId)->where('kind', 'email')->whereNull('bounced_at')
            ->orderByDesc('is_primary')->value('identifier_enc');

        return is_string($enc) ? FieldCipher::decrypt($enc) : null;
    }

    /**
     * RN-NOT-07 · Aviso corto por SMS/WhatsApp a quien no tiene correo válido: título y enlace, sin datos.
     *
     * @param  array<string, string|int|null>  $vars
     */
    private function noticeWithoutEmail(string $category, string $userId, string $locale, array $vars, ?string $notificationId, ?\DateTimeInterface $quietUntil): void
    {
        $channel = $this->catalog->setting('notificaciones.canal_sin_correo');
        if (! in_array($channel, ['sms', 'whatsapp'], true)) {
            return;
        }
        $enc = DB::table('user_identities')->where('user_id', $userId)->where('kind', 'phone')->orderByDesc('is_primary')->value('identifier_enc');
        if (! is_string($enc)) {
            return; // Sin correo ni celular: el aviso queda en la aplicación.
        }
        $title = $this->renderer->render($category, 'in_app', $locale, $vars)['subject'];
        $this->messages->send(
            (string) $channel,
            '+'.FieldCipher::decrypt($enc),
            self::NOTICE_TEMPLATE,
            ['title' => mb_substr($title, 0, 120), 'name' => $vars['name'] ?? '', 'organization' => $vars['organization'] ?? '', 'link' => $vars['link'] ?? ''],
            $locale,
            'notification:'.$category,
            'notice:'.($notificationId ?? (string) Str::uuid()).':'.$userId,
            $quietUntil,
        );
    }

    /**
     * Enlace absoluto a la organización (subdominio), para los canales externos.
     */
    private function absoluteLink(string $slug, string $path): string
    {
        $base = str_replace('{slug}', $slug, (string) config('tenancy.app_url', 'http://{slug}.localhost:3000'));

        return rtrim($base, '/').$path;
    }
}
