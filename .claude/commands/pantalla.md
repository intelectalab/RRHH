---
description: Construye una pantalla de producción a partir de la pantalla equivalente del prototipo
argument-hint: <nombre o ruta de la pantalla en el prototipo>
---

Construye la pantalla **$ARGUMENTS** en `apps/web`.

1. Abre el prototipo y recorre la pantalla (flujo, columnas, filtros, acciones, estados vacíos). Lee `docs/10-pantallas-y-ux.md` y `docs/15-que-no-copiar-del-prototipo.md`: se copia el flujo y la jerarquía visual, **no** el código, los datos en el navegador ni los cálculos.
2. Identifica los endpoints en `api/openapi.yaml` y usa los tipos de `packages/contracts`. Si falta un endpoint, créalo primero con `/endpoint`.
3. Implementa:
   - Ruta en `src/app/(app)/...`; componentes del módulo en `src/modules/<modulo>/`.
   - Solo componentes de `src/ui` (DataTable, Drawer, Modal, Timeline, Kanban, Calendar, EmptyState, FormField…). Si falta uno, créalo en `src/ui` con tokens, no en el módulo.
   - Datos con el cliente de API y caché de consultas; paginación, filtro y orden **en servidor**; estado de filtros en la URL.
   - Visibilidad con `can()` y `moduleOn()`; los campos sensibles se pintan solo si vienen en la respuesta.
   - Textos con claves i18n en `messages/es.json`, `en.json`, `pt.json`. Montos con `formatMoney`, fechas con `formatDate` (zona de la empresa).
   - Estados: cargando (skeleton), vacío útil con acción, error con reintento, sin permiso.
   - Ninguna regla legal ni cálculo de negocio en el cliente.
4. Verifica: 1280 px y 390 px sin desborde horizontal; teclado y foco visibles; tema claro y oscuro; contraste AA.
5. Pruebas: Vitest del componente (estados y permisos) y un recorrido Playwright del flujo principal.
6. Ejecuta `make test lint`.
