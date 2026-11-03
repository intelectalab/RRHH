# ADR 0005 · A-08: Región de alojamiento y transferencia internacional

- **Fecha:** 2026-11-02
- **Estado:** Aceptada (provisional, a confirmar por el negocio)
- **Decisión:** A-08

## Contexto

Ayni HR procesa datos laborales sensibles de empresas peruanas y regionales. La Ley N.° 29733 (Ley de Protección de Datos Personales del Perú) y su reglamento regulan el flujo transfronterizo de datos personales y exigen garantías adecuadas cuando los servidores se encuentran fuera del territorio nacional.

## Opción elegida

Opción (a): **Región en el extranjero con inventario de encargados y garantías registradas** (lo exige el reglamento de datos personales, ver `docs/12-seguridad-y-privacidad.md` §11), manteniendo el diseño de despliegue apto para alojamiento en Perú (opción c) si clientes específicos o regulaciones locales lo exigen. Requiere validación legal formal (D-15).

## Consecuencias

- **Positivas:**
  - Acceso a infraestructura en la nube de alta disponibilidad, escalabilidad y certificaciones internacionales de seguridad (SOC 2, ISO 27001).
  - Cumplimiento normativo mediante la formalización de acuerdos de procesamiento de datos (DPA) y cláusulas contractuales tipo con proveedores cloud.
  - La arquitectura desacoplada permite configurar regiones de despliegue aisladas para clientes que requieran residencia de datos en Perú.
- **Negativas / Compromisos:**
  - Obliga a mantener actualizado el registro de flujo transfronterizo ante la Autoridad Nacional de Protección de Datos Personales (ANPDP).

