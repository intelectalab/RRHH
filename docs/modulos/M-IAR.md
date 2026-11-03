# M-IAR · Asistente de IA y gobierno

Fase 8 · Inteligencia · Épica `IAR` · Reglas `RN-IAR-nn` · Módulo comercial: Asistente de IA (`ai`)

## 1. Objetivo

Ofrecer una capa de IA opcional que ahorra trabajo (redactar, resumir, consultar, detectar) bajo gobierno estricto: respeta permisos, deja registro y nunca decide sobre personas.

## 2. Alcance

- Casos de uso: redactar descripción de puesto, resumir CV, preparar preguntas de entrevista, resumir encuestas, borradores de comunicados, explicar métricas, consultar información en lenguaje natural, generar reportes, detectar anomalías operativas, responder consultas con la base de conocimiento.
- Asistente conversacional que ejecuta herramientas con los permisos del usuario.
- Registro de cada uso: quién, cuándo, caso de uso, datos enviados, respuesta, herramienta usada.
- Configuración por organización: activar por caso de uso, proveedor, retención del registro, datos excluidos.
- Evaluación de impacto para perfilado; revisión de sesgo en usos de selección.

## 3. Fuera de alcance

- Decisiones automáticas de alto impacto (prohibidas). Entrenamiento de modelos con datos de clientes (no se hace sin acuerdo expreso).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §69 IA para RRHH.

**Correcciones que incorpora:** C-08 IA con gobierno.

**Ampliaciones que incorpora:** E-26 Riesgo de rotación explicable.

## 5. Actores y permisos

- Usuarios con permiso por caso de uso. Administrador gobierna. Auditor revisa el registro.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `ai.conversations.view` | Ver conversaciones con el asistente | solo sobre lo propio o asignado: Administrador empresa, RRHH, Payroll, Recruiter, Manager, SST |
| `ai.conversations.create` | Crear conversaciones con el asistente | Administrador empresa, RRHH, Payroll, Recruiter, Manager, SST |
| `ai.conversations.send_message` | Enviar mensajes en conversaciones con el asistente | Administrador empresa, RRHH, Payroll, Recruiter, Manager, SST |
| `ai.conversations.query_data` **(+)** | Consultar datos en lenguaje natural dentro de sus permisos | Administrador empresa, RRHH, Payroll, Manager |
| `ai.mcp.use` | Usar asistentes externos por MCP | Administrador empresa, RRHH, Payroll, Manager |
| `ai.usage_logs.view` | Ver el registro de usos de IA | Administrador empresa, Auditor |
| `ai.policies.update` **(+)** | Activar casos de uso, proveedor y exclusiones | Administrador empresa |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Consulta en lenguaje natural.** 1) El usuario pregunta «¿Cuántas personas ingresaron este trimestre?». 2) El asistente traduce a una consulta sobre fuentes permitidas. 3) La consulta se ejecuta con los permisos y el alcance del usuario. 4) Responde con el dato, la definición usada y el enlace al detalle. 5) Queda registrado.

**Borrador.** 1) El usuario pide un texto (descripción de puesto, comunicado). 2) La IA lo genera con el contexto permitido. 3) El usuario edita y decide publicar. 4) El contenido publicado lleva al usuario como autor.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Asistente | Conversación con respuestas citadas, acciones sugeridas que el usuario confirma | Prototipo: Analytics → Asistente → pestañas Conversar y Redacción |
| Gobierno de IA | Casos de uso activos, proveedor, exclusiones, retención | Prototipo: Analytics → Asistente → pestaña Política de IA |
| Registro de IA | Usos con usuario, caso, datos consultados y respuesta | Prototipo: Analytics → Asistente → pestaña Bitácora de uso |

## 8. Datos principales

Configuración de IA por organización, registro de usos (`ai_interactions`), evaluaciones de impacto enlazadas, plantillas de instrucciones por caso de uso.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección IAR.

- **RN-IAR-01**: Si la IA accede a datos → lo hace con las mismas comprobaciones de permiso, alcance y campo que el usuario que la invoca; no existe un acceso privilegiado de la IA.
- **RN-IAR-02**: Si se usa la IA → se registra usuario, momento, caso de uso, fuentes consultadas y respuesta.
- **RN-IAR-03**: Si una acción implica contratar, cesar, sancionar, promover o cambiar un sueldo → la IA no puede ejecutarla ni dejarla aprobada; solo prepara información para que una persona decida en el flujo normal.
- **RN-IAR-04**: Si la IA sugiere, ordena o puntúa → muestra los criterios usados; sin explicación no se muestra el resultado.
- **RN-IAR-05**: Si la organización no activó un caso de uso → la función no aparece.
- **RN-IAR-06**: Si el caso de uso implica perfilar personas (riesgo de rotación, orden de candidatos) → requiere evaluación de impacto aprobada y se rotula como estimación revisable.
- **RN-IAR-07**: Si los datos pertenecen a denuncias, salud o casos disciplinarios → nunca se envían al proveedor de IA.
- **RN-IAR-08** (parámetro): Si el registro de usos supera su plazo de retención (parámetro) → se purga conservando metadatos.
- **RN-IAR-09**: Si la IA ejecuta una acción con efecto (crear solicitud, borrador) → pide confirmación explícita al usuario y la acción queda auditada con origen «IA» y el usuario responsable.
- **RN-IAR-10**: Si la respuesta proviene de documentos → cita la fuente; si no hay fuente, lo dice.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `ai.interaction_logged`, `ai.action_confirmed`.

**Consume:**

- Ninguno (opera a demanda).

## 12. Integraciones

Proveedor de modelos por adaptador con implementación falsa; servidor MCP (M-API).

## 13. Casos borde

- Usuario que pide datos fuera de su alcance: respuesta «no tienes acceso» sin revelar existencia.
- Texto generado con datos personales de terceros.
- Proveedor no disponible: las funciones degradan sin bloquear el flujo manual.

## 14. Criterios de aceptación generales

- Los nueve casos de uso del pedido funcionan con registro, y una prueba automática confirma que la IA no devuelve datos fuera de los permisos del usuario.
