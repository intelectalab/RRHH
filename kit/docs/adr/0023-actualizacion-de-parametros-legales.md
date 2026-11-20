# 0023 · Actualización de parámetros legales

- **Estado**: Aceptada
- **Fecha**: 2026-11-20
- **Decisión de origen**: D-27 en `../13-decisiones-abiertas.md`
- **Quién decide**: José Sánchez, aceptando la recomendación del kit

## Contexto

Decisión que el kit pedía cerrar antes de empezar la fase indicada en `13-decisiones-abiertas.md`.

## Opciones consideradas

(a) la plataforma mantiene los parámetros del paquete y las organizaciones los reciben; (b) cada organización los mantiene; (c) mixto.

## Decisión

Se acepta la recomendación del kit: (c): parámetros legales nacionales mantenidos por la plataforma con un responsable nombrado, doble revisión, fuente normativa obligatoria y aviso a las organizaciones; parámetros de política propia mantenidos por la organización. Definir quién es el especialista que valida y mantiene los casos dorados.

## Consecuencias

- El código sigue esta decisión sin interruptores alternativos; cambiarla exige un ADR nuevo que la reemplace.
- Todo valor legal involucrado entra como parámetro con vigencia en estado «por validar» (regla 4 de `CLAUDE.md`).

## Comprobación

Las historias que dependen de D-27 citan este ADR y sus pruebas cubren el comportamiento elegido.
