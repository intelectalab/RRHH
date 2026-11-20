<?php

namespace App\Modules\Platform\Application\Web;

use App\Shared\Integrations\IntegrationManager;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WEB-5 a WEB-7 · Correos del sitio. Se encolan en web_messages dentro de la misma transacción que el lead, el
 * registro o la demo (regla 8: el outbox de la plataforma, que no tiene organización) y salen por el puerto de
 * correo con reintentos. Cada fila se envía una sola vez: solo se toman las pendientes y se marcan al enviarlas.
 */
final class WebMessages
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly IntegrationManager $integrations) {}

    /**
     * @param  array<string, string|int>  $replace
     */
    public function queue(string $kind, string $to, ?string $locale, string $subjectKey, string $bodyKey, array $replace, string $refType, string $refId, string $append = ''): string
    {
        $locale = in_array($locale, ['es', 'en', 'pt'], true) ? $locale : 'es';
        $previous = App::getLocale();
        App::setLocale($locale);
        try {
            $subject = (string) trans($subjectKey, $replace);
            $body = (string) trans($bodyKey, $replace).$append;
        } finally {
            App::setLocale($previous);
        }

        return (string) DB::table('web_messages')->insertGetId([
            'kind' => $kind, 'to_email' => $to, 'locale' => $locale, 'subject' => $subject, 'body' => $body,
            'ref_type' => $refType, 'ref_id' => $refId,
        ]);
    }

    /**
     * Envía las pendientes. Una falla del proveedor deja la fila pendiente para el siguiente intento; tras
     * MAX_ATTEMPTS queda «failed» con el error.
     */
    public function deliver(int $limit = 100): int
    {
        $sent = 0;
        $rows = DB::table('web_messages')->where('status', 'pending')->orderBy('created_at')->limit($limit)->get();
        foreach ($rows as $row) {
            $claimed = DB::table('web_messages')->where('id', $row->id)->where('status', 'pending')->where('attempts', $row->attempts)
                ->update(['attempts' => (int) $row->attempts + 1]);
            if ($claimed === 0) {
                continue;
            }
            try {
                $ref = $this->integrations->email()->send((string) $row->to_email, (string) $row->subject, (string) $row->body);
                DB::table('web_messages')->where('id', $row->id)->update(['status' => 'sent', 'sent_at' => now(), 'provider_ref' => $ref, 'last_error' => null]);
                $sent++;
            } catch (Throwable $e) {
                DB::table('web_messages')->where('id', $row->id)->update([
                    'status' => (int) $row->attempts + 1 >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                    'last_error' => mb_substr($e->getMessage(), 0, 500),
                ]);
            }
        }

        return $sent;
    }
}
