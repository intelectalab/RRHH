# M-ALE · Alertas explicadas

Fase 8 · Inteligencia · Épica `ALE` · Reglas `RN-ALE-nn` · Módulo comercial: Analítica y reportes (`analytics`)

## 1. Objetivo

Avisar a tiempo de lo que requiere atención, siempre con la explicación de por qué y qué hacer.

## 2. Alcance

- Reglas de alerta por umbral, fecha o tendencia: contratos por vencer, vacaciones acumuladas, cursos obligatorios incompletos, aumento de rotación, documentos por firmar, evaluaciones vencidas, exámenes médicos, pólizas, topes de contrato, anomalías de planilla y de asistencia.
- Destinatarios por rol y alcance; severidad; agrupación; posponer, descartar con motivo, convertir en tarea.
- Explicación obligatoria: regla, datos, registros afectados, acción sugerida.
- Detección de anomalías operativas (estadística simple o IA) con factores visibles.

## 3. Fuera de alcance

- Notificación en sí (M-NOT). Obligaciones legales (M-CUM las evalúa y usa este motor para avisar).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §68 Alertas inteligentes.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** E-26 Riesgo de rotación explicable.

## 5. Actores y permisos

- RRHH configura. Cada rol recibe las de su alcance.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `analytics.turnover_risk.view` **(S)** | Ver indicadores de riesgo de rotación | Administrador empresa, RRHH |
| `alerts.alerts.view` | Ver alertas de su alcance | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Auditor, SST, Contador |
| `alerts.alerts.dismiss` | Descartar alertas de su alcance | Administrador empresa, RRHH, Payroll, Manager, SST |
| `alerts.alerts.resolve` | Resolver alertas de su alcance | Administrador empresa, RRHH, Payroll, Manager, SST |
| `alerts.alerts.snooze` | Posponer alertas de su alcance | Administrador empresa, RRHH, Payroll, Manager, SST |
| `alerts.rules.view` | Ver reglas de alerta | Administrador empresa, RRHH, Auditor |
| `alerts.rules.create` | Crear reglas de alerta | Administrador empresa, RRHH |
| `alerts.rules.pause` | Pausar reglas de alerta | Administrador empresa, RRHH |
| `alerts.rules.resume` | Reanudar reglas de alerta | Administrador empresa, RRHH |
| `alerts.rules.update` | Editar reglas de alerta | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Ciclo de una alerta.** 1) Una regla se evalúa (programada o por evento). 2) Si se cumple, se crea o actualiza la alerta con explicación y registros. 3) Se entrega a destinatarios. 4) El destinatario actúa, pospone o descarta. 5) Cuando la condición deja de cumplirse, la alerta se resuelve sola.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Alertas | Lista por severidad y tema con explicación y enlace a los registros; gestionar | Prototipo: Analytics → Alertas |
| Reglas de alerta | Reglas con condición, umbral, destinatarios, frecuencia; activar | Prototipo: Analytics → Alertas |

## 8. Datos principales

Reglas de alerta, alertas (`alerts`) con explicación y registros afectados, acciones del usuario.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección ALE.

- **RN-ALE-01**: Si una alerta no tiene explicación (regla aplicada, datos y registros afectados) → no se muestra.
- **RN-ALE-02**: Si el destinatario no puede ver alguno de los registros afectados → la alerta se le muestra solo con los que sí puede ver, o no se le envía.
- **RN-ALE-03**: Si la condición deja de cumplirse → la alerta pasa a «Resuelta» automáticamente.
- **RN-ALE-04** (parámetro): Si una alerta se pospone → reaparece en la fecha elegida, con un máximo de posposiciones (parámetro).
- **RN-ALE-05**: Si una regla repetiría la misma alerta para el mismo registro → se actualiza la existente en lugar de crear otra.
- **RN-ALE-06**: Si la alerta proviene de un modelo (anomalía, riesgo) → muestra los factores y su contribución, y se rotula como estimación.
- **RN-ALE-07** (parámetro): Si se evalúa «contratos por vencer», «vacaciones acumuladas» u otras reglas de plazo → usan las anticipaciones configuradas (parámetros por regla).
- **RN-ALE-08**: Si una alerta sugiere una acción sobre una persona (cesar, sancionar, cambiar sueldo) → nunca la ejecuta; solo enlaza al flujo humano.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `alert.raised`, `alert.resolved`, `alert.dismissed`.

**Consume:**

- Eventos de todos los módulos y evaluaciones programadas.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Regla con cientos de registros afectados: una alerta agrupada con listado.
- Alerta sobre datos sensibles a un manager.

## 14. Criterios de aceptación generales

- Cada alerta responde qué pasa, por qué, a quién afecta y qué hacer.
