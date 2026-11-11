# M-BSO · Beneficios sociales

Fase 3 · Nómina Perú · Épica `BSO` · Reglas `RN-BSO-nn` · Módulo comercial: Nómina Perú (`payroll`)

## 1. Objetivo

Calcular y pagar los beneficios sociales peruanos (gratificaciones, CTS, vacaciones, utilidades, asignación familiar) según el régimen de cada relación.

## 2. Alcance

- Gratificaciones de julio y diciembre, proporcionales y truncas, con bonificación extraordinaria.
- CTS: remuneración computable, depósitos semestrales, entidad depositaria por trabajador, constancia y liquidación de depósito.
- Remuneración vacacional y registro de goce.
- Utilidades: renta base, porcentaje por actividad, distribución por días y por remuneraciones, liquidación individual, tope.
- Asignación familiar según derechohabientes.
- Diferencias por régimen (general, micro, pequeña, otros).

## 3. Fuera de alcance

- Liquidación al cese (M-LIQ). Provisión mensual (M-PRV). Detalle legal en `14-localizacion-peru.md`.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §28 Localización Perú.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** E-21 Utilidades (DL 892).

## 5. Actores y permisos

- Payroll procesa. Aprobador aprueba como cualquier periodo. Empleado recibe boleta y constancias.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `payroll.benefit_runs.view` | Ver procesos de gratificaciones, CTS y utilidades | Administrador empresa, Payroll, Auditor, Contador |
| `payroll.benefit_runs.approve` | Aprobar procesos de gratificaciones, CTS y utilidades | Administrador empresa |
| `payroll.benefit_runs.calculate` | Calcular procesos de gratificaciones, CTS y utilidades | Administrador empresa, Payroll |
| `payroll.benefit_runs.close` | Cerrar procesos de gratificaciones, CTS y utilidades | Administrador empresa, Payroll |
| `payroll.benefit_runs.create` | Crear procesos de gratificaciones, CTS y utilidades | Administrador empresa, Payroll |
| `payroll.benefit_runs.configure_profit_sharing` **(+)** | Registrar renta base, porcentaje y criterios de utilidades | Administrador empresa, Payroll |
| `payroll.benefit_runs.manage_cts` **(+)** | Administrar entidades depositarias y constancias de CTS | Administrador empresa, Payroll |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Gratificación.** 1) Se abre el proceso del semestre. 2) El motor toma la remuneración computable y los meses completos de cada relación. 3) Calcula gratificación y bonificación extraordinaria según régimen. 4) Sigue revisión, aprobación, cierre, boleta y pago.

**CTS.** 1) Se abre el depósito semestral. 2) Se calcula la remuneración computable (incluye un sexto de la última gratificación) y el tiempo computable. 3) Se genera el archivo por entidad depositaria. 4) Tras el depósito, se emite la liquidación de CTS a cada trabajador.

**Utilidades.** 1) Finanzas registra la renta base y la actividad. 2) El motor reparte la mitad por días laborados y la mitad por remuneraciones, aplica el tope individual. 3) Emite la liquidación individual. 4) Pago y retención de quinta.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Beneficios sociales | Procesos por tipo y semestre o año con estado | Prototipo: Nómina → Beneficios sociales |
| Gratificaciones | Detalle por persona: computable, meses, monto, bonificación | Prototipo: Nómina → Beneficios sociales → pestaña Gratificaciones |
| CTS | Computable, tiempo, depósito, entidad, constancia | Prototipo: Nómina → Beneficios sociales → pestaña CTS |
| Utilidades | Datos de la empresa, reparto por criterio, liquidación individual | Prototipo: Nómina → Beneficios sociales → pestaña Utilidades |

## 8. Datos principales

Procesos de beneficio, bases computables por relación y semestre, depósitos de CTS, entidades depositarias, liquidaciones de utilidades, datos anuales de utilidades por empresa.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección BSO.

- **RN-BSO-01** (parámetro): Si la relación está en régimen general → la gratificación equivale a una remuneración computable por semestre completo, proporcional por meses completos laborados (valor de referencia 2026, parámetro con vigencia); pequeña empresa: media remuneración; microempresa: no corresponde.
- **RN-BSO-02** (parámetro): Si se paga gratificación → se añade la bonificación extraordinaria del 9 % de la gratificación (valor de referencia 2026, parámetro con vigencia); el porcentaje para afiliados a EPS es un parámetro a confirmar con la norma vigente.
- **RN-BSO-03** (parámetro): Si se calcula CTS → la remuneración computable incluye el sueldo más un sexto de la última gratificación y los depósitos se programan en la primera quincena de mayo y de noviembre (valores de referencia 2026, parámetros con vigencia).
- **RN-BSO-04** (parámetro): Si la relación es de pequeña empresa → la CTS equivale a 15 remuneraciones diarias por año (valor de referencia 2026, parámetro con vigencia); microempresa: no corresponde.
- **RN-BSO-05**: Si el trabajador no tiene entidad depositaria registrada → el proceso de CTS lo reporta como error y no genera su línea.
- **RN-BSO-06**: Si se realiza el depósito de CTS → se emite a cada trabajador la liquidación con fecha, entidad, cuenta, periodo y cálculo.
- **RN-BSO-07** (parámetro): Si el trabajador tiene uno o más hijos con derecho registrados y sustentados → percibe asignación familiar del 10 % de la RMV vigente (valor de referencia 2026, parámetro con vigencia); el monto se deriva de la RMV, no se digita.
- **RN-BSO-08** (parámetro): Si la empresa supera el umbral de trabajadores que obliga a repartir utilidades (más de 20, valor de referencia 2026) y genera renta → se reparte el porcentaje de su actividad (industria 10 %, comercio y restaurantes 8 %, otras 5 % como valores de referencia; lista completa a confirmar con la norma vigente).
- **RN-BSO-09** (parámetro): Si se distribuyen utilidades → la mitad se reparte en proporción a los días laborados y la mitad en proporción a las remuneraciones (criterio y tope individual: parámetros a confirmar con la norma vigente).
- **RN-BSO-10**: Si una persona cesó durante el ejercicio → participa en las utilidades por el tiempo laborado y se le liquida aunque ya no esté activa.
- **RN-BSO-11**: Si un concepto no está marcado como computable para un beneficio → no entra a su base (M-CPT define afectaciones).
- **RN-BSO-12** (parámetro): Si una remuneración es variable (comisiones, horas extra) → entra a la base computable por promedio del periodo y bajo el requisito de regularidad del paquete de país (parámetros a confirmar).
- **RN-BSO-13** (parámetro): Si se procesa la gratificación o la CTS → el factor y la forma salen del régimen de la relación: una remuneración (general), media (pequeña empresa), no corresponde (microempresa), 40 jornales (construcción civil); las relaciones agrarias con prorrateo se omiten y queda en la traza.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `benefit.gratification_calculated`, `benefit.cts_deposited`, `benefit.profit_sharing_calculated`, `benefit.family_allowance_changed`.

**Consume:**

- `dependent.added`, `parameter.changed`, `payroll.closed`, `employee.terminated`, `employment.regime_changed`.

## 12. Integraciones

Entidades financieras depositarias (archivo), M-DEC.

## 13. Casos borde

- Ingreso o cese dentro del semestre.
- Cambio de régimen MYPE a general dentro del semestre.
- Hijo que cumple la edad límite o continúa estudios superiores (parámetros a confirmar).
- Ambos padres en la misma empresa.
- Días que no cuentan como laborados para utilidades.

## 14. Criterios de aceptación generales

- Gratificaciones, CTS, utilidades y asignación familiar se calculan por régimen con traza y sin valores fijos en código.
