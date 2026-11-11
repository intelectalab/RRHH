---
description: Crea un endpoint de la API siguiendo el contrato, permisos, alcance y pruebas
argument-hint: <MÉTODO /ruta>
---

Crea el endpoint **$ARGUMENTS**.

1. Búscalo en `kit/api/openapi.yaml` (`grep -n`). Usa su `x-permission`, `x-phase` y esquemas. Si no existe, añádelo primero en `kit/tools/gen_openapi.py` y ejecuta `make openapi contracts`.
2. Implementa en `apps/api/app/Modules/<Modulo>/`:
   - `Http/Requests/*Request.php`: validación y autorización (`authorize()` llama a la Policy).
   - `Application/Actions/*` (escritura) o `Application/Queries/*` (lectura). El controlador no contiene lógica.
   - `Http/Resources/*Resource.php`: usa `SensitiveField::when(...)` para los campos de `x-sensitive-fields`.
   - Ruta en `Http/routes.php` con middlewares `tenant`, `module:<clave>`, `throttle`.
3. Reglas:
   - Listados: paginación en servidor (`page`/`per_page` ≤ 100 o `cursor`), `filter[...]`, `sort`, `as_of`; el alcance del usuario se aplica en la consulta.
   - Escrituras: transacción única que incluye cambio + auditoría + outbox; `Idempotency-Key` en `POST`; `If-Match` en `PATCH`.
   - Acciones de estado: `POST /recurso/{id}/accion`; la transición la valida la máquina de estados; 409 `invalid_transition`.
   - Errores con la envoltura y los códigos de `kit/docs/08-api.md` §4.
4. Pruebas (Pest, `Tests/Feature`): 200/201 feliz · 401 · 403 sin permiso · 403 `module_not_enabled` · 404 otra organización · 404 fuera de alcance · 422 · 409 (si aplica) · idempotencia · auditoría · outbox · campo sensible.
5. Ejecuta `make test-api F=<Nombre> lint` y la prueba de contrato.
