# ADR 0009 · D-08: MFA obligatorio

- **Fecha:** 2026-11-02
- **Estado:** Aceptada (provisional, a confirmar por el negocio)
- **Decisión:** D-08

## Contexto

El sistema gestiona información de alto impacto y confidencialidad: sueldos, cuentas bancarias, historiales disciplinarios, denuncias y expedientes de salud. La autenticación únicamente mediante usuario y contraseña representa un riesgo de seguridad inaceptable para cuentas con privilegios o acceso a datos sensibles.

## Opción elegida

Opción (b): **Obligatorio para roles con acceso a datos sensibles**, manteniendo la opción (c) como política de seguridad activable a nivel de organización para exigir MFA a todos los colaboradores.

Para el personal operativo que no cuente con correo electrónico corporativo, el segundo factor de autenticación se resuelve mediante código de un solo uso (OTP) enviado a su número de teléfono verificado (vía SMS o WhatsApp).

## Consecuencias

- **Positivas:**
  - Protección robusta para roles críticos (Superadmin, Administrador de Organización, Nómina/Planilla, Investigador de Denuncias, SST).
  - Flexibilidad para que empresas adopten MFA progresivamente o lo hagan mandatorio para todo su personal.
  - Inclusión digital para operarios en planta o campo mediante verificación por teléfono móvil.
- **Negativas / Compromisos:**
  - Requiere un flujo guiado de enrolamiento obligatorio de MFA cuando un usuario es promovido o asignado a un rol sensible.
  - Implica costos operativos marginales para envío de códigos OTP vía SMS o WhatsApp.

