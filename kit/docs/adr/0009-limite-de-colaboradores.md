# 0009 · Límite de colaboradores: personas únicas con una relación laboral activa

- **Estado**: Aceptada
- **Fecha**: 2026-11-05
- **Decisión de origen**: D-03 en `../13-decisiones-abiertas.md`
- **Quién decide**: Maximo, dirección del producto, aceptando la recomendación del kit

## Contexto

Cada plan tiene un tope de colaboradores (ADR 0008). Una persona puede tener más de una relación laboral en la misma organización, por ejemplo en dos razones sociales del grupo. También hay practicantes, locadores, pre-ingresos y cesados que conservan acceso a sus boletas.

## Opciones consideradas

1. **Relaciones laborales activas.** Cobra dos veces a quien trabaja en dos empresas del grupo.
2. **Personas únicas activas.** Es justo para el cliente y fácil de explicar.
3. **Relaciones con boleta en el periodo.** Depende de Planilla y no sirve al plan Esencial.

## Decisión

Opción 2. Cuenta cada persona única con al menos una relación laboral activa en la organización, y los practicantes con convenio activo cuentan. No cuentan los locadores, los pre-ingresos ni los cesados, aunque conserven acceso al portal.

## Consecuencias

- El conteo es una consulta única de la plataforma, que usan la consola, el aviso de tope y la facturación.
- Al llegar al tope se rechaza el alta nueva con un mensaje que explica cómo ampliar el plan (RN-COL-13).
- Qué tipos de relación cuentan es un parámetro de la consola, para poder ajustarlo sin cambiar código.

## Comprobación

Hay pruebas de la regla de conteo con una persona en dos razones sociales (cuenta 1), un locador (cuenta 0), un pre-ingreso (cuenta 0) y un cesado con acceso al portal (cuenta 0).
