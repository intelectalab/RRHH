# 0013 · Catálogo inicial de planes y complementos, sin montos

- **Estado**: Aceptada
- **Fecha**: 2026-11-05
- **Decisión de origen**: D-01 y D-03 en `../13-decisiones-abiertas.md` (complementa el ADR 0008, no lo reemplaza)
- **Quién decide**: Maximo, dirección del producto

## Contexto

El ADR 0008 fijó el modelo comercial (planes con módulos incluidos, más complementos), pero la composición solo estaba en el sitio y no en los datos iniciales de la base. Además, un complemento del sitio, como Gestia Planilla, enciende varios módulos (`payroll`, `advances`, `contractors`), y `addons` solo admitía un `module_code`.

## Opciones consideradas

1. **Dejar los planes solo en la consola.** El equipo tendría que cargarlos a mano en cada entorno y el sitio y la base podrían divergir.
2. **Cargarlos como datos iniciales desde el kit, sin montos.** Todos los entornos parten de la misma composición, y el precio sigue siendo decisión de finanzas en la consola.

## Decisión

Opción 2. La composición vive en `docs/01` §4.2 y `tools/esquema/catalogos.py` la carga en `plans`, `plan_modules`, `plan_limits`, `plan_features`, `addons` y la tabla nueva `addon_modules`. Los planes nacen en estado `draft` y sin precio; los complementos, en `inactive`.

## Consecuencias

- `addons` deja de tener `module_code` y gana `web_product_code`; los módulos que habilita un complemento están en `addon_modules`.
- El generador rechaza un plan que rompa `module_dependencies` (por ejemplo, `payroll` sin `time_off`).
- El sitio de referencia muestra montos **de ejemplo**, marcados como tales, desde `kit/tools/sitio/build.py` (`PRECIO_PLAN` y `PRECIO_MODULO`). En el producto, los lee de `/public/catalog`.
- Después de la carga inicial, la consola es la fuente: la tabla del 01 no se vuelve a aplicar sobre planes publicados.

## Comprobación

`python3 kit/tools/esquema/gen.py` falla si un plan rompe una dependencia o nombra un módulo inexistente. Al cargar `schema.sql`, `plans` tiene cuatro filas en `draft` con `price` nulo, y `pruebas_aislamiento.sql` reconoce `addon_modules` como tabla de plataforma.
