# Registro de decisiones (ADR)

Cada decisión de `../13-decisiones-abiertas.md` que se cierra deja aquí un archivo `NNNN-titulo-corto.md` con la plantilla [`0000-plantilla.md`](0000-plantilla.md), y en el 13 se marca como cerrada con la fecha y la opción elegida. Un ADR no se edita después de aceptado: si la decisión cambia, se escribe otro que lo reemplaza.

Estados: **Propuesta** (redactada, falta aprobarla) · **Aceptada** · **Reemplazada por NNNN**.

## Decisiones que bloquean la fase 0

Claude Code se detiene ante una historia que dependa de una decisión abierta (`CLAUDE.md`, «Antes de escribir código»). Las once quedaron cerradas el 2026-11-05:

| Decisión | Tema | ADR | Estado |
|---|---|---|---|
| A-01 | Stack tecnológico | [0004](0004-stack-tecnologico.md) | Aceptada |
| A-02 | Modelo de aislamiento multiempresa | [0005](0005-aislamiento-multiempresa.md) | Aceptada |
| A-03 | Tipo de identificador | [0001](0001-identificadores-y-particionado.md) | Aceptada |
| A-04 | Sesión y autenticación entre web y API | [0006](0006-sesion-web-y-api.md) | Aceptada |
| A-08 | Región de alojamiento y transferencia internacional | [0007](0007-region-de-alojamiento.md) | Aceptada (falta el visto bueno legal, D-15) |
| D-01 | Composición de planes y módulos | [0008](0008-planes-y-modulos.md) | Aceptada |
| D-03 | Qué cuenta para el límite de colaboradores | [0009](0009-limite-de-colaboradores.md) | Aceptada |
| D-04 | Cobro de la suscripción y comprobantes electrónicos | [0010](0010-cobro-de-la-suscripcion.md) | Aceptada (falta elegir el proveedor de facturación) |
| D-08 | MFA obligatorio: para quién | [0011](0011-verificacion-en-dos-pasos.md) | Aceptada |
| D-13 | Datos al desactivar un módulo o dar de baja una organización | [0012](0012-datos-al-desactivar-o-dar-de-baja.md) | Aceptada (faltan los plazos, con legal) |
| D-29 | Idioma de los códigos de permiso | [0002](0002-codigos-de-permiso.md) | Aceptada |

A-06 (particionado) se cierra antes de la fase 2, pero la parte de auditoría ya está en [0001](0001-identificadores-y-particionado.md).

## Índice

| N.º | Título | Estado | Decisiones |
|---|---|---|---|
| [0001](0001-identificadores-y-particionado.md) | Identificadores UUID v7 y particionado mensual de los registros | Aceptada | A-03, A-06 |
| [0002](0002-codigos-de-permiso.md) | Códigos de permiso en inglés con etiqueta traducida | Aceptada | D-29 |
| [0003](0003-asistente-de-ayuda.md) | Asistente de ayuda documental: activación en tres niveles y búsqueda de texto completo | Propuesta | D-38 |
| [0004](0004-stack-tecnologico.md) | Stack: Laravel 12, Next.js 15, PostgreSQL 17 y Redis | Aceptada | A-01 |
| [0005](0005-aislamiento-multiempresa.md) | Aislamiento multiempresa: esquema compartido con tenant_id y RLS | Aceptada | A-02 |
| [0006](0006-sesion-web-y-api.md) | Sesión: cookie HttpOnly en el mismo dominio, sin tokens en el navegador | Aceptada | A-04 |
| [0007](0007-region-de-alojamiento.md) | Alojamiento en una región fuera del Perú, con garantías de transferencia documentadas | Aceptada | A-08 |
| [0008](0008-planes-y-modulos.md) | Planes con módulos incluidos, más complementos | Aceptada | D-01 |
| [0009](0009-limite-de-colaboradores.md) | Límite de colaboradores: personas únicas con una relación laboral activa | Aceptada | D-03 |
| [0010](0010-cobro-de-la-suscripcion.md) | Cobro registrado en la consola primero y pasarela de pago después, con factura electrónica desde el primer cobro | Aceptada | D-04 |
| [0011](0011-verificacion-en-dos-pasos.md) | Verificación en dos pasos obligatoria para roles con datos sensibles | Aceptada | D-08 |
| [0012](0012-datos-al-desactivar-o-dar-de-baja.md) | Datos al apagar un módulo o dar de baja una organización | Aceptada | D-13 |
| [0013](0013-catalogo-inicial-de-planes.md) | Catálogo inicial de planes y complementos, sin montos | Aceptada | D-01, D-03 |
| [0014](0014-nombre-y-marca.md) | Nombre GestiaHR y logo provisional | Aceptada | D-02 |

Las decisiones aceptadas con un pendiente no bloquean el desarrollo: el código usa un parámetro o un adaptador y el valor se completa antes de la salida a producción.
