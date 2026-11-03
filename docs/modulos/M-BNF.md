# M-BNF · Beneficios

Fase 5 · Desarrollo y compensación · Épica `BNF` · Reglas `RN-BNF-nn` · Módulo comercial: Beneficios (`benefits`)

## 1. Objetivo

Publicar y administrar los beneficios que la empresa ofrece, con elegibilidad clara y consulta desde el portal.

## 2. Alcance

- Catálogo: seguro, alimentación, movilidad, educación, descuentos, bienestar, otros; proveedor, costo empresa y trabajador, naturaleza remunerativa o no.
- Elegibilidad por reglas (empresa, sede, puesto, nivel, antigüedad, tipo de contrato).
- Inscripción, dependientes, ventanas de inscripción, bajas.
- Vínculo con planilla (descuento o aporte) y con compensación total.
- Planes EPS y seguros con beneficiarios.

## 3. Fuera de alcance

- Seguros obligatorios (Vida Ley, SCTR: M-CUM y M-SST). Mercado de descuentos externo (integración).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §35 Beneficios.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** E-19 Derechohabientes, seguros y regímenes.

## 5. Actores y permisos

- RRHH administra. Colaborador consulta y se inscribe. Payroll ve efectos en planilla.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `benefits.benefits.view` | Ver beneficios y reglas de elegibilidad | Administrador empresa, RRHH, Payroll, Manager, Empleado, Auditor |
| `benefits.benefits.archive` | Archivar beneficios y reglas de elegibilidad | Administrador empresa, RRHH |
| `benefits.benefits.create` | Crear beneficios y reglas de elegibilidad | Administrador empresa, RRHH |
| `benefits.benefits.update` | Editar beneficios y reglas de elegibilidad | Administrador empresa, RRHH |
| `benefits.enrollments.view` | Ver inscripciones a beneficios | Administrador empresa, RRHH, Payroll; solo sobre lo propio o asignado: Manager, Empleado |
| `benefits.enrollments.approve` | Aprobar inscripciones a beneficios | Administrador empresa, RRHH |
| `benefits.enrollments.create` | Crear inscripciones a beneficios | Administrador empresa, RRHH; solo sobre lo propio o asignado: Manager, Empleado |
| `benefits.enrollments.end` | Finalizar inscripciones a beneficios | Administrador empresa, RRHH |
| `benefits.enrollments.reject` | Rechazar inscripciones a beneficios | Administrador empresa, RRHH |
| `benefits.benefits.cost.view` **(S)** **(+)** | Ver costo por persona de los beneficios | Administrador empresa, RRHH, Payroll, Contador |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Inscripción.** 1) El colaborador ve los beneficios para los que es elegible. 2) Solicita inscripción y registra dependientes si aplica. 3) Se aprueba según el beneficio. 4) Queda activo desde la fecha de efecto y genera el concepto de planilla si corresponde.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Beneficios | Catálogo con elegibilidad, inscritos y costo (con permiso) | Prototipo: Compensación → Beneficios |
| Mis beneficios | Activos y disponibles; inscribirme | Prototipo: Compensación → Beneficios |
| Beneficios del colaborador | Beneficios activos en la ficha | Prototipo: Personas → Colaboradores → Ficha → pestaña Beneficios |

## 8. Datos principales

Beneficios (`benefits`), planes, reglas de elegibilidad, inscripciones, dependientes inscritos, ventanas.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección BNF.

- **RN-BNF-01**: Si la persona no cumple las reglas de elegibilidad → el beneficio no se le muestra como disponible.
- **RN-BNF-02**: Si un beneficio tiene costo para el trabajador → la inscripción exige su aceptación expresa del descuento.
- **RN-BNF-03** (parámetro): Si la inscripción se pide fuera de la ventana definida (parámetro) → requiere excepción de RRHH, salvo evento de vida (ingreso, nacimiento, matrimonio).
- **RN-BNF-04**: Si la persona deja de ser elegible (cambio de puesto, cese) → la inscripción termina en la fecha del cambio y cesa el concepto de planilla.
- **RN-BNF-05**: Si un beneficio es remunerativo → su concepto de planilla lleva las afectaciones correspondientes (M-CPT).
- **RN-BNF-06**: Si se inscribe a un dependiente → debe existir como derechohabiente registrado.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `benefit.enrolled`, `benefit.ended`, `benefit.eligibility_changed`.

**Consume:**

- `employee.hired`, `employment.assignment_changed`, `employee.terminated`, `dependent.added`.

## 12. Integraciones

Aseguradoras, EPS y proveedores por archivo o API (M-INT).

## 13. Casos borde

- Beneficio con cupos limitados.
- Cambio de plan de salud a mitad de vigencia.

## 14. Criterios de aceptación generales

- El catálogo define elegibilidad y el empleado consulta sus beneficios.
