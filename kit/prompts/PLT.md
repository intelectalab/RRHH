# PLT · Plataforma técnica

Base técnica común: repositorio, entornos, contratos de API, colas, archivos, errores y observabilidad sobre la que se construyen todos los módulos.

- **Fase:** Fase 0 · Cimientos. **Módulo comercial:** Plataforma base.
- **Actividades:** 14 (0 historias y 14 tareas); 14 del MVP. **Operaciones de API:** 4.
- **Especificación:** `kit/docs/modulos/M-PLT.md`.
- **Ya avanzado en el kit:** `kit/referencia/arranque/`: Docker con PostgreSQL 17, Redis, S3 y correo de pruebas; `Makefile`; integración continua del kit.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica PLT · Plataforma técnica de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-PLT.md, kit/prompts/PLT.md y la sección PLT de
kit/backlog/backlog.md (grep -n "^### PLT-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. PLT-1 · Monorepo y estructura de aplicaciones
2. PLT-2 · Entorno local con Docker
3. PLT-3 · Integración continua
4. PLT-4 · Formato único de errores y validación en servidor
5. PLT-12 · Convenciones del modelo de datos y migraciones
6. PLT-14 · Guía de trabajo del repositorio
7. PLT-5 · Contratos OpenAPI y tipos compartidos
8. PLT-6 · Colas, trabajos programados y outbox de eventos de dominio
9. PLT-7 · Servicio de archivos
10. PLT-8 · Idempotencia y acciones de estado
11. PLT-9 · Entornos y despliegue
12. PLT-10 · Observabilidad
13. PLT-11 · Rendimiento de base: paginación, índices y caché
14. PLT-13 · Adaptadores de integración con implementación falsa
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: A-13, D-22. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `PLT-1` | Monorepo y estructura de aplicaciones | Tarea | MVP | — |
| 2 | `PLT-2` | Entorno local con Docker | Tarea | MVP | PLT-1 |
| 3 | `PLT-3` | Integración continua | Tarea | MVP | PLT-1 |
| 4 | `PLT-4` | Formato único de errores y validación en servidor | Tarea | MVP | PLT-1 |
| 5 | `PLT-12` | Convenciones del modelo de datos y migraciones | Tarea | MVP | PLT-1 |
| 6 | `PLT-14` | Guía de trabajo del repositorio | Tarea | MVP | PLT-1 |
| 7 | `PLT-5` | Contratos OpenAPI y tipos compartidos | Tarea | MVP | PLT-1, PLT-4 |
| 8 | `PLT-6` | Colas, trabajos programados y outbox de eventos de dominio | Tarea | MVP | PLT-1, PLT-2 |
| 9 | `PLT-7` | Servicio de archivos | Tarea | MVP | PLT-1, PLT-2 |
| 10 | `PLT-8` | Idempotencia y acciones de estado | Tarea | MVP | PLT-4 |
| 11 | `PLT-9` | Entornos y despliegue | Tarea | MVP | PLT-3 |
| 12 | `PLT-10` | Observabilidad | Tarea | MVP | PLT-9 |
| 13 | `PLT-11` | Rendimiento de base: paginación, índices y caché | Tarea | MVP | PLT-5 |
| 14 | `PLT-13` | Adaptadores de integración con implementación falsa | Tarea | MVP | PLT-6 |

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| A-13 | Objetivos de recuperación y restauración por organización | Fase 0 (salida a producción) | recuperación a un punto en el tiempo + (b) mediante exportación e importación por `tenant_id` desde un entorno aislado. (c) se cubre con papelera e historia con vigencia. |
| D-22 | Presupuestos de rendimiento | Fase 1 | fijarlos como umbrales de la prueba de carga (`11` §11) antes de construir la fase 1, para que el diseño de índices y proyecciones se mida contra ellos. |
