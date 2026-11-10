<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Infrastructure\Models\Notification;
use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * NOT-1 · Motor de avisos: destinatarios vigentes, preferencias, plantilla en su idioma, aviso en la
 * aplicación y entregas por canal externo vía outbox (se difieren en el horario de silencio).
 */
final class NotificationDispatcher implements Notifier
{
    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly PreferenceService $preferences,
        private readonly TemplateRenderer $renderer,
        private readonly OutboxWriter $outbox,
        private readonly TenantDirectory $tenants,
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
            $quietUntil = $cat['urgent'] ? null : $this->catalog->quietUntil(now());
            $count = 0;

            foreach ($users as $user) {
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
                    $address = $this->address((string) $user->id, $channel);
                    if ($address === null) {
                        continue; // RN-NOT-07: sin correo, el aviso queda en la aplicación.
                    }
                    $delivery = NotificationDelivery::create([
                        'notification_id' => $notificationId,
                        'channel' => $channel,
                        'recipient_address_enc' => Crypt::encryptString($address),
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
        $enc = DB::table('user_identities')->where('user_id', $userId)->where('kind', 'email')->orderByDesc('is_primary')->value('identifier_enc');

        return is_string($enc) ? Crypt::decryptString($enc) : null;
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
