# 0007 · Alojamiento en una región fuera del Perú, con garantías de transferencia documentadas

- **Estado**: Aceptada
- **Fecha**: 2026-11-05
- **Decisión de origen**: A-08 en `../13-decisiones-abiertas.md`
- **Quién decide**: Maximo, dirección del producto, aceptando la recomendación del kit. La validación legal queda pendiente en D-15

## Contexto

La Ley 29733 y su reglamento (DS 016-2024-JUS) permiten la transferencia internacional de datos personales si se documentan los encargados, los países y las garantías, y si se informa al titular (`docs/12` §11). Alojar en el Perú limita los proveedores y servicios gestionados disponibles.

## Opciones consideradas

1. **Región de nube fuera del Perú**, con inventario de encargados y garantías registradas. Da acceso a servicios gestionados y redundancia.
2. **Alojamiento en el Perú.** Simplifica el argumento legal, pero tiene menos oferta y menos redundancia.
3. **Elección por organización.** Es lo más flexible y lo más caro de operar.

## Decisión

Opción 1. El diseño se mantiene apto para la opción 3, porque la región se resuelve por organización detrás de `TenantContext` (ADR 0005). La región concreta la elige el área de desarrollo al montar la infraestructura (PLT) y se registra en el inventario de encargados.

## Consecuencias

- El inventario de encargados y transferencias (épica PRI) registra el proveedor de alojamiento, el país y la garantía desde la fase 0.
- El aviso de privacidad y el acuerdo de tratamiento de datos mencionan la transferencia internacional.
- **Pendiente, sin bloquear el desarrollo:** el área legal valida esta decisión dentro de D-15 antes de la salida a producción.

## Comprobación

Antes de producción, el inventario de encargados exportado incluye el alojamiento, con su país y su garantía, y legal deja su visto bueno registrado en D-15.
