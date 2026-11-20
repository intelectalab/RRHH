# 0017 · Reapertura de planilla cerrada y retroactivos

- **Estado**: Aceptada
- **Fecha**: 2026-11-20
- **Decisión de origen**: D-07 en `../13-decisiones-abiertas.md`
- **Quién decide**: José Sánchez, aceptando la recomendación del kit

## Contexto

Decisión que el kit pedía cerrar antes de empezar la fase indicada en `13-decisiones-abiertas.md`.

## Opciones consideradas

(a) nunca reabrir: todo ajuste va al siguiente periodo; (b) reabrir con permiso especial mientras no se haya declarado ni pagado; (c) reabrir siempre con rectificatoria.

## Decisión

Se acepta la recomendación del kit: (b). Tras declarar o pagar, solo ajustes en el siguiente periodo y archivo rectificatorio.

## Consecuencias

- El código sigue esta decisión sin interruptores alternativos; cambiarla exige un ADR nuevo que la reemplace.
- Todo valor legal involucrado entra como parámetro con vigencia en estado «por validar» (regla 4 de `CLAUDE.md`).

## Comprobación

Las historias que dependen de D-07 citan este ADR y sus pruebas cubren el comportamiento elegido.
