# 0004 · Stack: Laravel 12, Next.js 15, PostgreSQL 17 y Redis

- **Estado**: Aceptada
- **Fecha**: 2026-11-05
- **Decisión de origen**: A-01 en `../13-decisiones-abiertas.md`
- **Quién decide**: Maximo, dirección del producto, aceptando la recomendación del kit. Si el área de desarrollo propone otra opción antes de empezar la fase 0, escribe un ADR que reemplace a este

## Contexto

Todo el kit (esquema, pruebas de aislamiento, comandos de Claude Code y convenciones) se escribió para un stack concreto. El diseño usa como garantías funciones propias de PostgreSQL: seguridad por fila (RLS), restricciones de exclusión por rango de fechas y `numeric` exacto para montos. Cambiar de stack después obligaría a rehacer el esquema y las pruebas.

## Opciones consideradas

1. **Laravel 12 (PHP 8.4), Next.js 15, PostgreSQL 17 y Redis en un monorepo.** Es el stack de los demás kits del área, y el esquema y las pruebas ya existen para él.
2. **Otro stack.** Exige reescribir `docs/02`, `docs/03`, las migraciones y los comandos de `.claude/commands/`.

## Decisión

Opción 1. El backend usa Laravel 12 con PHP 8.4, la interfaz usa Next.js 15, la base de datos es PostgreSQL 17 y Redis se usa para colas, caché y límites de uso. Todo vive en un solo repositorio (PLT-1).

## Consecuencias

- `docs/02-arquitectura.md` y `docs/03-convenciones.md` quedan como norma, sin alternativas.
- No se usa ninguna función de base de datos que no exista en PostgreSQL 17. `uuid_v7()` se mantiene hasta pasar a PostgreSQL 18 (ADR 0001).
- Las librerías nuevas se evalúan en cada pull request con `/revisar` y no reemplazan piezas del stack.

## Comprobación

PLT-1 deja el monorepo con las versiones fijadas en `composer.json`, `package.json` y la imagen de base de datos. La integración continua falla si cambia una versión mayor sin un ADR.
