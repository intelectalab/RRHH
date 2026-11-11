# 0010 · Cobro registrado en la consola primero y pasarela de pago después, con factura electrónica desde el primer cobro

- **Estado**: Aceptada
- **Fecha**: 2026-11-05
- **Decisión de origen**: D-04 en `../13-decisiones-abiertas.md`
- **Quién decide**: Maximo, dirección del producto, aceptando la recomendación del kit

## Contexto

Para vender hace falta cobrar y emitir comprobantes desde el primer cliente. Integrar una pasarela de pago (tarjetas, billeteras móviles, transferencia con conciliación) no debería retrasar el lanzamiento. A las empresas peruanas hay que emitirles factura electrónica ante SUNAT, con IGV.

## Opciones consideradas

1. **Registro manual del estado de cuenta en la consola.**
2. **Pasarela de pago integrada desde el inicio.**
3. **Registro manual primero y pasarela después**, con el cobro detrás de un adaptador.

## Decisión

Opción 3. Desde la fase 0, la consola registra el plan, el ciclo, los cargos, los pagos, el saldo y la suspensión por mora. La pasarela se conecta después detrás del mismo adaptador de cobro. Cada cobro, manual o integrado, emite su comprobante electrónico mediante un proveedor OSE o PSE detrás de un adaptador de facturación, que en desarrollo usa una implementación falsa (SAS-11).

## Consecuencias

- El IGV es un parámetro con vigencia, no una constante.
- La regla de suspensión por mora y su plazo se configuran en la consola.
- **Pendiente, sin bloquear el desarrollo:** elegir el proveedor OSE o PSE y confirmar con el contador el tratamiento del IGV y de los clientes del exterior. Se hace antes del primer cobro real.

## Comprobación

Hay pruebas de SAS-11 con el adaptador falso: un cobro registrado a mano genera un comprobante, un pago lo concilia y una mora vencida suspende la organización según el parámetro.
