# 0022 · Adelanto de sueldo

- **Estado**: Aceptada
- **Fecha**: 2026-11-20
- **Decisión de origen**: D-25 en `../13-decisiones-abiertas.md`
- **Quién decide**: José Sánchez, aceptando la recomendación del kit

## Contexto

Decisión que el kit pedía cerrar antes de empezar la fase indicada en `13-decisiones-abiertas.md`.

## Opciones consideradas

(a) lo financia la empresa cliente; (b) un tercero financiero; tope sobre lo devengado; canal de pago (banco, billetera).

## Decisión

Se acepta la recomendación del kit: (a) al inicio: adelanto sobre lo ya devengado, tope por política de la organización como parámetro, descuento automático en el periodo. (b) implica regulación financiera y debe evaluarse aparte.

## Consecuencias

- El código sigue esta decisión sin interruptores alternativos; cambiarla exige un ADR nuevo que la reemplace.
- Todo valor legal involucrado entra como parámetro con vigencia en estado «por validar» (regla 4 de `CLAUDE.md`).

## Comprobación

Las historias que dependen de D-25 citan este ADR y sus pruebas cubren el comportamiento elegido.
