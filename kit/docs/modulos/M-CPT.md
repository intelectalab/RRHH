# M-CPT · Conceptos y fórmulas

Fase 3 · Nómina Perú · Épica `CPT` · Reglas `RN-CPT-nn` · Módulo comercial: Nómina Perú (`payroll`)

## 1. Objetivo

Permitir que Payroll defina y mantenga los conceptos de la planilla (ingresos, descuentos, aportes) con su fórmula, condiciones, vigencia, afectaciones y cuenta contable.

## 2. Alcance

- Catálogo por empresa sobre la plantilla del país: código, nombre, tipo (ingreso, descuento, aporte del trabajador, aporte del empleador, impuesto, beneficio, provisión, informativo), naturaleza remunerativa o no.
- Fórmula con editor asistido, variables, funciones y condiciones; conceptos fijos, variables e ingresados por incidencia.
- Afectaciones: a qué bases suma (pensión, salud, renta de quinta, CTS, gratificación, vacaciones, utilidades, indemnización).
- Mapeo a código de la administración tributaria (PLAME) y a cuenta contable; centro de costo.
- Versionado con vigencia; conceptos personalizados (comisión, bono de productividad, movilidad, descuento de préstamo); cláusulas de convenio como conceptos.

## 3. Fuera de alcance

- Valores de tasas y topes (M-PAR). Carga mensual de montos (M-PER).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §29 Motor de conceptos de nómina.

**Correcciones que incorpora:** C-05 Planilla = motor + reglas + país + régimen.

**Ampliaciones que incorpora:** E-17 Sindicatos y convenios; E-20 Teletrabajo (Ley 31572).

## 5. Actores y permisos

- Payroll administra. Contador consulta el mapeo contable.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `payroll.concepts.view` | Ver conceptos y fórmulas | Administrador empresa, Payroll, Auditor, Contador |
| `payroll.concepts.create` | Crear conceptos y fórmulas | Administrador empresa, Payroll |
| `payroll.concepts.publish` | Publicar conceptos y fórmulas | Administrador empresa |
| `payroll.concepts.retire` | Retirar conceptos y fórmulas | Administrador empresa, Payroll |
| `payroll.concepts.test` | Probar conceptos y fórmulas | Administrador empresa, Payroll |
| `payroll.concepts.update` | Editar conceptos y fórmulas | Administrador empresa, Payroll |
| `payroll.concepts.map` **(+)** | Mapear conceptos a códigos de declaración y cuentas contables | Administrador empresa, Payroll |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Nuevo concepto.** 1) Se parte de cero o de un concepto de la plantilla. 2) Se define tipo, naturaleza, fórmula y condiciones. 3) Se marcan afectaciones y mapeos. 4) Se prueba con casos reales en el banco de pruebas. 5) Se publica con fecha de vigencia.

**Cambio de fórmula.** 1) Se crea una nueva versión con fecha. 2) Se compara el resultado contra la versión vigente sobre el último periodo. 3) Se publica; los periodos anteriores siguen con la versión de su fecha.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Conceptos | Tabla con código, nombre, tipo, afectaciones, vigencia, estado; crear, duplicar, archivar | Prototipo: Nómina → Configuración de nómina → pestaña Conceptos |
| Editor de concepto | Datos, fórmula con ayuda y validación, condiciones, afectaciones, mapeos, historial de versiones, prueba | Prototipo: Nómina → Configuración de nómina → pestaña Conceptos |
| Matriz de afectaciones | Conceptos × bases de cálculo en una sola vista | Prototipo: Nómina → Configuración de nómina → pestaña Conceptos |

## 8. Datos principales

Conceptos (`payroll_concepts`) y versiones, fórmulas, condiciones, afectaciones por base, mapeos a declaración y a cuentas, plantilla de conceptos del país.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección CPT.

- **RN-CPT-01**: Si se modifica un concepto usado en periodos cerrados → se crea una versión nueva con vigencia; la anterior no cambia.
- **RN-CPT-02**: Si un concepto tiene resultados históricos → no se elimina; se archiva.
- **RN-CPT-03**: Si un concepto proviene de la plantilla del país → la organización puede cambiar nombre y cuenta contable, pero no su afectación legal sin crear un concepto propio y dejar constancia.
- **RN-CPT-04**: Si la fórmula no supera la validación (sintaxis, referencias, ciclo) → no se guarda como publicable.
- **RN-CPT-05**: Si un concepto no tiene mapeo a código de declaración siendo declarable → la validación previa de PLAME lo reporta como error.
- **RN-CPT-06**: Si un concepto es no remunerativo → no suma a ninguna base salvo que la afectación se marque de forma expresa con sustento.
- **RN-CPT-07**: Si quien edita un concepto intenta publicarlo → se exige que la publicación la haga otra persona con `payroll.concepts.publish` cuando la política de doble control está activa (parámetro; activa por defecto).
- **RN-CPT-08** (parámetro): Si un descuento voluntario llevaría el neto por debajo del mínimo protegido (parámetro del paquete de país, a confirmar) → se descuenta hasta el límite y el resto se difiere.
- **RN-CPT-09**: Si un concepto afecta a la base de quinta → declara su naturaleza en la proyección (ordinario, gratificación o extraordinario); sin ella no se puede activar.
- **RN-CPT-10**: Si un concepto declara a qué regímenes aplica → el motor solo lo ofrece a las relaciones de esos regímenes.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `payroll_concept.created`, `payroll_concept.version_published`, `payroll_concept.archived`.

**Consume:**

- `parameter.changed`, `collective_agreement.clause_added`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Concepto con vigencia que empieza a mitad de periodo.
- Concepto ingresado en otra moneda.
- Dos conceptos con el mismo código de declaración (se suman al declarar).

## 14. Criterios de aceptación generales

- Un concepto personalizado se crea con código, nombre, tipo, fórmula, condiciones, vigencia, afectaciones y contabilización, y se usa en el siguiente cálculo sin programar.
