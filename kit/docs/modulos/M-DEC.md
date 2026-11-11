# M-DEC · Declaraciones y archivos

Fase 3 · Nómina Perú · Épica `DEC` · Reglas `RN-DEC-nn` · Módulo comercial: Nómina Perú (`payroll`)

## 1. Objetivo

Generar, validar y controlar los archivos que la empresa debe presentar o enviar: PLAME, T-Registro, AFPnet, pagos bancarios y otros, con seguimiento de su estado.

## 2. Alcance

- T-Registro: altas, bajas y modificaciones de trabajadores, derechohabientes y personal en formación, con control de plazos.
- PLAME: archivos de importación (jornada, ingresos, descuentos, aportes, días subsidiados y no laborados) con mapeo de conceptos y validación previa; rectificatorias.
- AFPnet: planilla de aportes previsionales por AFP.
- Bancos: archivos de pago de haberes, CTS y proveedores de servicios por banco y formato; billeteras.
- Otros: retenciones judiciales, certificados de renta de quinta (a solicitud), declaraciones juradas de rentas de otros empleadores, libro de retenciones de quinta y devoluciones de exceso.
- Ficha del registro laboral por relación (`peruvian_employee_profiles` y tablas hermanas) con validación previa al alta.
- Estructuras de archivo y tablas paramétricas como datos con vigencia; archivo dorado por estructura.
- Estado de cada archivo: generado, validado, presentado, aceptado, observado, rectificado; constancia adjunta.

## 3. Fuera de alcance

- Presentación directa ante la autoridad (se hace en sus portales; integración futura por adaptador). Asiento contable (M-PRV).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §28 Localización Perú.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** E-19 Derechohabientes, seguros y regímenes.

## 5. Actores y permisos

- Payroll genera y presenta. Contador consulta. Administrador supervisa plazos.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `payroll.declaration_files.view` | Ver archivos de declaración y de pago (PLAME, T-Registro, AFPnet, bancos) | Administrador empresa, Payroll, Auditor, Contador |
| `payroll.declaration_files.create` | Crear archivos de declaración y de pago (PLAME, T-Registro, AFPnet, bancos) | Administrador empresa, Payroll |
| `payroll.declaration_files.mark_submitted` | Marcar como presentados archivos de declaración y de pago (PLAME, T-Registro, AFPnet, bancos) | Administrador empresa, Payroll |
| `payroll.declaration_files.rectify` | Rectificar archivos de declaración y de pago (PLAME, T-Registro, AFPnet, bancos) | Administrador empresa, Payroll |
| `payroll.declaration_files.validate` | Validar archivos de declaración y de pago (PLAME, T-Registro, AFPnet, bancos) | Administrador empresa, Payroll |
| `payroll.registry_profiles.view` **(S)** | Ver fichas del registro laboral (T-Registro) | Administrador empresa, RRHH, Payroll, Auditor |
| `payroll.registry_profiles.create` **(S)** | Crear fichas del registro laboral (T-Registro) | Administrador empresa, RRHH, Payroll |
| `payroll.registry_profiles.validate` **(S)** | Validar fichas del registro laboral (T-Registro) | Administrador empresa, RRHH, Payroll |
| `payroll.external_income_declarations.view` **(S)** | Ver declaraciones juradas de rentas de otros empleadores | Administrador empresa, Payroll, Auditor; solo sobre lo propio o asignado: Empleado |
| `payroll.external_income_declarations.create` **(S)** | Crear declaraciones juradas de rentas de otros empleadores | Administrador empresa, Payroll, RRHH; solo sobre lo propio o asignado: Empleado |
| `payroll.external_income_declarations.reject` **(S)** | Rechazar declaraciones juradas de rentas de otros empleadores | Administrador empresa, Payroll |
| `payroll.external_income_declarations.supersede` **(S)** | Reemplazar declaraciones juradas de rentas de otros empleadores | Administrador empresa, Payroll |
| `payroll.external_income_declarations.validate` **(S)** | Validar declaraciones juradas de rentas de otros empleadores | Administrador empresa, Payroll |
| `payroll.fifth_category_ledger.view` **(S)** | Ver el libro de retenciones de quinta categoría | Administrador empresa, Payroll, Auditor, Contador; solo sobre lo propio o asignado: Empleado |
| `payroll.fifth_category_refunds.view` **(S)** | Ver devoluciones de retenciones de quinta en exceso | Administrador empresa, Payroll, Auditor, Contador; solo sobre lo propio o asignado: Empleado |
| `payroll.fifth_category_refunds.close` **(S)** | Cerrar devoluciones de retenciones de quinta en exceso | Administrador empresa |
| `payroll.fifth_category_refunds.compensate` **(S)** | Compensar devoluciones de retenciones de quinta en exceso | Administrador empresa, Payroll |
| `payroll.fifth_category_refunds.create` **(S)** | Crear devoluciones de retenciones de quinta en exceso | Administrador empresa, Payroll |
| `payroll.fifth_category_refunds.refund` **(S)** | Devolver al trabajador devoluciones de retenciones de quinta en exceso | Administrador empresa |
| `payroll.fifth_category_refunds.request_authority_refund` **(S)** | Solicitar a la autoridad la devolución de devoluciones de retenciones de quinta en exceso | Administrador empresa, Payroll |
| `payroll.file_layouts.view` | Ver estructuras oficiales de archivos con vigencia | Administrador empresa, Payroll, Auditor, Contador |
| `payroll.parametric_tables.view` | Ver tablas paramétricas de la planilla electrónica | Administrador empresa, RRHH, Payroll, Auditor, Contador |
| `payroll.file_formats.update` **(+)** | Configurar formatos de banco y mapeos | Administrador empresa, Payroll |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**PLAME.** 1) Con el periodo cerrado, Payroll genera los archivos. 2) La validación previa revisa mapeos, datos de T-Registro, totales y consistencia. 3) Corrige errores en su origen y regenera. 4) Descarga, importa en el aplicativo de la autoridad y adjunta la constancia. 5) El archivo queda «Presentado»/«Aceptado».

**T-Registro.** 1) Un ingreso, cese o cambio declarable crea una novedad pendiente con su fecha límite. 2) Payroll genera el archivo o registra en el portal. 3) Marca presentado con constancia; se entrega la constancia de alta al trabajador.

**Pago bancario.** 1) Se elige el banco y la cuenta de cargo. 2) Se genera el archivo en su formato. 3) Se valida (cuentas, montos, duplicados). 4) Tras procesar en el banco, se carga el resultado y se concilian rechazos.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Declaraciones | Por periodo y tipo: estado, fecha límite, responsable, constancia; generar, validar, presentar | Prototipo: Nómina → Declaraciones |
| Validación previa | Errores y advertencias con enlace al dato a corregir | Prototipo: Nómina → Declaraciones |
| T-Registro | Novedades pendientes con plazo; generar archivo; constancias | Prototipo: Nómina → Declaraciones |
| Pagos bancarios | Lotes por banco con total, estado y rechazos | Prototipo: Nómina → Declaraciones |
| Formatos | Bancos y estructuras de archivo configurables | Prototipo: Nómina → Configuración de nómina |
| Ficha del registro laboral | Datos del T-Registro de la relación con vigencia, códigos de las tablas paramétricas, faltantes y resultado de la validación previa al alta | Prototipo: Nómina → Declaraciones |
| Rentas de otros empleadores | Declaraciones juradas por trabajador y ejercicio (empleador anterior o simultáneo), detalle mensual, quién retiene | Prototipo: Nómina → Declaraciones |
| Libro de retenciones de quinta | Por trabajador y ejercicio: proyección, impuesto anual, retenciones deducidas, divisor, retención ordinaria y adicional, traza; devoluciones de exceso | Prototipo: Nómina → Declaraciones |
| Estructuras y tablas paramétricas | Estructuras de archivo con vigencia y marca de verificada; tablas paramétricas de la planilla electrónica | Prototipo: Nómina → Configuración de nómina |

## 8. Datos principales

Archivos generados (`statutory_filings`) con tipo, periodo, versión, hash, estado; novedades de T-Registro; lotes de pago y líneas; constancias; formatos de archivo.  Ficha del registro laboral (`peruvian_employee_profiles`, establecimientos, periodos declarados, direcciones y estudios codificados, derechohabientes), tablas paramétricas (`pe_parametric_tables`, `pe_parametric_items`), estructuras de archivo (`statutory_file_layouts`), declaraciones de rentas de otros empleadores, libro de retenciones de quinta (`fifth_category_ledger`, solo inserción) y devoluciones (`fifth_category_refunds`).

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección DEC.

- **RN-DEC-01**: Si el periodo no está cerrado → no se generan archivos definitivos de PLAME, AFPnet ni pago (solo borradores de validación).
- **RN-DEC-02**: Si la validación previa encuentra errores → el archivo no puede marcarse como presentable hasta corregirlos en su origen.
- **RN-DEC-03**: Si un archivo fue presentado → no se regenera encima; un cambio produce una rectificatoria enlazada con nueva versión.
- **RN-DEC-04** (parámetro): Si ocurre un ingreso, un cese o una modificación declarable → se crea la novedad de T-Registro con su fecha límite (alta: dentro del día del ingreso; modificación: 5 días hábiles; baja: día siguiente, tipo de día a confirmar; parámetros `treg_*`) y se alerta antes de vencer.
- **RN-DEC-05**: Si un trabajador no tiene completos los datos exigidos (régimen pensionario, de salud, tipo de contrato, establecimiento) → la novedad de alta queda bloqueada con la lista de faltantes.
- **RN-DEC-06**: Si un concepto declarable no tiene código de la administración tributaria → PLAME no se puede validar.
- **RN-DEC-07**: Si se genera el archivo de AFPnet → lleva por trabajador la identificación, los indicadores de relación laboral, la remuneración asegurable y los aportes voluntarios; el aporte, la comisión y la prima los calcula AFPnet, y el sistema concilia su propio cálculo contra la liquidación devuelta.
- **RN-DEC-08**: Si el banco rechaza pagos → cada rechazo se concilia contra su línea y la persona queda «pago pendiente» hasta resolver.
- **RN-DEC-09**: Si se genera un archivo de pago → el total debe coincidir con el neto del periodo cerrado; cualquier diferencia impide exportarlo.
- **RN-DEC-10**: Si un archivo se descarga → se registra quién y cuándo, y se guarda su hash.
- **RN-DEC-11**: Si se genera un archivo oficial → se usa la estructura (`statutory_file_layouts`) vigente en la fecha del periodo; con una estructura no verificada el archivo solo sale como borrador.
- **RN-DEC-12**: Si se genera un archivo → se guardan su huella SHA-256, tamaño, totales de control, estructura y versión del generador; regenerar el mismo periodo cerrado produce los mismos bytes.
- **RN-DEC-13**: Si un concepto con monto no tiene mapeo vigente a un código de la tabla de conceptos de la autoridad en la fecha del periodo → la validación previa falla; el mapeo tiene vigencia y marca de verificado.
- **RN-DEC-14**: Si se intenta el alta de un trabajador en el registro laboral → debe pasar las comprobaciones TR-01 a TR-22 (`18-localizacion-peru-detalle-tecnico.md` §1.5); cada fallo indica campo, pantalla de origen y si bloquea.
- **RN-DEC-15**: Si cambia un dato declarable de la ficha del registro laboral → se cierra la fila vigente, se abre otra y se crea la novedad de modificación; lo declarado se compara con lo derivado del núcleo.
- **RN-DEC-16** (parámetro): Si se presenta la planilla de aportes previsionales → debe declararse y pagarse dentro de `afpnet_plazo_dias_habiles` del mes siguiente; el calendario alerta con los días hábiles de la sede.
- **RN-DEC-17**: Si la planilla es semanal o quincenal → la declaración mensual consolida todas las corridas cerradas del mismo periodo tributario (`statutory_period_code`) y no se genera si falta cerrar alguna.
- **RN-DEC-18**: Si un trabajador presenta una declaración jurada de rentas de otros empleadores → se guarda con su documento, no se sobrescribe (una nueva reemplaza a la anterior) y alimenta la proyección de quinta desde el mes en que se valida.
- **RN-DEC-19**: Si se calcula la retención de quinta → se inserta una fila en el libro de retenciones con entradas, parámetros y traza; un recálculo es otra fila, nunca una modificación.
- **RN-DEC-20**: Si hay retención en exceso → se devuelve al cese (en la liquidación) o en la regularización de diciembre, se compensa contra las retenciones del periodo y el saldo se arrastra o se solicita a la autoridad (ME-49).

## 10. Estados

- ME-24 · Archivo de declaración o pago (`05-maquinas-de-estado.md`): estados Borrador, Validado, Con errores, Presentado, Aceptado, Observado, Rectificado.
- ME-49 · Devolución de retención de quinta en exceso (`05-maquinas-de-estado.md`): estados Pendiente, Devuelto al trabajador, Compensado, Solicitado a la autoridad, Cerrado.

## 11. Eventos

**Emite:**

- `filing.generated`, `filing.validated`, `filing.submitted`, `filing.accepted`, `filing.observed`, `filing.rectified`, `tregistro.pending`, `bank_batch.generated`, `bank_batch.reconciled`.

**Consume:**

- `payroll.closed`, `employee.hired`, `employee.terminated`, `dependent.added`, `employment.regime_changed`, `benefit.cts_deposited`.

## 12. Integraciones

Portales de la autoridad tributaria y de AFPnet (carga manual de archivo; adaptador para integración directa cuando exista), bancos y billeteras.

## 13. Casos borde

- Trabajador que cambia de AFP dentro del periodo.
- Rectificatoria por ajuste retroactivo.
- Alta declarada fuera de plazo: se registra la contingencia.
- Empresa con varios bancos pagadores.

## 14. Criterios de aceptación generales

- PLAME, T-Registro, AFPnet, archivos bancarios y contables se generan con validación previa y con su estado y constancia.
