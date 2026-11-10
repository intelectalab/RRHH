<?php

namespace App\Shared\Notifications;

/**
 * NOT-1 · Puerto único para avisar a personas. Los módulos lo usan desde sus suscriptores de
 * eventos; el módulo de notificaciones resuelve preferencias, plantillas, idioma, silencio y canales.
 */
interface Notifier
{
    /**
     * @param  array<int, string>  $userIds  destinatarios (se validan al enviar: activos y de la organización)
     * @param  array<string, string|int>  $variables  variables de la plantilla declaradas para la categoría
     * @return int notificaciones creadas
     */
    public function notify(string $category, array $userIds, array $variables, ?string $subjectType = null, ?string $subjectId = null): int;

    /**
     * Usuarios activos con un rol vigente (para avisos dirigidos a un rol). RN-NOT-01: se calcula al enviar.
     *
     * @return array<int, string>
     */
    public function usersWithRole(string $roleId): array;
}
