# 00 · Trazabilidad del pedido original

Ayni HR · kit de desarrollo. Demuestra que cada uno de los 124 puntos de `prompt-original.md` queda cubierto por al menos una épica del catálogo de `SPEC.md`, y dónde se concretan las correcciones (C-01 a C-12) y ampliaciones (E-01 a E-27).

Cómo usarla: la columna «Documento(s)» lleva a la especificación del módulo (`kit/docs/modulos/`). Dentro de cada módulo, la sección 4 repite esta trazabilidad en sentido inverso. Documentos de apoyo: `05-maquinas-de-estado.md`, `06-reglas-de-negocio.md`, `07-permisos-y-roles.md`, `14-localizacion-peru.md`.

Notas:
- Los puntos de precio de planes (§3) se cubren como **campos configurables del plan** en la consola SaaS; este kit no fija ningún precio.
- Los puntos de proceso (§120 a §123) se cubren en `M-QA.md` como reglas de trabajo, sin plazos ni estimaciones.
- El pedido ubicaba la plataforma SaaS en su «Fase 9»; por la corrección C-04 pasa a la Fase 0.
