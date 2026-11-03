# M-DOC · Gestión documental, plantillas y firma

Fase 1 · Core HR · Épica `DOC` · Reglas `RN-DOC-nn` · Módulo comercial: Core HR (`core`)

## 1. Objetivo

Guardar, generar y firmar los documentos laborales con permisos por documento, versiones y evidencia suficiente para una inspección.

## 2. Alcance

- Repositorio por colaborador y por empresa con carpetas (personal, contratos, boletas, certificados, vacaciones, SST, evaluaciones, capacitaciones) y tipos documentales.
- Subir, descargar, previsualizar, versionar, archivar, solicitar al colaborador, aprobar, vencimientos.
- Generador con plantillas y variables (`{{empleado.nombre}}`), salida PDF, generación individual y masiva.
- Firma electrónica propia (hash, sello de tiempo, IP, OTP, constancia) y capa de proveedor para firma digital con certificado; firmantes en orden o en paralelo.
- Certificados a pedido (trabajo, constancias) con emisión automática o con aprobación.
- Acuses de lectura de políticas y reglamentos.

## 3. Fuera de alcance

- Boletas (M-BOL las genera y las deposita aquí). Contenido de contratos (M-CON).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §19 Gestión documental; §20 Generador de documentos; §21 Firma electrónica.

**Correcciones que incorpora:** C-12 Firma electrónica con evidencia y capa de proveedor.

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- RRHH administra. Colaborador sube lo solicitado, firma y descarga. Manager firma lo que le corresponde. Representante legal firma por la empresa.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `documents.documents.view` | Ver documentos | Administrador empresa, RRHH, Payroll, Manager, Auditor, SST; solo sobre lo propio o asignado: Empleado |
| `documents.documents.archive` | Archivar documentos | Administrador empresa, RRHH |
| `documents.documents.create` | Crear documentos | Administrador empresa, RRHH, Payroll, SST; solo sobre lo propio o asignado: Empleado |
| `documents.documents.new_version` | Subir nueva versión de documentos | Administrador empresa, RRHH, Payroll, SST; solo sobre lo propio o asignado: Empleado |
| `documents.documents.restore` | Restaurar documentos | Administrador empresa, RRHH |
| `documents.documents.update` | Editar documentos | Administrador empresa, RRHH |
| `documents.documents.approve` **(+)** | Aprobar o rechazar documentos entregados | Administrador empresa, RRHH |
| `documents.documents.request` **(+)** | Solicitar documentos a colaboradores | Administrador empresa, RRHH |
| `documents.templates.view` | Ver plantillas de documentos | Administrador empresa, RRHH, Payroll, Auditor |
| `documents.templates.create` | Crear plantillas de documentos | Administrador empresa, RRHH |
| `documents.templates.generate` | Generar documentos desde plantillas de documentos | Administrador empresa, RRHH, Payroll |
| `documents.templates.publish` | Publicar plantillas de documentos | Administrador empresa, RRHH |
| `documents.templates.retire` | Retirar plantillas de documentos | Administrador empresa, RRHH |
| `documents.templates.update` | Editar plantillas de documentos | Administrador empresa, RRHH |
| `documents.signature_requests.view` | Ver envíos de firma | Administrador empresa, RRHH, Payroll, Auditor; solo sobre lo propio o asignado: Manager, Empleado |
| `documents.signature_requests.cancel` | Cancelar envíos de firma | Administrador empresa, RRHH, Payroll |
| `documents.signature_requests.create` | Crear envíos de firma | Administrador empresa, RRHH, Payroll |
| `documents.signature_requests.decline` | Rechazar (firmante o candidato) envíos de firma | solo sobre lo propio o asignado: todos los roles de organización |
| `documents.signature_requests.remind` | Enviar recordatorio de envíos de firma | Administrador empresa, RRHH, Payroll |
| `documents.signature_requests.send` | Enviar envíos de firma | Administrador empresa, RRHH, Payroll |
| `documents.signature_requests.sign` | Firmar envíos de firma | solo sobre lo propio o asignado: todos los roles de organización |
| `documents.signature_requests.evidence` **(+)** | Descargar la constancia de evidencia de firma | Administrador empresa, RRHH, Auditor |
| `documents.document_types.update` **(+)** | Administrar tipos, visibilidad y vencimientos | Administrador empresa |
| `documents.documents.confidential.view` **(S)** **(+)** | Ver documentos de tipos marcados confidenciales | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Solicitar un documento.** 1) RRHH pide un tipo documental a una o varias personas con fecha límite. 2) El colaborador lo sube desde el portal. 3) RRHH aprueba o rechaza con motivo. 4) Aprobado queda vigente; si tiene vencimiento, se agenda la alerta.

**Generar y firmar.** 1) Se elige plantilla y destinatarios. 2) Se rellenan variables con datos vigentes y se revisa la vista previa. 3) Se definen firmantes y orden. 4) Cada firmante recibe el aviso, ve el documento, confirma con OTP y firma. 5) Al completarse se sella el PDF, se guarda la constancia de evidencia y se archiva en la carpeta.

**Certificado a pedido.** 1) El colaborador lo pide desde el portal. 2) Si el tipo es de emisión automática, se genera con firma del representante precargada o sello de la empresa; si no, pasa por RRHH. 3) Queda en sus documentos.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Documentos | Repositorio por carpeta y tipo; búsqueda, filtros, vencimientos, subir, solicitar | Prototipo: Operaciones → Documentos |
| Documentos del colaborador | Carpetas de la persona con estado y versión; previsualizar, descargar | Prototipo: Personas → Colaboradores → Ficha → pestaña Documentos |
| Solicitudes de documentos | Pendientes de entrega y de revisión; aprobar, rechazar | Prototipo: Operaciones → Documentos |
| Plantillas | Lista con versión y uso; editor con variables y vista previa con datos reales | Prototipo: Operaciones → Documentos |
| Firma | Envíos con estado por firmante (enviado, visto, firmado), recordar, cancelar, constancia | Prototipo: Operaciones → Firma electrónica |
| Firmar documento | Vista del documento, aceptación, OTP, firma; rechazo con motivo | Prototipo: Operaciones → Firma electrónica |

## 8. Datos principales

Documentos y versiones, tipos documentales, carpetas, solicitudes de documento, plantillas y versiones, envíos de firma, firmantes, eventos de firma (visto, OTP, firma), constancias de evidencia, acuses de lectura.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección DOC.

- **RN-DOC-01**: Si se sube una nueva versión de un documento → la anterior se conserva y queda consultable.
- **RN-DOC-02**: Si un documento está firmado → no se modifica ni se reemplaza; un cambio exige un documento nuevo (adenda o nueva versión con nueva firma).
- **RN-DOC-03**: Si un firmante firma → se registran hash del documento, sello de tiempo, IP, método de verificación (OTP) y se añade a la constancia de evidencia.
- **RN-DOC-04**: Si el envío es secuencial → el firmante siguiente no recibe el documento hasta que firme el anterior.
- **RN-DOC-05**: Si un firmante rechaza → el envío termina «Rechazado» con motivo y se notifica al emisor.
- **RN-DOC-06** (parámetro): Si el envío no se completa antes de su fecha de caducidad (parámetro) → pasa a «Vencido» y se puede reenviar.
- **RN-DOC-07**: Si el hash del documento almacenado no coincide con el de la constancia → se marca como alterado y se alerta.
- **RN-DOC-08**: Si el tipo documental es confidencial → solo lo ven el titular y quienes tengan el permiso de confidenciales dentro de su alcance; cada descarga queda en el registro de accesos.
- **RN-DOC-09** (parámetro): Si un documento con vencimiento llega a la ventana de aviso (parámetro por tipo) → se genera alerta al responsable y al titular.
- **RN-DOC-10**: Si una plantilla usa una variable que el emisor no puede ver (sueldo) → la generación exige que el emisor tenga el permiso del campo.
- **RN-DOC-11**: Si una plantilla cambia → los documentos ya generados conservan la versión con la que se emitieron.
- **RN-DOC-12** (parámetro): Si un tipo documental requiere firma con certificado digital (parámetro por tipo) → el envío usa el proveedor de firma digital; en otro caso, la firma electrónica propia.
- **RN-DOC-13**: Si el colaborador cesa → conserva acceso a sus documentos personales en modo ex-colaborador durante el periodo configurado.

## 10. Estados

- ME-10 · Flujo de firma (`05-maquinas-de-estado.md`): estados Borrador, Enviado, Visto, Firmado parcialmente, Completado, Rechazado, Vencido, Cancelado.
- ME-12 · Documento (`05-maquinas-de-estado.md`): estados Solicitado, Entregado (en revisión), Vigente, Rechazado, Por vencer, Vencido, Archivado.

## 11. Eventos

**Emite:**

- `document.uploaded`, `document.requested`, `document.approved`, `document.rejected`, `document.expiring`, `document.generated`, `signature.sent`, `signature.viewed`, `signature.signed`, `signature.completed`, `signature.declined`, `signature.expired`.

**Consume:**

- `employee.hired` (solicita documentos de ingreso), `payslip.published`, `contract.created`, `policy.published`.

## 12. Integraciones

Proveedores de firma digital con certificado, sellado de tiempo, OCR opcional; todos por adaptador.

## 13. Casos borde

- Firmante sin correo: firma con documento + OTP al teléfono.
- Firmante que cesa antes de firmar: el envío se cancela o se reasigna.
- Generación masiva con datos incompletos: se listan los omitidos y el motivo.
- Documento físico histórico escaneado: se marca «firma manuscrita, digitalizado».

## 14. Criterios de aceptación generales

- El flujo documento → firmantes → orden → enviado → visto → firmado → completado deja una constancia verificable.
- Cambiar de proveedor de firma no modifica el módulo.
- Un certificado de trabajo se genera con datos vigentes de la ficha.
