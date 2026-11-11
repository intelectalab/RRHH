# M-ENT · Entrevistas y ofertas

Fase 4 · Talento · Épica `ENT` · Reglas `RN-ENT-nn` · Módulo comercial: Reclutamiento (`recruiting`)

## 1. Objetivo

Organizar entrevistas con evaluación estructurada y emitir ofertas aprobadas, de modo que la decisión de contratar quede sustentada.

## 2. Alcance

- Agenda: fecha, hora, entrevistadores, tipo, lugar o enlace, guía y formulario de evaluación (scorecard).
- Disponibilidad e invitaciones de calendario; recordatorios; reprogramación.
- Scorecards por competencia con escala y comentario; consolidado por candidato.
- Preguntas sugeridas por IA como apoyo.
- Ofertas desde plantilla con variables (`{{nombre}}`, `{{cargo}}`, `{{salario}}`, `{{fecha_ingreso}}`); borrador → revisión → aprobación → enviada → aceptada o rechazada; vencimiento; firma.

## 3. Fuera de alcance

- Tablero de candidatos (M-ATS). Pre-onboarding (M-ONB).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §13 Entrevistas; §14 Ofertas laborales.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Recruiter agenda y redacta. Entrevistadores evalúan. RRHH y finanzas aprueban ofertas. Candidato acepta o rechaza.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `recruiting.interviews.view` | Ver entrevistas | Administrador empresa, RRHH, Recruiter; solo sobre lo propio o asignado: Manager |
| `recruiting.interviews.cancel` | Cancelar entrevistas | Administrador empresa, RRHH, Recruiter |
| `recruiting.interviews.complete` | Completar entrevistas | Administrador empresa, RRHH, Recruiter; solo sobre lo propio o asignado: Manager |
| `recruiting.interviews.create` | Crear entrevistas | Administrador empresa, RRHH, Recruiter |
| `recruiting.interviews.update` | Editar entrevistas | Administrador empresa, RRHH, Recruiter |
| `recruiting.scorecards.view` | Ver evaluaciones de entrevista | Administrador empresa, RRHH, Recruiter |
| `recruiting.scorecards.create` | Crear evaluaciones de entrevista | solo sobre lo propio o asignado: Administrador empresa, RRHH, Recruiter, Manager |
| `recruiting.offers.view` | Ver ofertas laborales | Administrador empresa, RRHH, Recruiter |
| `recruiting.offers.accept` | Aceptar ofertas laborales (lo ejerce el candidato por enlace seguro, sin rol) | — |
| `recruiting.offers.approve` | Aprobar ofertas laborales | Administrador empresa, RRHH |
| `recruiting.offers.create` | Crear ofertas laborales | Administrador empresa, RRHH, Recruiter |
| `recruiting.offers.decline` | Rechazar (firmante o candidato) ofertas laborales (lo ejerce el candidato por enlace seguro, sin rol) | — |
| `recruiting.offers.send` | Enviar ofertas laborales | Administrador empresa, RRHH, Recruiter |
| `recruiting.offers.submit` | Enviar ofertas laborales | Administrador empresa, RRHH, Recruiter |
| `recruiting.offers.update` | Editar ofertas laborales | Administrador empresa, RRHH, Recruiter |
| `recruiting.offers.withdraw` | Retirar ofertas laborales | Administrador empresa, RRHH, Recruiter |
| `recruiting.offers.amount.view` **(S)** **(+)** | Ver el monto de la oferta | Administrador empresa, RRHH, Recruiter |
| `recruiting.offers.amount.edit` **(S)** **(+)** | Editar el monto de la oferta | Administrador empresa, RRHH, Recruiter |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Entrevista.** 1) El reclutador propone horario según disponibilidad. 2) Se invita a entrevistadores y candidato. 3) Tras la entrevista, cada entrevistador completa su scorecard. 4) El consolidado se muestra cuando todos terminaron.

**Oferta.** 1) Se genera desde plantilla con datos de la posición y el candidato. 2) Revisión. 3) Aprobación (monto frente a banda y presupuesto). 4) Se envía con enlace seguro y fecha de vencimiento. 5) El candidato acepta (firma) o rechaza con motivo. 6) Aceptada: `offer.accepted` inicia el pre-onboarding y reserva la posición.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Agenda de entrevistas | Calendario y lista con estado; agendar, reprogramar | Prototipo: Talento → Reclutamiento |
| Evaluación de entrevista | Guía, competencias con escala, comentarios, recomendación | Prototipo: Talento → Reclutamiento |
| Ofertas | Lista con candidato, posición, estado, vencimiento; crear, enviar | Prototipo: Talento → Reclutamiento |
| Oferta (vista del candidato) | Documento, aceptar con firma o rechazar | Prototipo: Talento → Reclutamiento |

## 8. Datos principales

Entrevistas (`interviews`), participantes, guías, plantillas de scorecard, evaluaciones, ofertas (`job_offers`) y versiones, respuestas.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección ENT.

- **RN-ENT-01**: Si un entrevistador no ha enviado su evaluación → no ve las de los demás (evita sesgo de arrastre).
- **RN-ENT-02**: Si se agenda una entrevista → se valida que entrevistadores no tengan ausencia aprobada ni otra entrevista en ese horario.
- **RN-ENT-03** (parámetro): Si el monto de la oferta supera el máximo de la banda del puesto o el presupuesto de la posición → la aprobación exige el nivel adicional configurado (parámetro).
- **RN-ENT-04**: Si la oferta no está aprobada → no se puede enviar.
- **RN-ENT-05** (parámetro): Si la oferta no se responde antes de su vencimiento (parámetro) → pasa a «Vencida» y la posición queda libre.
- **RN-ENT-06**: Si el candidato acepta → la aceptación se registra con la evidencia de firma y se crea el proceso de pre-onboarding.
- **RN-ENT-07**: Si una oferta enviada necesita cambios → se retira y se emite una nueva versión; la anterior se conserva.
- **RN-ENT-08**: Si ya existe una oferta aceptada para la misma posición de cupo único → no se puede enviar otra.
- **RN-ENT-09**: Si la IA propone preguntas o un resumen → el entrevistador decide usarlos; la recomendación de contratar siempre la registra una persona.

## 10. Estados

- ME-07 · Oferta laboral (`05-maquinas-de-estado.md`): estados Borrador, En revisión, En aprobación, Aprobada, Enviada, Aceptada, Rechazada, Vencida, Retirada.

## 11. Eventos

**Emite:**

- `interview.scheduled`, `interview.rescheduled`, `interview.cancelled`, `interview.completed`, `scorecard.submitted`, `offer.created`, `offer.approved`, `offer.sent`, `offer.accepted`, `offer.declined`, `offer.expired`, `offer.withdrawn`.

**Consume:**

- `application.stage_changed`, `approval.approved`, `signature.completed`.

## 12. Integraciones

Calendarios y videollamada (M-INT), firma (M-DOC).

## 13. Casos borde

- Candidato que acepta fuera de plazo.
- Entrevistador que cesa antes de evaluar.
- Contraoferta: nueva versión con nueva aprobación.

## 14. Criterios de aceptación generales

- Los entrevistadores evalúan con formularios estructurados y la oferta recorre borrador, revisión, aprobación, envío y respuesta.
