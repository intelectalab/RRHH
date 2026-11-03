# ADR 0003 · A-03: Tipo de identificador

- **Fecha:** 2026-11-02
- **Estado:** Aceptada (provisional, a confirmar por el negocio)
- **Decisión:** A-03

## Contexto

El sistema requiere identificadores primarios para todas las entidades (organizaciones, colaboradores, marcaciones, boletas, etc.). Se debe evitar la exposición de secuencias predecibles que revelen volumen de negocio o permitan ataques de enumeración (IDOR), garantizando al mismo tiempo alto rendimiento en índices relacionales y la capacidad de generar identificadores de forma distribuida u offline.

## Opción elegida

Opción (a): **UUID v7 (ordenable por tiempo)**.

Los UUID v7 combinan una marca de tiempo de milisegundos con entropía aleatoria, garantizando ordenación temporal natural, excelente localidad en páginas de índices B-tree en PostgreSQL, y la posibilidad de generación en el cliente (imprescindible para marcación sin conexión y cabeceras de idempotencia). La API trata los identificadores como texto opaco.

## Consecuencias

- **Positivas:**
  - Mitiga la fragmentación de índices B-tree que afectaba a UUID v4.
  - No expone secuencias continuas ni volumen de transacciones a usuarios finales o terceros.
  - Permite a aplicaciones móviles o kioscos generar identificadores válidos en modo sin conexión sin riesgo de colisión al sincronizar.
  - Totalmente alineado con `database/schema.sql` y `docs/04-modelo-de-datos.md`.
- **Negativas / Compromisos:**
  - Consume 16 bytes por identificador en lugar de 8 bytes de un bigint secuencial.

