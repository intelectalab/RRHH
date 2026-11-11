# Cambios de la versión 1.11 (05/11/2026)

## Decisiones de la fase 0 cerradas

Maximo, desde la dirección del producto, aceptó las recomendaciones del kit para las once decisiones que bloqueaban la fase 0. El desarrollo puede empezar por `PLT-1` (ver `EMPEZAR-AQUI.md` y `backlog/ruta-mvp.md`).

| Decisión | Qué se eligió | ADR |
|---|---|---|
| A-01 · Stack | Laravel 12, Next.js 15, PostgreSQL 17 y Redis | 0004 (nuevo) |
| A-02 · Aislamiento multiempresa | Esquema compartido con `tenant_id`, RLS y claves foráneas compuestas | 0005 (nuevo) |
| A-03 · Identificadores | UUID v7 | 0001 (pasa a aceptado) |
| A-04 · Sesión entre web y API | Cookie `HttpOnly` en el mismo origen, sin tokens en el navegador | 0006 (nuevo) |
| A-08 · Región de alojamiento | Fuera del Perú, con inventario de encargados y garantías | 0007 (nuevo) |
| D-01 · Planes y módulos | Planes con módulos incluidos (Esencial, Profesional, Corporativo, Empresas), más complementos | 0008 (nuevo) |
| D-03 · Límite de colaboradores | Personas únicas con una relación activa; no cuentan locadores, pre-ingresos ni cesados | 0009 (nuevo) |
| D-04 · Cobro | Registro en la consola primero y pasarela después; factura electrónica desde el primer cobro | 0010 (nuevo) |
| D-08 · Verificación en dos pasos | Obligatoria para roles con permisos sensibles; cada organización puede exigirla a todos | 0011 (nuevo) |
| D-13 · Datos al apagar o dar de baja | Módulo en solo lectura con exportación; baja con exportación, periodo de gracia y eliminación certificada | 0012 (nuevo) |
| D-29 · Códigos de permiso | En inglés, con etiqueta traducida | 0002 (pasa a aceptado) |

## Pendientes que no bloquean

- **A-08:** el área legal da su visto bueno dentro de D-15 antes de la salida a producción.
- **D-04:** hay que elegir el proveedor OSE o PSE y confirmar con el contador el IGV y el caso de clientes del exterior antes del primer cobro real.
- **D-13:** el negocio fija con legal los plazos de gracia, de solo lectura y de retención de respaldos. En el código son parámetros de la consola.

## Archivos que cambian

- `docs/adr/`: los ADR 0004 a 0012 son nuevos, 0001 y 0002 pasan a aceptados, y el índice y la tabla de la fase 0 están al día.
- `docs/13-decisiones-abiertas.md`: el resumen y cada una de las once decisiones las marcan como cerradas, con su fecha y su ADR.
- `EMPEZAR-AQUI.md` §2 muestra las decisiones como cerradas y lista los tres pendientes.
- `README.md` y `kit-desarrollo-ia.md` reflejan la nueva versión.
- No hay cambios en el backlog, la API ni el esquema. El backlog regenerado es idéntico al de la v1.10.

## Precios de ejemplo en el sitio

El sitio de referencia ya no muestra «S/ —»: ahora muestra montos de ejemplo para que se vea completo. Son provisionales y Maximo los ajustará.

| Concepto | Monto de ejemplo, sin IGV |
|---|---|
| Plan Esencial, hasta 50 colaboradores | S/ 290 al mes |
| Plan Profesional, hasta 200 colaboradores | S/ 790 al mes |
| Plan Corporativo, hasta 1 000 colaboradores | S/ 1 990 al mes |
| Plan Empresas | A la medida |
| Gestia Tiempo como complemento | S/ 3 por colaborador al mes |
| Gestia Planilla como complemento | S/ 6 por colaborador al mes |
| Gestia Talento y Gestia Formación, al lanzarse | S/ 4 y S/ 3 por colaborador al mes |
| Pago anual | Dos meses gratis |

- Todos los montos se cambian en un solo lugar, al inicio de `tools/sitio/build.py` (`PRECIO_PLAN`, `PRECIO_MODULO`, `DESCUENTO_ANUAL` e `IGV`), y luego se regenera el sitio.
- En el producto los montos no se escriben en el código: viven en la consola y el sitio los lee de `/public/catalog` (ADR 0008).
- La pregunta frecuente «¿Cómo se cuentan los colaboradores?» ahora sigue el ADR 0009.

## Calculadora de planilla de referencia

Responde al documento de requerimientos de planillas y regímenes laborales que Maximo entregó el 05/11/2026.

- **`referencia/calculadora-planilla/`** (nuevo): implementación en Laravel 12 de la calculadora mensual. Incluye la migración, los modelos, un repositorio de parámetros con vigencia, `PayrollService::calculate()` con pasos configurables, el DTO de resultado, un seeder que carga el catálogo del kit y 31 pruebas, 6 de ellas sobre PostgreSQL. Es una referencia para las historias NOM, PAR, CPT, PER y BSO, no las reemplaza. Además del mes, calcula:
  - el prorrateo por ingreso, cese o cambio de sueldo dentro del mes (RN-NOM-05);
  - la retención de quinta por proyección anual, con divisores, meses de corte y pagos extraordinarios (RN-NOM-13 a RN-NOM-16);
  - la liquidación de beneficios sociales: gratificación trunca con bonificación, CTS trunca, vacaciones truncas y pendientes, con sus aportes (`SettlementService`).
- **Correcciones al requerimiento**, explicadas en el README de esa carpeta:
  - RMV de S/ 1 130 y UIT 2026 de S/ 5 500;
  - 15 días de vacaciones en micro y pequeña empresa;
  - SIS con monto fijo en la microempresa;
  - fórmula de la CTS, que dividía la gratificación dos veces;
  - bonificación extraordinaria;
  - tope de la prima de AFP;
  - bases remunerativa y computable separadas;
  - quinta categoría por proyección anual;
  - parámetros con vigencia, UUID v7 y RLS.
- **Catálogo de parámetros legales** (`tools/esquema/catalogos.py`; se regeneraron `database/catalogos/parametros_legales_pe.json` y `docs/19`):
  - 4 valores generales nuevos (`gratificacion_aplica`, `gratificacion_factor`, `cts_aplica` y `cts_dias_por_anio`), para que el régimen general no dependa de valores escritos en el código;
  - `asignacion_familiar_aplica` (1 general, 0 en micro y pequeña empresa, con fuente secundaria) y `dias_base_mes` = 30;
  - `vacaciones_dias_regimen_general` pasa a ser `vacaciones_dias` con régimen `pe_general`, sin valor todavía; `docs/14` usa el nombre nuevo;
  - ahora hay 161 parámetros, 122 con valor y 39 por validar; 98 tienen fecha de inicio por confirmar.
- **Documento para el especialista laboral:** «GestiaHR · Validación de parámetros legales» (Claude Docs), con los 32 parámetros que bloquean el MVP, los 7 que no, los 98 con fecha por confirmar, la tabla por régimen, las fórmulas de la calculadora y las preguntas abiertas, cada fila con su visto bueno.
