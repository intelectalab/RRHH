# 0020 · Bancos, billeteras y ERP del primer lanzamiento

- **Estado**: Aceptada
- **Fecha**: 2026-11-20
- **Decisión de origen**: D-12 en `../13-decisiones-abiertas.md`
- **Quién decide**: José Sánchez, aceptando la recomendación del kit

## Contexto

Decisión que el kit pedía cerrar antes de empezar la fase indicada en `13-decisiones-abiertas.md`.

## Opciones consideradas

lista de bancos para archivo de pago de sueldos y CTS; billeteras; ERP o formato contable.

## Decisión

Se acepta la recomendación del kit: empezar por archivo (no por conexión en línea) para los bancos con más clientes del mercado objetivo y un formato contable genérico (CSV configurable). El negocio entrega la lista priorizada.

## Consecuencias

- El código sigue esta decisión sin interruptores alternativos; cambiarla exige un ADR nuevo que la reemplace.
- Todo valor legal involucrado entra como parámetro con vigencia en estado «por validar» (regla 4 de `CLAUDE.md`).

## Comprobación

Las historias que dependen de D-12 citan este ADR y sus pruebas cubren el comportamiento elegido.
