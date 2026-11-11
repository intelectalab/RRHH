# M-CFG · Configuración, parámetros, campos personalizados, i18n y multimoneda

Fase 0 · Cimientos · Épica `CFG` · Reglas `RN-CFG-nn` · Módulo comercial: Plataforma base (`base`)

## 1. Objetivo

Reunir en un solo centro todo lo que una organización ajusta sin programar, con herencia organización → empresa → sede → grupo y con vigencia por fecha.

## 2. Alcance

- Centro de configuración por secciones (organización, empresas, sedes, estructura, usuarios, roles, permisos, campos, workflows, asistencia, vacaciones, planilla, desempeño, reclutamiento, LMS, SST, documentos, integraciones, notificaciones, seguridad, API, auditoría).
- Configuración por empresa: datos fiscales, logo, moneda, zona horaria, idioma, calendario, feriados, políticas.
- Parámetros con vigencia y nivel de herencia; catálogos editables (tipos, motivos, categorías).
- Campos personalizados (texto, número, fecha, selector, selección múltiple, casilla, usuario, archivo, URL, moneda, porcentaje) por entidad, con visibilidad y sensibilidad.
- Idiomas es, en, pt; formatos regionales.
- Multimoneda: moneda por empresa, por compensación y por gasto; tipos de cambio con fecha.
- Calendarios y feriados por país, región y empresa.

## 3. Fuera de alcance

- Parámetros legales del paquete de país (M-PAR los publica; aquí solo se consultan). Marca blanca (M-WLB).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §24 Horarios y jornadas; §79 Configuración por empresa; §80 Multiidioma; §81 Multimoneda; §93 Centro de configuración; §94 Campos personalizados.

**Correcciones que incorpora:** C-03 Historia con vigencia; C-04 La plataforma SaaS va en los cimientos.

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Administrador empresa configura. RRHH ajusta catálogos y políticas de sus módulos.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `settings.organization.view` | Ver la configuración de la organización y de cada empresa | Administrador empresa, RRHH, Auditor |
| `settings.organization.update` | Editar la configuración de la organización y de cada empresa | Administrador empresa |
| `settings.custom_fields.view` | Ver campos personalizados | Administrador empresa, RRHH, Auditor |
| `settings.custom_fields.create` | Crear campos personalizados | Administrador empresa |
| `settings.custom_fields.delete` | Eliminar campos personalizados | Administrador empresa |
| `settings.custom_fields.update` | Editar campos personalizados | Administrador empresa |
| `settings.exchange_rates.view` | Ver monedas y tipos de cambio | Administrador empresa, RRHH, Payroll, Auditor, Contador |
| `settings.exchange_rates.create` | Crear monedas y tipos de cambio | Administrador empresa, Payroll |
| `settings.catalogs.update` **(+)** | Editar catálogos, tipos y motivos | Administrador empresa, RRHH |
| `settings.parameters.view` **(+)** | Ver parámetros y su historial de vigencias | Administrador empresa, RRHH, Auditor |
| `settings.parameters.update` **(+)** | Cambiar parámetros de política con vigencia | Administrador empresa, RRHH |
| `time.holiday_calendars.view` | Ver calendarios y feriados | Administrador empresa, RRHH, Payroll, Manager, Empleado, Auditor |
| `time.holiday_calendars.create` | Crear calendarios y feriados | Administrador empresa, RRHH |
| `time.holiday_calendars.update` | Editar calendarios y feriados | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Cambiar un parámetro.** 1) Se elige nivel (organización, empresa, sede, grupo) y fecha de vigencia. 2) Se guarda como nuevo registro; el anterior se cierra el día previo. 3) Los cálculos usan el valor vigente en la fecha del hecho.

**Campo personalizado.** 1) Se define entidad, tipo, etiqueta por idioma, obligatoriedad, opciones. 2) Se elige en qué sección aparece, quién lo ve y edita, y si es sensible. 3) Queda disponible en formularios, tablas, importador, reportes y API.

**Feriados.** 1) El paquete de país carga feriados nacionales. 2) La empresa añade regionales o propios por sede. 3) Asistencia, vacaciones y planilla los consumen.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Centro de configuración | Índice por secciones con buscador; cada sección abre su pantalla | Prototipo: Configuración → Centro de configuración |
| Empresa | Datos fiscales, logo, moneda, zona horaria, idioma, calendario | Prototipo: Configuración → Centro de configuración → sección Empresas |
| Catálogos | Tipos, motivos y categorías por módulo; crear, ordenar, archivar | Prototipo: Configuración → Catálogos |
| Campos personalizados | Lista por entidad; editor con visibilidad y sensibilidad | Prototipo: Configuración → Centro de configuración → sección Campos personalizados (y pestaña Campos adicionales de la ficha) |
| Parámetros | Parámetros de política con nivel, valor y vigencia; historial | Prototipo: Configuración → Parámetros |
| Calendarios y feriados | Calendarios por país, región, empresa y sede | Prototipo: Calendario (feriados y eventos) |
| Monedas y tipos de cambio | Monedas habilitadas, tipo de cambio por fecha | Prototipo: Configuración → Centro de configuración → sección Monedas y tipos de cambio |

## 8. Datos principales

Parámetros (clave, nivel, valor, vigencia), catálogos, definiciones y valores de campos personalizados, traducciones, monedas, tipos de cambio, calendarios, feriados.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección CFG.

- **RN-CFG-01**: Si un parámetro existe en varios niveles → gana el más específico vigente: grupo, sede, empresa, organización, valor por defecto del paquete de país.
- **RN-CFG-02**: Si se cambia un parámetro → se crea un registro con vigencia; el valor anterior no se sobrescribe y los cálculos de fechas pasadas siguen usando el valor de su fecha.
- **RN-CFG-03**: Si un parámetro es legal (lo publica el paquete de país) → la organización no puede bajarlo por debajo del mínimo legal; solo mejorarlo cuando la norma lo permite.
- **RN-CFG-04**: Si un campo personalizado se marca sensible → hereda las reglas de permiso por campo, cifrado y registro de accesos.
- **RN-CFG-05**: Si un campo personalizado tiene valores cargados → no se elimina ni cambia de tipo; se archiva.
- **RN-CFG-06**: Si un monto se guarda → lleva siempre su moneda; nunca se suman montos de monedas distintas sin tipo de cambio de la fecha.
- **RN-CFG-07** (parámetro): Si falta el tipo de cambio de la fecha → se usa el último anterior disponible y se marca el resultado como «tipo de cambio referencial» (comportamiento parámetro: bloquear o usar anterior).
- **RN-CFG-08**: Si una fecha u hora se muestra → usa la zona horaria de la empresa del registro; se guarda en UTC.
- **RN-CFG-09**: Si un valor de catálogo está en uso → no se elimina; se archiva y deja de ofrecerse para nuevos registros.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `parameter.changed`, `custom_field.created`, `holiday.changed`.

**Consume:**

- `company.created` (siembra configuración por defecto del país).

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Cambio retroactivo de un parámetro que afecta un periodo de planilla cerrado: no recalcula; genera aviso para evaluar un ajuste en el periodo abierto.
- Empresa con sedes en regiones con feriados distintos.
- Campo personalizado obligatorio añadido cuando ya hay registros: solo obliga en nuevas ediciones.

## 14. Criterios de aceptación generales

- Ninguna política vive en código: todas se cambian desde el centro de configuración con vigencia.
- Un campo personalizado nuevo aparece en ficha, tabla, importador, constructor de reportes y API sin despliegue.
