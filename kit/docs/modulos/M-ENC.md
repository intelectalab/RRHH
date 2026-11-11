# M-ENC · Encuestas, clima y eNPS

Fase 6 · Experiencia · Épica `ENC` · Reglas `RN-ENC-nn` · Módulo comercial: Clima y encuestas (`engagement`)

## 1. Objetivo

Escuchar a las personas con encuestas confiables, con anonimato real cuando se promete, y convertir los resultados en planes de acción con seguimiento.

## 2. Alcance

- Constructor de encuestas: tipos de pregunta, secciones, lógica, plantillas (clima, compromiso, onboarding, salida, pulso, eNPS, personalizada).
- Población, calendario, recordatorios, canales (portal, enlace, WhatsApp).
- Anonimato configurable con umbral mínimo por segmento y sin vínculo respuesta–persona.
- Resultados: participación, promedios, distribución, eNPS (promotores, pasivos, detractores), tendencias, mapa de calor por área, comentarios con resumen por IA.
- Planes de acción con responsable, fecha y avance.

## 3. Fuera de alcance

- Evaluaciones de desempeño. Encuestas a clientes.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §49 Encuestas; §50 eNPS.

**Correcciones que incorpora:** C-06 Anonimato real.

**Ampliaciones que incorpora:** E-25 Clima con mapa de calor y planes de acción.

## 5. Actores y permisos

- RRHH diseña y analiza. Managers ven resultados de su área y gestionan acciones. Colaboradores responden.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `engagement.surveys.view` | Ver encuestas | Administrador empresa, RRHH, Manager, Auditor |
| `engagement.surveys.close` | Cerrar encuestas | Administrador empresa, RRHH |
| `engagement.surveys.create` | Crear encuestas | Administrador empresa, RRHH |
| `engagement.surveys.launch` | Lanzar encuestas | Administrador empresa, RRHH |
| `engagement.surveys.results` | Ver resultados agregados de encuestas | Administrador empresa, RRHH, Manager |
| `engagement.surveys.update` | Editar encuestas | Administrador empresa, RRHH |
| `engagement.surveys.respond` **(+)** | Responder encuestas asignadas | solo sobre lo propio o asignado: todos los roles de organización |
| `engagement.action_plans.view` | Ver planes de acción | Administrador empresa, RRHH, Manager |
| `engagement.action_plans.complete` | Completar planes de acción | Administrador empresa, RRHH, Manager |
| `engagement.action_plans.create` | Crear planes de acción | Administrador empresa, RRHH, Manager |
| `engagement.action_plans.update` | Editar planes de acción | Administrador empresa, RRHH, Manager |
| `engagement.surveys.comments.view` **(S)** **(+)** | Ver comentarios abiertos de encuestas | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Encuesta.** 1) RRHH elige plantilla, población y si es anónima (no se puede cambiar tras lanzar). 2) Lanza. 3) Cada persona responde una vez. 4) Al cerrar se calculan resultados por segmento aplicando el umbral. 5) Se publican a los managers. 6) Se crean planes de acción por área.

**eNPS.** 1) Pregunta de recomendación de 0 a 10. 2) Promotores 9–10, pasivos 7–8, detractores 0–6. 3) eNPS = % promotores − % detractores. 4) Se muestra la tendencia entre mediciones.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Encuestas | Lista con tipo, estado, participación; crear desde plantilla | Prototipo: Experiencia → Encuestas |
| Constructor | Preguntas, secciones, lógica, vista previa | Prototipo: Experiencia → Encuestas |
| Resultados | Participación, puntajes, mapa de calor por área, comentarios, comparación con medición anterior | Prototipo: Experiencia → Encuestas → resultados (Resumen, Mapa de calor, Comentarios) |
| eNPS | Puntaje, distribución, tendencia, por segmento | Prototipo: Experiencia → eNPS |
| Planes de acción | Acciones por área con responsable, fecha, avance | Prototipo: Experiencia → Encuestas |
| Responder encuesta | Formulario móvil con aviso de anonimato | Prototipo: Experiencia → Encuestas |

## 8. Datos principales

Encuestas (`surveys`), preguntas, población invitada, registro de participación (quién respondió, sin enlace a la respuesta), respuestas (sin identificador de persona si es anónima), atributos de segmento copiados a la respuesta, planes de acción.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección ENC.

- **RN-ENC-01**: Si la encuesta es anónima → la respuesta se guarda sin identificador de persona, usuario, IP ni dispositivo; la participación se registra aparte y sin clave que permita unirlas.
- **RN-ENC-02** (parámetro): Si un segmento tiene menos respuestas que el umbral mínimo (parámetro; no puede fijarse por debajo del mínimo de plataforma) → sus resultados no se muestran y se agrupan en el nivel superior.
- **RN-ENC-03**: Si la combinación de filtros permite aislar a una persona por diferencia entre segmentos → el sistema oculta también el segmento complementario.
- **RN-ENC-04**: Si una encuesta ya se lanzó → no se puede cambiar su condición de anónima ni añadir segmentaciones nuevas.
- **RN-ENC-05**: Si una persona ya respondió → no puede responder de nuevo; en modo anónimo se controla con un token de un solo uso sin relación con la respuesta.
- **RN-ENC-06**: Si se calcula eNPS → es porcentaje de promotores (9–10) menos porcentaje de detractores (0–6) sobre respuestas válidas.
- **RN-ENC-07**: Si la IA resume comentarios → trabaja sobre el conjunto que el usuario puede ver y no cita textos que identifiquen al autor en segmentos pequeños.
- **RN-ENC-08**: Si la encuesta es anónima → los comentarios abiertos solo se muestran a quien tenga el permiso y agrupados al nivel que cumple el umbral.
- **RN-ENC-09** (parámetro): Si la participación no alcanza el mínimo definido para dar por válida la medición (parámetro) → se advierte antes de publicar resultados.
- **RN-ENC-10**: Si un plan de acción vence sin avance → se alerta a su responsable y a RRHH.

## 10. Estados

- ME-30 · Encuesta (`05-maquinas-de-estado.md`): estados Borrador, Programada, Abierta, Cerrada, Resultados publicados, Archivada.

## 11. Eventos

**Emite:**

- `survey.launched`, `survey.response_submitted` (sin persona si es anónima), `survey.closed`, `survey.results_published`, `action_plan.created`, `action_plan.overdue`.

**Consume:**

- `onboarding.completed`, `offboarding.started` (encuestas automáticas), `employee.hired`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Área de tres personas: nunca ve resultado propio.
- Persona que cambia de área durante la encuesta: cuenta en el segmento de la fecha de lanzamiento.
- Encuesta de salida con una sola respuesta en el mes: no se muestra individual si es anónima.

## 14. Criterios de aceptación generales

- El anonimato es verificable en el modelo de datos: no existe consulta que una una respuesta anónima con una persona.
- Hay mapa de calor por área, eNPS con tendencia y planes de acción con seguimiento.
