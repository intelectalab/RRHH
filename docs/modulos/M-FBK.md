# M-FBK · Reuniones 1:1 y feedback continuo

Fase 4 · Talento · Épica `FBK` · Reglas `RN-FBK-nn` · Módulo comercial: Desempeño y objetivos (`performance`)

## 1. Objetivo

Sostener la conversación continua entre jefe y colaborador y entre compañeros, con registro y seguimiento.

## 2. Alcance

- Reuniones 1:1 recurrentes: agenda compartida, temas, notas compartidas y notas privadas, compromisos con responsable y fecha.
- Feedback continuo: solicitar, dar (público, privado, solo al jefe), plantillas, vínculo con competencias y objetivos.
- Seguimiento de compromisos; resumen para la evaluación de desempeño.

## 3. Fuera de alcance

- Reconocimientos públicos con insignias (M-REC). Evaluación formal (M-DES).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §41 1:1; §42 Feedback continuo.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Manager y colaborador. Cualquier compañero para feedback.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `performance.one_on_ones.view` | Ver reuniones 1:1 donde participa | solo sobre lo propio o asignado: Administrador empresa, RRHH, Manager, Empleado |
| `performance.one_on_ones.cancel` | Cancelar reuniones 1:1 donde participa | solo sobre lo propio o asignado: Administrador empresa, RRHH, Manager, Empleado |
| `performance.one_on_ones.complete` | Completar reuniones 1:1 donde participa | solo sobre lo propio o asignado: Administrador empresa, RRHH, Manager, Empleado |
| `performance.one_on_ones.create` | Crear reuniones 1:1 donde participa | solo sobre lo propio o asignado: Administrador empresa, RRHH, Manager, Empleado |
| `performance.one_on_ones.update` | Editar reuniones 1:1 donde participa | solo sobre lo propio o asignado: Administrador empresa, RRHH, Manager, Empleado |
| `performance.feedback.view` | Ver feedback recibido y dado | solo sobre lo propio o asignado: todos los roles de organización |
| `performance.feedback.create` | Crear feedback recibido y dado | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Empleado, SST, Contador |
| `performance.feedback.view_team` **(+)** | Ver el feedback recibido por su equipo según visibilidad | Administrador empresa, RRHH, Manager |
| `performance.feedback.view_usage` **(+)** | Ver indicadores de uso sin contenido | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**1:1.** 1) El jefe crea la serie. 2) Ambos agregan temas antes de la reunión. 3) Durante la reunión se toman notas y compromisos. 4) Los compromisos pendientes aparecen en la siguiente reunión.

**Feedback.** 1) Una persona solicita feedback a otra sobre un tema. 2) La otra responde eligiendo visibilidad. 3) Queda en el historial del receptor y disponible como insumo en su evaluación.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Reuniones 1:1 | Series, próxima reunión, agenda, notas, compromisos | Prototipo: Talento → 1:1 y feedback → pestaña Reuniones 1:1 |
| Feedback | Recibido, dado y solicitudes; dar feedback | Prototipo: Talento → 1:1 y feedback → pestaña Feedback |

## 8. Datos principales

Series y reuniones 1:1, temas, notas (compartidas y privadas), compromisos, feedback y solicitudes.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección FBK.

- **RN-FBK-01**: Si una nota es privada → solo la ve su autor; ni RRHH ni administradores acceden a su contenido desde la aplicación.
- **RN-FBK-02**: Si el feedback es «privado» → lo ven emisor y receptor; si es «con jefe» → además el jefe vigente del receptor.
- **RN-FBK-03**: Si un compromiso queda pendiente → se arrastra a la agenda de la siguiente reunión de la serie.
- **RN-FBK-04**: Si cambia el jefe → la serie 1:1 se cierra, el nuevo jefe no hereda las notas compartidas anteriores salvo que el colaborador las comparta.
- **RN-FBK-05**: Si RRHH consulta indicadores → ve frecuencia y cumplimiento, nunca el contenido.
- **RN-FBK-06** (parámetro): Si no se realiza un 1:1 dentro de la frecuencia definida (parámetro) → se recuerda a ambos.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `one_on_one.held`, `one_on_one.commitment_created`, `feedback.requested`, `feedback.given`.

**Consume:**

- `employment.manager_changed`, `review_cycle.launched`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Feedback hacia alguien que cesó.
- Contenido que revela una posible denuncia: se ofrece el canal correspondiente; no se deriva de forma automática.

## 14. Criterios de aceptación generales

- Las reuniones 1:1 tienen agenda, temas, notas, compromisos y seguimiento, con información privada cuando corresponde, y el feedback se integra a desempeño.
