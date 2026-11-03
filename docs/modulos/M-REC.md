# M-REC · Reconocimientos

Fase 6 · Experiencia · Épica `REC` · Reglas `RN-REC-nn` · Módulo comercial: Clima y encuestas (`engagement`)

## 1. Objetivo

Hacer visible el buen trabajo entre compañeros, ligado a los valores de la empresa.

## 2. Alcance

- Reconocer a un compañero o equipo con valor empresarial, mensaje e insignia.
- Muro opcional con reacciones y comentarios; reconocimientos privados.
- Catálogo de valores e insignias; programas (colaborador del mes), puntos opcionales y canje.
- Indicadores por valor, área y persona.

## 3. Fuera de alcance

- Bonos monetarios (M-COM). Feedback privado (M-FBK).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §42 Feedback continuo; §51 Reconocimientos.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Todos los colaboradores. RRHH modera y configura.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `engagement.recognitions.view` | Ver reconocimientos | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Empleado, SST, Contador |
| `engagement.recognitions.create` | Crear reconocimientos | Administrador empresa, RRHH, Payroll, Recruiter, Manager, Empleado, SST, Contador |
| `engagement.recognitions.hide` | Ocultar reconocimientos | Administrador empresa, RRHH |
| `engagement.recognitions.configure` **(+)** | Configurar valores, insignias, programas y puntos | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Reconocer.** 1) Se elige a la persona o equipo. 2) Se elige valor e insignia y se escribe el mensaje. 3) Se publica en el muro o se envía en privado. 4) El reconocido y su jefe reciben aviso; queda en su ficha.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Reconocimientos | Muro con filtros por valor y área; reconocer | Prototipo: Experiencia → Reconocimientos |
| Programas e insignias | Valores, insignias, reglas de puntos | Prototipo: Experiencia → Reconocimientos |
| Reconocimientos del colaborador | Recibidos y dados | Prototipo: Personas → Colaboradores → Ficha → pestaña Reconocimientos |

## 8. Datos principales

Reconocimientos (`recognitions`), destinatarios, valores, insignias, reacciones, comentarios, puntos y canjes.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección REC.

- **RN-REC-01**: Si una persona intenta reconocerse a sí misma → se rechaza.
- **RN-REC-02** (parámetro): Si el programa usa puntos → cada persona tiene un cupo por periodo para dar (parámetro) y no puede excederlo.
- **RN-REC-03**: Si un reconocimiento se oculta por moderación → deja de verse en el muro, se conserva con el motivo y se notifica al autor.
- **RN-REC-04**: Si el muro está desactivado para la organización → los reconocimientos son visibles solo para emisor, receptor y jefe.
- **RN-REC-05**: Si el receptor cesa → sus reconocimientos se conservan en el historial y dejan de mostrarse en el muro activo.
- **RN-REC-06** (parámetro): Si los puntos tienen valor canjeable → su tratamiento en planilla (remunerativo o no) se define por parámetro y concepto antes de activar el canje.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `recognition.given`, `recognition.hidden`, `points.redeemed`.

**Consume:**

- `employee.terminated`, `review.published` (sugerencia de reconocer).

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Reconocimiento a un equipo completo.
- Mensaje con contenido inapropiado: denuncia desde el muro hacia moderación.

## 14. Criterios de aceptación generales

- Un colaborador reconoce a otro con valor, mensaje e insignia y aparece en el muro opcional.
