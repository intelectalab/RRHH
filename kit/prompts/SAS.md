# SAS · Consola SaaS

Consola del propietario del SaaS, separada de la aplicación del cliente: organizaciones, planes, módulos, límites, feature flags, soporte y salud.

- **Fase:** Fase 0 · Cimientos. **Módulo comercial:** —.
- **Actividades:** 13 (12 historias y 1 tareas); 13 del MVP. **Operaciones de API:** 31.
- **Especificación:** `kit/docs/modulos/M-SAS.md`.
- **Ya avanzado en el kit:** Planes y complementos como datos iniciales (`docs/01` §4.2, ADR 0013).

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica SAS · Consola SaaS de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-SAS.md, kit/prompts/SAS.md y la sección SAS de
kit/backlog/backlog.md (grep -n "^### SAS-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. SAS-1 · Consola separada para el superadministrador
2. SAS-2 · Organizaciones: alta, suspensión y bloqueo
3. SAS-3 · Planes comerciales
4. SAS-9 · Salud y monitoreo de la plataforma
5. SAS-12 · Copias de seguridad y restauración
6. SAS-13 · Configuración global y registros de la consola
7. SAS-4 · Suscripciones, complementos y pruebas gratuitas
8. SAS-8 · Acceso de soporte auditado
9. SAS-5 · Módulos por organización
10. SAS-11 · Facturación de la suscripción y estado de cuenta
11. SAS-6 · Límites y consumo
12. SAS-7 · Feature flags
13. SAS-10 · Tablero del negocio SaaS
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-19. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `SAS-1` | Consola separada para el superadministrador | Historia | MVP | SEG-3, TEN-1 |
| 2 | `SAS-2` | Organizaciones: alta, suspensión y bloqueo | Historia | MVP | SAS-1 |
| 3 | `SAS-3` | Planes comerciales | Historia | MVP | SAS-1 |
| 4 | `SAS-9` | Salud y monitoreo de la plataforma | Historia | MVP | SAS-1, PLT-10 |
| 5 | `SAS-12` | Copias de seguridad y restauración | Historia | MVP | SAS-1, PLT-9 |
| 6 | `SAS-13` | Configuración global y registros de la consola | Historia | MVP | SAS-1, AUD-1 |
| 7 | `SAS-4` | Suscripciones, complementos y pruebas gratuitas | Historia | MVP | SAS-2, SAS-3 |
| 8 | `SAS-8` | Acceso de soporte auditado | Historia | MVP | SAS-2, AUD-1 |
| 9 | `SAS-5` | Módulos por organización | Tarea | MVP | SAS-4, SEG-8 |
| 10 | `SAS-11` | Facturación de la suscripción y estado de cuenta | Historia | MVP | SAS-4, PLT-13 |
| 11 | `SAS-6` | Límites y consumo | Historia | MVP | SAS-5, PLT-7 |
| 12 | `SAS-7` | Feature flags | Historia | MVP | SAS-5 |
| 13 | `SAS-10` | Tablero del negocio SaaS | Historia | MVP | SAS-4, SAS-6 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`AUD-1`, `PLT-7`, `PLT-9`, `PLT-10`, `PLT-13`, `SEG-3`, `SEG-8`, `TEN-1`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-19 | Límites de uso de API por plan | Fase 9 | por plan, como parámetros de plataforma, con límites más estrictos en rutas públicas, autenticación e IA. |
