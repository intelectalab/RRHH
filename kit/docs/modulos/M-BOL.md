# M-BOL · Boletas de pago

Fase 3 · Nómina Perú · Épica `BOL` · Reglas `RN-BOL-nn` · Módulo comercial: Nómina Perú (`payroll`)

## 1. Objetivo

Generar y entregar la boleta de pago de forma digital, con constancia de entrega, y mantener su historial accesible para el trabajador y para una inspección.

## 2. Alcance

- Plantilla de boleta por empresa con los datos exigidos; PDF.
- Publicación automática en el portal al cerrar; aviso al colaborador; constancia de emisión y de acceso.
- Firma del empleador (representante) y acuse o firma del trabajador según política.
- Historial por trabajador, descarga individual y masiva, reemisión controlada.
- Certificado de rentas y retenciones de quinta categoría: al cese, al cierre del ejercicio o a solicitud (su entrega ya no es obligatoria; el trabajador acredita ante otro empleador con declaración jurada).

## 3. Fuera de alcance

- Cálculo. Otros documentos (M-DOC).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §31 Boletas de pago.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Payroll genera y publica. Empleado consulta y descarga. Auditor verifica entregas.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `payroll.payslips.view` | Ver boletas de pago | Administrador empresa, Payroll, Auditor, Contador; solo sobre lo propio o asignado: RRHH, Recruiter, Manager, Empleado, SST |
| `payroll.payslips.acknowledge` | Dar acuse de boletas de pago | solo sobre lo propio o asignado: todos los roles de organización |
| `payroll.payslips.publish` | Publicar boletas de pago | Administrador empresa, Payroll |
| `payroll.payslips.void` | Anular boletas de pago | Administrador empresa |
| `payroll.payslips.bulk_download` **(+)** | Descarga masiva de boletas | Administrador empresa, Payroll |
| `payroll.payslips.update_template` **(+)** | Configurar la plantilla de boleta | Administrador empresa, Payroll |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Emisión.** 1) Al cerrar el periodo se generan los PDF. 2) Se firman con la firma del representante. 3) Se publican en el portal y se notifica. 4) Cada acceso del trabajador queda registrado. 5) Si la política exige acuse, el trabajador lo confirma.

**Reemisión.** 1) Se anula la boleta con motivo. 2) Se genera la nueva versión ligada al ajuste. 3) Ambas quedan en el historial.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Boletas | Por periodo: generadas, publicadas, vistas, con acuse; publicar, descargar en bloque | Prototipo: Nómina → Planilla |
| Mis boletas | Historial propio, ver, descargar | Prototipo: Nómina → Mis boletas |
| Constancias de entrega | Emisión y accesos por trabajador y periodo; exportar | Prototipo: Nómina → Planilla |
| Plantilla de boleta | Logo, bloques, textos, firmas | Prototipo: Nómina → Configuración de nómina |

## 8. Datos principales

Boletas (`payslips`) con versión, hash, estado, fecha de publicación; accesos; acuses; certificados anuales.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección BOL.

- **RN-BOL-01**: Si el periodo se cierra → se genera una boleta por relación con resultado y se publica en el portal del titular.
- **RN-BOL-02**: Si el trabajador abre o descarga su boleta → se registra fecha y hora como constancia de entrega.
- **RN-BOL-03** (parámetro): Si la política de la empresa exige acuse o firma del trabajador (parámetro) → la boleta queda «pendiente de acuse» y se recuerda hasta obtenerlo.
- **RN-BOL-04**: Si una boleta ya fue publicada → no se sobrescribe; una corrección genera una nueva versión y conserva la anterior anulada.
- **RN-BOL-05** (parámetro): Si una boleta tiene menos años que el plazo de conservación de documentos de pago (cinco años como valor de referencia, parámetro a confirmar con la norma vigente) → no puede purgarse.
- **RN-BOL-06**: Si un usuario distinto del titular consulta una boleta → necesita `payroll.payslips.view` y `payroll.results.view`, y el acceso queda en el registro de accesos sensibles.
- **RN-BOL-07**: Si el trabajador no tiene acceso digital → la boleta puede marcarse «entregada en físico» con cargo escaneado.
- **RN-BOL-08**: Si se descarga en bloque → el archivo se entrega por enlace temporal y queda registrado quién lo pidió.

## 10. Estados

- ME-20 · Boleta de pago (`05-maquinas-de-estado.md`): estados Generada, Publicada, Vista, Con acuse, Anulada.

## 11. Eventos

**Emite:**

- `payslip.generated`, `payslip.published`, `payslip.viewed`, `payslip.acknowledged`, `payslip.voided`.

**Consume:**

- `payroll.closed`, `payroll.reopened`, `signature.completed`.

## 12. Integraciones

Firma (M-DOC), WhatsApp para consulta (M-WHA).

## 13. Casos borde

- Ex-colaborador que necesita boletas antiguas.
- Boleta de un periodo importado como histórico (solo lectura, marcada).
- Persona con dos relaciones: una boleta por relación.

## 14. Criterios de aceptación generales

- Las boletas se generan en PDF, se publican solas, tienen historial por trabajador y descarga individual y masiva con constancia de entrega.
