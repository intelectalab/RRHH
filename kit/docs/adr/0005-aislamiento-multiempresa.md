# 0005 · Aislamiento multiempresa: esquema compartido con tenant_id y RLS

- **Estado**: Aceptada
- **Fecha**: 2026-11-05
- **Decisión de origen**: A-02 en `../13-decisiones-abiertas.md`
- **Quién decide**: Maximo, dirección del producto, aceptando la recomendación del kit. Si el área de desarrollo propone otra opción antes de empezar la fase 0, escribe un ADR que reemplace a este

## Contexto

GestiaHR guarda en la misma plataforma datos de muchas empresas, entre ellos sueldos y datos de salud. Una fuga entre empresas es el peor incidente posible. El esquema de `kit/database/` ya está escrito con `tenant_id`, claves foráneas compuestas y políticas RLS forzadas.

## Opciones consideradas

1. **Base y esquema compartidos, con `tenant_id`, RLS y claves foráneas compuestas.** Es una sola migración para todos y deja una defensa en la base además de la aplicación. Exige disciplina en cada tabla nueva.
2. **Un esquema por organización.** Aísla bien, pero las migraciones se multiplican y la consola necesita cruzar esquemas.
3. **Una base por organización.** Es el aislamiento más fuerte, con el mayor costo de operación.
4. **Híbrido:** la opción 1 por defecto y una base dedicada para clientes grandes.

## Decisión

Opción 1, con la resolución de la conexión detrás de `TenantContext`. Así se puede ofrecer la opción 4 más adelante sin tocar los módulos.

## Consecuencias

- Toda tabla de negocio lleva `tenant_id`, RLS forzado y la política `tenant_isolation`. Sus claves foráneas incluyen `tenant_id`.
- La aplicación se conecta con el rol `gestia_app`, que no es dueño de las tablas ni salta RLS. Las migraciones usan otro rol.
- Sin `TenantContext` activo, una consulta falla en lugar de devolver todo (`docs/02` §aislamiento).
- Ofrecer una base dedicada a un cliente será un cambio de infraestructura, no de código de módulos.

## Comprobación

`kit/database/pruebas_aislamiento.sql` corre en la integración continua. Si una tabla nueva no tiene RLS forzado o su clave foránea no incluye `tenant_id`, la prueba falla.
