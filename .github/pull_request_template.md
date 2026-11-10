## Descripción del cambio
<!-- Resume qué problema resuelve o qué historia del backlog implementa este PR -->

Historia / Tarea relacionada:
- Código del backlog: `PLT-XX` (o enlace a la tarea)

---

## Justificación técnica (en caso de modificación o reemplazo de código existente)
<!-- IMPORTANTE: Consulta la regla de no regresión en docs/guia-trabajo.md §1 -->
<!-- Si este PR elimina o reemplaza código o funcionalidad previa, explica detalladamente el motivo técnico y de negocio. Deja vacío si solo añade funcionalidad. -->

---

## Lista de verificación de «Hecho» (Definition of Done)
> Consulta la [Guía de trabajo del repositorio (docs/guia-trabajo.md)](../docs/guia-trabajo.md) para los criterios detallados de cada punto.

### 1. Regla de no regresión
- [ ] No se elimina ni degrada funcionalidad existente sin justificación técnica documentada arriba.

### 2. Modelo de datos y migraciones
- [ ] Columna `tenant_id` y RLS forzado (`FORCE ROW LEVEL SECURITY`) en tablas de negocio.
- [ ] Claves foráneas compuestas por organización `(tenant_id, foreign_id)`.
- [ ] Prohibido `ON DELETE CASCADE` en tablas de historia laboral (personal, contratos, compensación).
- [ ] Las migraciones fueron probadas con `artisan db:verify-conventions`.

### 3. Backend, Dominio y API
- [ ] Casos de uso atómicos dentro de `DB::transaction`.
- [ ] Eventos registrados en `OutboxService` y acciones sensibles registradas en auditoría.
- [ ] Respuestas y errores siguen el sobre canónico de `docs/08-api.md`.
- [ ] Si cambió la API, se regeneró `api/openapi.yaml` (`make openapi`) y `packages/contracts` (`make contracts`).
- [ ] Pruebas Feature usan `assertMatchesOpenApi()` para validar contratos.

### 4. Permisos y seguridad
- [ ] Permisos declarados con convención `modulo.recurso.accion` y validados en el servidor.
- [ ] Permisos por campo sensible aplicados (sueldos, datos médicos, sanciones).
- [ ] Aislamiento multi-tenant validado (retorna 404 ante accesos cruzados de organización).

### 5. Frontend y Diseño
- [ ] Pantallas implementadas con el sistema de tokens y componentes de `docs/10-diseno-del-sistema.md`.
- [ ] No se utilizó el prototipo descartado (`prototipo/`) como base de código ni de diseño.
- [ ] Textos internacionalizados mediante claves en `messages/` (sin cadenas directas en JSX).
- [ ] Diseño adaptable probado en móvil, tablet y escritorio.

### 6. Calidad y Verificación
- [ ] `vendor/bin/pint` ejecutado sin incidencias de formato.
- [ ] `vendor/bin/phpstan analyse --memory-limit=2G` en nivel 8 sin errores.
- [ ] Suite de pruebas (`make test`) pasando al 100% en verde.
- [ ] `docs/AVANCE.md` actualizado con el estado de la historia y el resumen de artefactos.
