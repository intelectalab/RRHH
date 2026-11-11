# 0012 · Datos al apagar un módulo o dar de baja una organización

- **Estado**: Aceptada
- **Fecha**: 2026-11-05
- **Decisión de origen**: D-13 en `../13-decisiones-abiertas.md`
- **Quién decide**: Maximo, dirección del producto, aceptando la recomendación del kit. Los plazos los fija el negocio con el área legal

## Contexto

Un cliente puede apagar un módulo o irse de la plataforma. Los datos laborales tienen plazos de conservación legal, el cliente es el titular de sus bancos de datos y GestiaHR es su encargado (`docs/12` §11). Borrar de inmediato o retener para siempre son igual de riesgosos.

## Opciones consideradas

- **Módulo:** (a) solo lectura indefinida; (b) solo lectura durante un periodo y luego archivo.
- **Organización:** periodo de gracia, forma de entrega de los datos y eliminación.

## Decisión

- **Módulo apagado:** sus datos quedan en solo lectura y se pueden exportar. No se borra nada, se ocultan los menús y la API responde `module_not_enabled` a las escrituras.
- **Baja de organización:** se entrega una exportación completa, la organización pasa un periodo de gracia en solo lectura y después se hace una eliminación certificada. La plataforma emite una constancia con la fecha y el alcance de lo eliminado.

## Consecuencias

- El periodo de gracia, el plazo de solo lectura de un módulo y la retención de respaldos son parámetros de la consola. No están escritos en el código.
- La eliminación respeta lo que la ley obliga a conservar. Lo que debe quedar se separa y se documenta en la constancia.
- **Pendiente, sin bloquear el desarrollo:** los valores de esos plazos los define el negocio con legal (D-15) antes de la salida a producción.

## Comprobación

Hay pruebas con plazos de prueba cortos. Un módulo apagado rechaza escrituras y permite exportar. Una organización dada de baja pasa a solo lectura, se elimina al vencer el plazo y deja su constancia en la auditoría de la plataforma.
