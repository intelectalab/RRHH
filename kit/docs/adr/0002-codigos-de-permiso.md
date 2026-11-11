# 0002 · Códigos de permiso en inglés con etiqueta traducida

- **Estado**: Aceptada (aplicada desde la v1.6)
- **Fecha**: 2026-11-05
- **Decisión de origen**: D-29 en `../13-decisiones-abiertas.md`
- **Quién decide**: producto y área de desarrollo

## Contexto

`api/openapi.yaml`, `docs/07` y el catálogo usan códigos en inglés (`payroll.periods.close`); el prototipo y la historia SEG-8 de la v1.5 usaban español (`planilla.periodo.cerrar`). La primera historia de SEG fija el catálogo, así que hay que elegir antes de la fase 0.

## Opciones consideradas

1. Inglés en todo, igual que rutas, tablas y eventos.
2. Español, igual que el prototipo.
3. Código en inglés y etiqueta traducida (i18n) en la pantalla de roles.

## Decisión

Opción 3. El código `dominio.recurso.accion` (o `dominio.recurso.campo.accion` para permisos de campo) es un identificador técnico que viaja en la API y en los tokens; el administrador ve la etiqueta en su idioma.

## Consecuencias

- `x-permission` en `openapi.yaml` y `permissions.code` en la base usan el mismo código.
- Las etiquetas viven en `messages/{es,en,pt}.json` con la clave `permissions.<código>`.
- El prototipo conserva sus códigos en español: es referencia de navegación (`docs/15`).

## Comprobación

Prueba que compara los `x-permission` de `openapi.yaml` con `permissions.code`, y prueba de i18n que falla si un permiso no tiene etiqueta en algún idioma.
