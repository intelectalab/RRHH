# ADR 0007 · D-03: Qué cuenta para el límite de colaboradores

- **Fecha:** 2026-11-02
- **Estado:** Aceptada (provisional, a confirmar por el negocio)
- **Decisión:** D-03

## Contexto

Los planes de suscripción de Ayni HR establecen un límite máximo de colaboradores activos. Se debe definir de manera unívoca qué entidades consumen plazas del plan y cuáles están exentas, evitando penalizar a grupos empresariales donde una persona presta servicios en múltiples razones sociales.

## Opción elegida

Opción (b): **Personas únicas con al menos una relación laboral activa**.

- Las personas físicas únicas que mantengan al menos un vínculo laboral vigente consumen una (1) plaza del plan, sin importar si tienen relaciones concurrentes en varias razones sociales del mismo grupo/tenant.
- Los locadores de servicios (4ta categoría) y candidatos en proceso de selección o pre-ingreso no consumen plazas del plan.
- Los colaboradores cesados no cuentan para el límite de colaboradores activos.

## Consecuencias

- **Positivas:**
  - Modelo justo y predecible para clientes multi-RUC y grupos corporativos.
  - La comprobación del límite se valida en el backend al crear o activar una relación laboral; si se excede el límite del plan, se rechaza la acción con `422 plan_limit_reached` sin bloquear operaciones de lectura ni cálculo de planillas en curso.
- **Negativas / Compromisos:**
  - Requiere consultas de agregación sobre `people` con relación laboral activa para determinar el consumo de cuota actual en lugar de un conteo simple de filas en `employments`.

