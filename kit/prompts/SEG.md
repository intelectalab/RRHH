# SEG · Identidad, roles, permisos por campo y alcance

Ingreso con correo o documento de identidad, MFA, sesiones, roles personalizables, permisos `dominio.recurso.accion`, permisos por campo y alcance.

- **Fase:** Fase 0 · Cimientos. **Módulo comercial:** Plataforma base.
- **Actividades:** 12 (9 historias y 3 tareas); 12 del MVP. **Operaciones de API:** 41.
- **Especificación:** `kit/docs/modulos/M-SEG.md`.
- **Ya avanzado en el kit:** 908 permisos y 11 roles en `kit/database/catalogos/permisos_y_roles.json`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica SEG · Identidad, roles, permisos por campo y alcance de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-SEG.md, kit/prompts/SEG.md y la sección SEG de
kit/backlog/backlog.md (grep -n "^### SEG-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. SEG-1 · Ingreso con correo y contraseña
2. SEG-2 · Ingreso con documento de identidad y clave u OTP
3. SEG-3 · Segundo factor (MFA)
4. SEG-4 · Sesiones y dispositivos
5. SEG-5 · Políticas de contraseña, bloqueo y límite de peticiones
6. SEG-6 · Usuarios e invitaciones
7. SEG-7 · Roles del sistema y roles personalizados
8. SEG-8 · Catálogo de permisos `dominio.recurso.accion`
9. SEG-9 · Permisos por campo sensible
10. SEG-10 · Alcance de datos
11. SEG-11 · Cifrado de datos sensibles y registro de acceso
12. SEG-12 · Control de descargas y exportaciones
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-23, D-24. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `SEG-1` | Ingreso con correo y contraseña | Historia | MVP | TEN-1, PLT-4 |
| 2 | `SEG-2` | Ingreso con documento de identidad y clave u OTP | Historia | MVP | SEG-1, PLT-13 |
| 3 | `SEG-3` | Segundo factor (MFA) | Historia | MVP | SEG-1 |
| 4 | `SEG-4` | Sesiones y dispositivos | Historia | MVP | SEG-1 |
| 5 | `SEG-5` | Políticas de contraseña, bloqueo y límite de peticiones | Tarea | MVP | SEG-1 |
| 6 | `SEG-6` | Usuarios e invitaciones | Historia | MVP | SEG-1 |
| 7 | `SEG-7` | Roles del sistema y roles personalizados | Historia | MVP | SEG-6 |
| 8 | `SEG-8` | Catálogo de permisos `dominio.recurso.accion` | Tarea | MVP | SEG-7 |
| 9 | `SEG-9` | Permisos por campo sensible | Historia | MVP | SEG-8 |
| 10 | `SEG-10` | Alcance de datos | Historia | MVP | SEG-8, TEN-5 |
| 11 | `SEG-11` | Cifrado de datos sensibles y registro de acceso | Tarea | MVP | SEG-9, PLT-12 |
| 12 | `SEG-12` | Control de descargas y exportaciones | Historia | MVP | SEG-9, PLT-7 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`PLT-4`, `PLT-7`, `PLT-12`, `PLT-13`, `TEN-1`, `TEN-5`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-23 | Acceso del colaborador tras el cese | Fase 1 | (b), con duración configurable por organización; no cuenta para el límite del plan. |
| D-24 | Certificaciones de seguridad objetivo | Fase 0 (salida a producción) | diseñar los controles desde la fase 0 conforme a `12` y decidir el calendario de certificación con el negocio. |
