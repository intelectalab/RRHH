# 0006 · Sesión: cookie HttpOnly en el mismo dominio, sin tokens en el navegador

- **Estado**: Aceptada
- **Fecha**: 2026-11-05
- **Decisión de origen**: A-04 en `../13-decisiones-abiertas.md`
- **Quién decide**: Maximo, dirección del producto, aceptando la recomendación del kit. Si el área de desarrollo propone otra opción antes de empezar la fase 0, escribe un ADR que reemplace a este

## Contexto

Cada empresa entra por su subdominio (`miempresa.gestiahr.com`), y la interfaz en Next.js habla con la API en Laravel. Un token accesible desde JavaScript queda expuesto a cualquier script inyectado, y en este sistema eso significa sueldos y datos personales.

## Opciones consideradas

1. **Cookie de sesión del backend en el mismo origen**, con Next.js como interfaz y la API detrás de un proxy en la misma ruta. Hay un solo origen y la cookie es `HttpOnly`.
2. **Tokens en el navegador.** Es simple de integrar, pero el token se puede robar con XSS.
3. **Next.js como intermediario:** guarda la sesión y llama a la API desde el servidor. Duplica la lógica de sesión.

## Decisión

Opción 1. La sesión es una cookie `HttpOnly`, `Secure` y `SameSite=Lax`, emitida por Laravel para el subdominio de la organización. La API se sirve bajo el mismo origen (`/api`) y ningún token queda al alcance de JavaScript.

## Consecuencias

- CSRF se protege con el token de Laravel en las solicitudes que cambian datos. La CSP puede ser estricta porque todo sale de un solo origen.
- Las integraciones de terceros, la app instalable sin conexión y el servidor MCP usan tokens de API propios con alcance y caducidad (épica API). No usan la cookie.
- La verificación en dos pasos (ADR 0011) y el cierre de sesión por inactividad se aplican sobre esta sesión.

## Comprobación

Hay una prueba de punta a punta que verifica que `document.cookie` no muestra la sesión, que una solicitud sin token CSRF se rechaza y que la sesión de un subdominio no sirve en otro.
