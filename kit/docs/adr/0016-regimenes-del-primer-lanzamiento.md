# 0016 · Regímenes laborales del primer lanzamiento

- **Estado**: Aceptada
- **Fecha**: 2026-11-20
- **Decisión de origen**: D-06 en `../13-decisiones-abiertas.md`
- **Quién decide**: José Sánchez, aceptando la recomendación del kit

## Contexto

Decisión que el kit pedía cerrar antes de empezar la fase indicada en `13-decisiones-abiertas.md`.

## Opciones consideradas

general; microempresa; pequeña empresa; construcción civil; agrario; modalidades formativas.

## Decisión

Se acepta la recomendación del kit: general + microempresa + pequeña empresa + modalidades formativas primero; construcción civil y agrario después. El motor admite todos; lo que se decide es el contenido del paquete y sus casos dorados.

## Consecuencias

- El código sigue esta decisión sin interruptores alternativos; cambiarla exige un ADR nuevo que la reemplace.
- Todo valor legal involucrado entra como parámetro con vigencia en estado «por validar» (regla 4 de `CLAUDE.md`).

## Comprobación

Las historias que dependen de D-06 citan este ADR y sus pruebas cubren el comportamiento elegido.
