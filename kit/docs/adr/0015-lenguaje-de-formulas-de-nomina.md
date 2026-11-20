# 0015 · Lenguaje de fórmulas del motor de nómina

- **Estado**: Aceptada
- **Fecha**: 2026-11-20
- **Decisión de origen**: A-14 en `../13-decisiones-abiertas.md`
- **Quién decide**: José Sánchez, aceptando la recomendación del kit

## Contexto

Decisión que el kit pedía cerrar antes de empezar la fase indicada en `13-decisiones-abiertas.md`.

## Opciones consideradas

(a) lenguaje de expresiones propio con evaluador y funciones del motor; (b) reglas en código por concepto; (c) motor de reglas de terceros.

## Decisión

Se acepta la recomendación del kit: (a). Los conceptos son datos con vigencia que RRHH puede crear; (b) impide conceptos personalizados y obliga a desplegar por cada cambio normativo. Mantener la gramática pequeña y sin efectos secundarios.

## Consecuencias

- El código sigue esta decisión sin interruptores alternativos; cambiarla exige un ADR nuevo que la reemplace.
- Todo valor legal involucrado entra como parámetro con vigencia en estado «por validar» (regla 4 de `CLAUDE.md`).

## Comprobación

Las historias que dependen de A-14 citan este ADR y sus pruebas cubren el comportamiento elegido.
