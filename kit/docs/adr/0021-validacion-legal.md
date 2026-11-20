# 0021 · Validación legal

- **Estado**: Aceptada
- **Fecha**: 2026-11-20
- **Decisión de origen**: D-15 en `../13-decisiones-abiertas.md`
- **Quién decide**: José Sánchez, aceptando la recomendación del kit

## Contexto

Decisión que el kit pedía cerrar antes de empezar la fase indicada en `13-decisiones-abiertas.md`.

## Opciones consideradas

(a) validación por asesor laboral y de datos personales antes de cada fase; (b) validación única al final.

## Decisión

Se acepta la recomendación del kit: (a). Cada valor entra como parámetro con estado «por validar» y no puede usarse en producción hasta que lo valide el responsable (D-27).

## Consecuencias

- El código sigue esta decisión sin interruptores alternativos; cambiarla exige un ADR nuevo que la reemplace.
- Todo valor legal involucrado entra como parámetro con vigencia en estado «por validar» (regla 4 de `CLAUDE.md`).

## Comprobación

Las historias que dependen de D-15 citan este ADR y sus pruebas cubren el comportamiento elegido.
