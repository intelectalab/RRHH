# ADR 0006 · D-01: Composición de planes y módulos

- **Fecha:** 2026-11-02
- **Estado:** Aceptada (provisional, a confirmar por el negocio)
- **Decisión:** D-01

## Contexto

El modelo comercial de Ayni HR requiere empaquetar funcionalidades para distintos segmentos de clientes (MYPEs, medianas, grandes empresas y estudios contables), permitiendo a la vez contratar módulos adicionales según la necesidad del cliente sin duplicar ni bifurcar el código base.

## Opción elegida

Opción (c): **Planes con módulos incluidos y complementos (add-ons)**.

La arquitectura trata planes, módulos comerciales, límites y flags como datos configurables (`docs/01-vision-y-fases.md` §4). Los módulos **Plataforma base (`base`)** y **Core HR (`core`)** están siempre incluidos en todos los planes. El resto de módulos (asistencia, vacaciones, nómina, talento, etc.) pueden incluirse por nivel de plan o contratarse como complementos independientes.

## Consecuencias

- **Positivas:**
  - Flexibilidad para el equipo comercial para diseñar y ajustar planes (Starter, Growth, Enterprise) sin despliegues de código.
  - La API impone el control mediante el middleware `module:<clave>` devolviendo 403 `module_not_enabled` si el inquilino no tiene activo el módulo.
  - Los datos de un módulo apagado no se eliminan; quedan en modo solo lectura para exportación conforme a las políticas de retención.
- **Negativas / Compromisos:**
  - Requiere administrar el ciclo de vida de suscripciones y habilitación de módulos en la consola SaaS desde la Fase 0.

