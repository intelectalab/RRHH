# M-SIN · Sindicatos y convenios

Fase 7 · Operación y cumplimiento · Épica `SIN` · Reglas `RN-SIN-nn` · Módulo comercial: Cumplimiento Perú (`compliance`)

## 1. Objetivo

Administrar la relación colectiva: afiliaciones, cuotas, licencias sindicales y cláusulas de convenio que afectan la planilla.

## 2. Alcance

- Organizaciones sindicales, dirigentes y periodos; afiliación y desafiliación con autorización de descuento.
- Cuota sindical como descuento de planilla y su pago al sindicato.
- Licencias sindicales con cupo.
- Convenios colectivos: vigencia, ámbito, cláusulas económicas como conceptos (bonos, asignaciones, incrementos) con población beneficiaria.
- Pliegos y actas de negociación como expediente.

## 3. Fuera de alcance

- Estrategia de negociación. Asesoría legal.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** ninguno de forma directa; es una ampliación o un cimiento exigido por las correcciones.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** E-17 Sindicatos y convenios.

## 5. Actores y permisos

- RRHH o relaciones laborales administra. Payroll aplica. Auditor consulta.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `unions.unions.view` | Ver sindicatos y dirigentes | Administrador empresa, RRHH, Payroll, Auditor |
| `unions.unions.archive` | Archivar sindicatos y dirigentes | Administrador empresa, RRHH |
| `unions.unions.create` | Crear sindicatos y dirigentes | Administrador empresa, RRHH |
| `unions.unions.update` | Editar sindicatos y dirigentes | Administrador empresa, RRHH |
| `unions.unions.manage_leaves` **(+)** | Administrar licencias sindicales y cupos | Administrador empresa, RRHH |
| `unions.memberships.view` **(S)** | Ver afiliaciones sindicales | Administrador empresa, RRHH, Payroll |
| `unions.memberships.create` **(S)** | Crear afiliaciones sindicales | Administrador empresa, RRHH |
| `unions.memberships.update` **(S)** | Editar afiliaciones sindicales | Administrador empresa, RRHH |
| `unions.agreements.view` | Ver convenios y cláusulas | Administrador empresa, RRHH, Payroll, Auditor, Contador |
| `unions.agreements.activate` | Activar convenios y cláusulas | Administrador empresa, RRHH |
| `unions.agreements.create` | Crear convenios y cláusulas | Administrador empresa, RRHH |
| `unions.agreements.update` | Editar convenios y cláusulas | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Afiliación.** 1) Se registra la afiliación con la autorización de descuento. 2) Desde su vigencia, planilla descuenta la cuota. 3) Cada periodo se genera el pago al sindicato con el detalle.

**Cláusula de convenio.** 1) Se registra el convenio con vigencia y ámbito. 2) Cada cláusula económica se crea como concepto con su población. 3) Planilla la aplica mientras el convenio esté vigente.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Sindicatos | Organizaciones, dirigentes, afiliados (con permiso) | Prototipo: Cumplimiento → Cumplimiento Perú → pestaña Sindicatos |
| Convenios | Convenios con vigencia, ámbito y cláusulas; conceptos vinculados | Prototipo: Cumplimiento → Cumplimiento Perú → pestaña Sindicatos |
| Licencias sindicales | Cupos, uso por dirigente | Prototipo: Cumplimiento → Cumplimiento Perú → pestaña Sindicatos |

## 8. Datos principales

Sindicatos, afiliaciones (dato sensible), dirigentes, convenios (`collective_agreements`), cláusulas, licencias sindicales.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección SIN.

- **RN-SIN-01**: Si se descuenta cuota sindical → debe existir afiliación vigente con autorización del trabajador.
- **RN-SIN-02**: Si el trabajador se desafilia → el descuento cesa desde la fecha de vigencia de la desafiliación.
- **RN-SIN-03**: Si un usuario no tiene el permiso de afiliación → no ve quién está afiliado en ficha, reportes ni exportaciones; la boleta del titular sí muestra su descuento.
- **RN-SIN-04**: Si una cláusula de convenio define un beneficio económico → se aplica solo a la población de su ámbito y dentro de su vigencia.
- **RN-SIN-05** (parámetro): Si un dirigente usa licencia sindical → se descuenta de su cupo (parámetro del convenio o de la norma, a confirmar); agotado, requiere autorización.
- **RN-SIN-06**: Si el convenio vence sin uno nuevo → la continuidad de sus cláusulas es una decisión registrada por RRHH, no automática.
- **RN-SIN-07**: Si la afiliación se usa en analítica → solo en agregados que cumplan el umbral de confidencialidad.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `union.membership_started`, `union.membership_ended`, `collective_agreement.registered`, `collective_agreement.clause_added`, `collective_agreement.expiring`.

**Consume:**

- `employee.terminated`, `payroll.closed`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Trabajador afiliado a dos sindicatos.
- Convenio con efecto retroactivo: diferencias en planilla.
- Dirigente con protección especial ante medidas disciplinarias.

## 14. Criterios de aceptación generales

- Afiliación, cuota, licencias y cláusulas de convenio operan conectadas con planilla y con acceso restringido.
