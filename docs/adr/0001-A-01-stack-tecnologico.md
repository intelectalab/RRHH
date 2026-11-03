# ADR 0001 · A-01: Stack tecnológico

- **Fecha:** 2026-11-02
- **Estado:** Aceptada (provisional, a confirmar por el negocio)
- **Decisión:** A-01

## Contexto

Ayni HR es un SaaS integral de Recursos Humanos multiempresa con requerimientos estrictos de integridad transaccional, cálculos de nómina según la legislación peruana y latinoamericana, aislamiento de datos, y una experiencia moderna tanto para administradores como para colaboradores. Se debe definir el stack base que soporte estas capacidades de forma robusta, mantenible y escalable.

## Opción elegida

Opción (a): **Laravel 12 / PHP 8.4 + PostgreSQL 17 + Redis + Next.js 15 (React, TypeScript, Tailwind)**.

Esta opción reutiliza el conocimiento, convenciones y herramientas del equipo de desarrollo. PostgreSQL aporta capacidades clave para el dominio:
- Row Level Security (RLS) nativo para aislamiento estricto por organización (`tenant_id`).
- Extensiones como `btree_gist` para restricciones de exclusión GiST que garantizan la no superposición de rangos de fechas en la historia laboral con vigencia.
- Soporte exacto para tipos `numeric` evitando errores de coma flotante en montos salariales, aportes y deducciones.

Next.js 15 permite renderizado optimizado, cliente tipado basado en contratos OpenAPI y una arquitectura de componentes moderna.

## Consecuencias

- **Positivas:**
  - Garantías nativas de integridad referencial, RLS y vigencias a nivel de base de datos.
  - Tipado estático en el frontend generado desde el contrato OpenAPI (`packages/contracts`).
  - Separación limpia entre API REST y clientes mediante arquitectura de monolito modular.
- **Negativas / Compromisos:**
  - Requiere un entorno de desarrollo local contenerizado con Docker Compose para gestionar todos los servicios de apoyo (PostgreSQL 17, Redis, MinIO, Mailpit).
  - Obliga al cumplimiento estricto de las 15 reglas no negociables de arquitectura.

