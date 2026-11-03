# ADR 0008 · D-04: Cobro de la suscripción

- **Fecha:** 2026-11-02
- **Estado:** Aceptada (provisional, a confirmar por el negocio)
- **Decisión:** D-04

## Contexto

El sistema SaaS debe gestionar la facturación y cobranza de las suscripciones de los clientes. Se debe permitir la operación comercial desde las etapas iniciales sin retrasar el desarrollo de la plataforma base a la espera de integraciones complejas con pasarelas de pago.

## Opción elegida

Opción (c): **Registro manual del estado de cuenta en la consola SaaS primero (a) y pasarela de pagos integrada después (b)**, manteniendo el cobro desacoplado tras un puerto/adaptador (`BillingProvider`).

La consola SaaS registra desde la Fase 0 el plan asignado, ciclo de facturación, fecha de vencimiento, estado de pago y acciones de suspensión.

## Consecuencias

- **Positivas:**
  - Permite la puesta en marcha inmediata del producto para ventas B2B tradicionales (transferencia bancaria, orden de compra, facturación manual).
  - La arquitectura desacoplada mediante puertos y adaptadores permite incorporar procesadores de pago automáticos (Stripe, Culqi, Niubiz) sin modificar la lógica de suscripciones ni el modelo de datos.
- **Negativas / Compromisos:**
  - En la fase inicial, la conciliación y activación de pagos requiere intervención manual del administrador de la plataforma SaaS.

