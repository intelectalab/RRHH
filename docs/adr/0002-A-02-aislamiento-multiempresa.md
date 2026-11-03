# ADR 0002 · A-02: Modelo de aislamiento multiempresa

- **Fecha:** 2026-11-02
- **Estado:** Aceptada (provisional, a confirmar por el negocio)
- **Decisión:** A-02

## Contexto

Como SaaS multi-tenant, Ayni HR atiende a múltiples organizaciones sobre la misma infraestructura. Es crítico garantizar que ninguna organización pueda acceder bajo ninguna circunstancia a los datos de otra, manteniendo al mismo tiempo costos operativos eficientes y simplicidad en las migraciones de base de datos.

## Opción elegida

Opción (a): **Base y esquema compartidos con `tenant_id` + RLS + claves foráneas compuestas**, dejando la resolución de conexión detrás de una abstracción (`TenantContext`) para poder ofrecer en el futuro base dedicada para clientes grandes (opción d) sin modificar el código de los módulos.

## Consecuencias

- **Positivas:**
  - Despliegues y migraciones unificadas en un único esquema relacional.
  - El motor de PostgreSQL impone el aislamiento mediante RLS (`FORCE ROW LEVEL SECURITY`) a nivel de fila antes de que cualquier consulta de la aplicación se ejecute.
  - Claves foráneas compuestas `(tenant_id, id)` impiden que relaciones entre tablas apunten accidentalmente a registros de otro inquilino.
  - Permite evolucionar hacia esquemas híbridos o bases dedicadas en planes Enterprise encapsulando la selección de base en `TenantContext`.
- **Negativas / Compromisos:**
  - Requiere incluir `tenant_id` en todas las tablas de negocio y mantener la disciplina de no utilizar `withoutGlobalScopes` fuera de las excepciones expresamente permitidas (`Shared/Tenancy`).
  - La suite de pruebas de aislamiento (`make test-isolation`) es obligatoria en CI para validar todas las rutas contra accesos cruzados.

