<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Http\Middleware\SetRequestLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CFG-6 · PATCH /me/preferences · Idioma propio. Rige desde la solicitud siguiente (interfaz,
 * mensajes de la API y notificaciones, que se generan con users.locale); null vuelve al idioma de
 * la organización. No exige volver a iniciar sesión.
 */
final class MePreferencesController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['present', 'nullable', Rule::in(SetRequestLocale::LOCALES)],
        ]);
        /** @var User $user */
        $user = $request->user();
        $before = $user->locale;
        $after = $data['locale'];
        if ($before !== $after) {
            DB::transaction(function () use ($user, $before, $after): void {
                $user->locale = $after;
                $user->save();
                $this->audit->record('user.preferences_updated', 'user', $user->id, ['locale' => $before], ['locale' => $after]);
            });
        }
        $tenantLocale = DB::table('current_tenant')->value('default_locale');
        $effective = $after ?? (is_string($tenantLocale) ? $tenantLocale : 'es');
        app()->setLocale($effective);

        return response()->json(['data' => ['locale' => $after, 'effective_locale' => $effective]]);
    }
}
