# 0018 · Presentación ante SUNAT y AFPnet

- **Estado**: Aceptada
- **Fecha**: 2026-11-20
- **Decisión de origen**: D-09 en `../13-decisiones-abiertas.md`
- **Quién decide**: José Sánchez, aceptando la recomendación del kit

## Contexto

Decisión que el kit pedía cerrar antes de empezar la fase indicada en `13-decisiones-abiertas.md`.

## Opciones consideradas

(a) generar y validar archivos; la empresa presenta; (b) presentación automatizada.

## Decisión

Se acepta la recomendación del kit: (a). El sistema registra la constancia de presentación que carga el usuario.

## Consecuencias

- El código sigue esta decisión sin interruptores alternativos; cambiarla exige un ADR nuevo que la reemplace.
- Todo valor legal involucrado entra como parámetro con vigencia en estado «por validar» (regla 4 de `CLAUDE.md`).

## Comprobación

Las historias que dependen de D-09 citan este ADR y sus pruebas cubren el comportamiento elegido.
