# ADR 0004 · A-04: Sesión y autenticación entre web y API

- **Fecha:** 2026-11-02
- **Estado:** Aceptada (provisional, a confirmar por el negocio)
- **Decisión:** A-04

## Contexto

Se requiere un esquema de autenticación y gestión de sesiones seguro y eficiente entre la aplicación web en Next.js y el backend en Laravel. Se debe minimizar el riesgo de ataques XSS, CSRF y facilitar el cumplimiento de políticas de seguridad estrictas (Content Security Policy).

## Opción elegida

Opción (a): **Cookie de sesión del backend en el mismo dominio raíz** (Next.js como interfaz, API detrás de un proxy o en subruta del mismo origen).

Se utiliza una cookie de sesión emitida por el backend con banderas `HttpOnly`, `Secure` y `SameSite=Lax`. No se almacenan tokens JWT ni secretos en el almacenamiento del navegador (`localStorage` / `sessionStorage`).

## Consecuencias

- **Positivas:**
  - Inmunidad contra robo de credenciales vía JavaScript malicioso (XSS).
  - Un único origen simplifica la configuración de CSRF, CORS y Content Security Policy (CSP).
  - El backend gestiona de forma centralizada la expiración, renovación e invalidación de sesiones (ej. ante cierre de sesión global o cambio de contraseña).
- **Negativas / Compromisos:**
  - Requiere enrutamiento unificado bajo el mismo dominio base para la interfaz web y la API en producción.

