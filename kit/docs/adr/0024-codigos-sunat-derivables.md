# 0024 · Ubicación de los códigos SUNAT derivables (perfil T-Registro)

- **Estado**: Aceptada
- **Fecha**: 2026-11-20
- **Decisión de origen**: D-30 en `../13-decisiones-abiertas.md`
- **Quién decide**: José Sánchez, aceptando la recomendación del kit

## Contexto

Decisión que el kit pedía cerrar antes de empezar la fase indicada en `13-decisiones-abiertas.md`.

## Opciones consideradas

(a) guardar lo declarado y conciliarlo con lo derivado (aplicado); (b) derivar siempre y no guardar; (c) llevar los códigos a los catálogos del núcleo (`contract_types`, `health_providers`).

## Decisión

Se acepta la recomendación del kit: (a). Lo declarado es lo que dispara la novedad de modificación; `has_risk_insurance` queda como indicador derivado. Detalle en `18-localizacion-peru-detalle-tecnico.md` §0.2.

## Consecuencias

- El código sigue esta decisión sin interruptores alternativos; cambiarla exige un ADR nuevo que la reemplace.
- Todo valor legal involucrado entra como parámetro con vigencia en estado «por validar» (regla 4 de `CLAUDE.md`).

## Comprobación

Las historias que dependen de D-30 citan este ADR y sus pruebas cubren el comportamiento elegido.
