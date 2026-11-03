# ADR 0011 · D-29: Idioma de los códigos de permiso

- **Fecha:** 2026-11-02
- **Estado:** Aceptada (provisional, a confirmar por el negocio)
- **Decisión:** D-29

## Contexto

Existía disparidad entre los identificadores de permisos utilizados en la especificación OpenAPI (`payroll.periods.close`, `people.employments.salary.view`) y algunas referencias iniciales en español provenientes del prototipo (`planilla.periodo.cerrar`, `colaborador.sueldo.ver`). Se requiere unificar y estandarizar la nomenclatura técnica en todo el sistema.

## Opción elegida

Opción (c): **Código en inglés con etiqueta traducida en la pantalla de roles**.

El código de permiso es un identificador técnico estándar que viaja en la API (`x-permission`), en tokens, policies y base de datos bajo el formato `modulo.recurso.accion` y `modulo.recurso.campo.view|edit`. La interfaz de usuario presenta siempre la etiqueta traducida en el idioma del usuario (`messages/{es,en,pt}.json`).

## Consecuencias

- **Positivas:**
  - Alineación total entre el backend (Laravel policies), la API REST (`api/openapi.yaml`), las pruebas de autorización y los tipos de TypeScript.
  - Soporte internacional nativo (i18n): los administradores ven descripciones en español, inglés o portugués según su preferencia, mientras la infraestructura técnica permanece inmutable.
  - Resuelve discrepancias en `docs/07-permisos-y-roles.md`, backlog y `tools/gen_openapi.py`.
- **Negativas / Compromisos:**
  - Todas las referencias históricas en español en prototipos y seeders deben migrarse al identificador formal en inglés.

