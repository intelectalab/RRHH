# 0019 · Firma electrónica: validez por tipo de documento

- **Estado**: Aceptada
- **Fecha**: 2026-11-20
- **Decisión de origen**: D-10 en `../13-decisiones-abiertas.md`
- **Quién decide**: José Sánchez, aceptando la recomendación del kit

## Contexto

Decisión que el kit pedía cerrar antes de empezar la fase indicada en `13-decisiones-abiertas.md`.

## Opciones consideradas

(a) firma con evidencia propia para todo; (b) firma con certificado por proveedor para todo; (c) por tipo de documento.

## Decisión

Se acepta la recomendación del kit: (c): evidencia propia por defecto (boletas, constancias, políticas, EPP) y proveedor con certificado donde legal lo indique. Qué documento exige qué nivel lo define el área legal (D-15).

## Consecuencias

- El código sigue esta decisión sin interruptores alternativos; cambiarla exige un ADR nuevo que la reemplace.
- Todo valor legal involucrado entra como parámetro con vigencia en estado «por validar» (regla 4 de `CLAUDE.md`).

## Comprobación

Las historias que dependen de D-10 citan este ADR y sus pruebas cubren el comportamiento elegido.
