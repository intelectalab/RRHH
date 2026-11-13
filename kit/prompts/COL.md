# COL · Colaboradores y ficha 360

Personas, relaciones laborales y la ficha 360 como fuente única de información del colaborador.

- **Fase:** Fase 1 · Core HR. **Módulo comercial:** Core HR.
- **Actividades:** 12 (10 historias y 2 tareas); 12 del MVP. **Operaciones de API:** 37.
- **Especificación:** `kit/docs/modulos/M-COL.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica COL · Colaboradores y ficha 360 de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-COL.md, kit/prompts/COL.md y la sección COL de
kit/backlog/backlog.md (grep -n "^### COL-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. COL-1 · Personas y deduplicación
2. COL-2 · Alta de relación laboral
3. COL-3 · Ficha 360 del colaborador
4. COL-9 · Listado de colaboradores
5. COL-10 · Reingreso y relaciones simultáneas
6. COL-4 · Datos personales y contactos de emergencia
7. COL-5 · Datos laborales de la ficha
8. COL-11 · Registro del cese de la relación laboral
9. COL-6 · Derechohabientes
10. COL-7 · Régimen pensionario, salud y seguros del trabajador
11. COL-8 · Cuentas bancarias y datos de pago
12. COL-12 · Inicio de RRHH
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: A-05, A-10, D-14, D-17, D-30, D-35. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `COL-1` | Personas y deduplicación | Tarea | MVP | TEN-3, CFG-10 |
| 2 | `COL-2` | Alta de relación laboral | Historia | MVP | COL-1, POS-6, HIS-1 |
| 3 | `COL-3` | Ficha 360 del colaborador | Historia | MVP | COL-2, SEG-9, SEG-10 |
| 4 | `COL-9` | Listado de colaboradores | Historia | MVP | COL-2, UI-4 |
| 5 | `COL-10` | Reingreso y relaciones simultáneas | Historia | MVP | COL-2 |
| 6 | `COL-4` | Datos personales y contactos de emergencia | Historia | MVP | COL-3 |
| 7 | `COL-5` | Datos laborales de la ficha | Historia | MVP | COL-3, HIS-2 |
| 8 | `COL-11` | Registro del cese de la relación laboral | Tarea | MVP | COL-2, HIS-2 |
| 9 | `COL-6` | Derechohabientes | Historia | MVP | COL-4, DOC-1 |
| 10 | `COL-7` | Régimen pensionario, salud y seguros del trabajador | Historia | MVP | COL-4, HIS-1 |
| 11 | `COL-8` | Cuentas bancarias y datos de pago | Historia | MVP | COL-4, SEG-11 |
| 12 | `COL-12` | Inicio de RRHH | Historia | MVP | COL-9, CON-4 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-10`, `CON-4`, `DOC-1`, `HIS-1`, `HIS-2`, `POS-6`, `SEG-9`, `SEG-10`, `SEG-11`, `TEN-3`, `UI-4`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| A-05 | Motor de búsqueda | Fase 1 (revisable en fase 8) | (a) al inicio, detrás de `SearchProvider`; pasar a (b) solo si las pruebas de carga lo exigen. |
| A-10 | Cifrado de campo para sueldos | Fase 1 | (b). La planilla, las provisiones y la analítica necesitan sumar en la base; el control efectivo es el permiso por campo y el registro de accesos. Documento de identidad, cuenta bancaria, salud y denuncias sí van cifrados a nivel de campo. |
| D-14 | Privacidad: qué va en la base y qué en el módulo de cumplimiento | Fase 1 | (c). Base: avisos, consentimientos, derechos del titular, registro de accesos, retención. Módulo: inventario de tratamientos, evaluaciones de impacto, registro de incidentes con seguimiento, informes. |
| D-17 | Eventos públicos: `employee.created` frente a `employee.hired` | Fase 1 | (c), porque el pedido original nombra ambos y las integraciones suelen esperar `employee.created`. Si se necesita reaccionar al pre-ingreso, usar `offer.accepted`. |
| D-30 | Ubicación de los códigos SUNAT derivables (perfil T-Registro) | Fase 3 | (a). Lo declarado es lo que dispara la novedad de modificación; `has_risk_insurance` queda como indicador derivado. Detalle en `18-localizacion-peru-detalle-tecnico.md` §0.2. |
| D-35 | Enumerados ampliados de documento, derechohabiente y novedad del registro laboral | Fase 3 | (a) mientras Perú sea el único paquete; revisar al diseñar el segundo país. |
