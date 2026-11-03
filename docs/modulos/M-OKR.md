# M-OKR · Objetivos

Fase 4 · Talento · Épica `OKR` · Reglas `RN-OKR-nn` · Módulo comercial: Desempeño y objetivos (`performance`)

## 1. Objetivo

Alinear objetivos corporativos, de área e individuales con resultados clave medibles y seguimiento continuo.

## 2. Alcance

- Ciclos (anual, trimestral); objetivos de empresa, unidad y persona; resultados clave con métrica, valor inicial, meta y avance.
- Alineación jerárquica y árbol de objetivos; pesos; visibilidad pública o privada.
- Registro de avance con comentario y nivel de confianza; cierre con calificación.
- Aprobación de objetivos por el jefe; enlace con desempeño y con bonos por resultados.

## 3. Fuera de alcance

- Cálculo del bono (M-COM, M-CPT). Evaluación (M-DES).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §40 Objetivos / OKR.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Dirección define corporativos. Managers definen y aprueban. Colaboradores proponen y actualizan.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `performance.objectives.view` | Ver objetivos y resultados clave | Administrador empresa, RRHH, Manager, Empleado, Auditor |
| `performance.objectives.activate` | Activar objetivos y resultados clave | Administrador empresa, RRHH, Manager |
| `performance.objectives.check_in` | Registrar avance en objetivos y resultados clave | Administrador empresa, RRHH, Manager; solo sobre lo propio o asignado: Empleado |
| `performance.objectives.close` | Cerrar objetivos y resultados clave | Administrador empresa, RRHH, Manager; solo sobre lo propio o asignado: Empleado |
| `performance.objectives.create` | Crear objetivos y resultados clave | Administrador empresa, RRHH, Manager; solo sobre lo propio o asignado: Empleado |
| `performance.objectives.update` | Editar objetivos y resultados clave | Administrador empresa, RRHH, Manager; solo sobre lo propio o asignado: Empleado |
| `performance.objectives.manage_company` **(+)** | Administrar objetivos de empresa y de unidad y abrir o cerrar ciclos | Administrador empresa, RRHH |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Ciclo.** 1) RRHH abre el ciclo. 2) Se publican objetivos corporativos. 3) Las unidades y personas definen los suyos alineados. 4) El jefe aprueba. 5) Se actualiza el avance con la frecuencia definida. 6) Al cierre se califica y se archiva.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Objetivos | Árbol o lista por nivel con avance y estado; crear, alinear | Prototipo: Talento → Objetivos |
| Detalle del objetivo | Resultados clave, avance, historial de actualizaciones, comentarios | Prototipo: Talento → Objetivos |
| Mis objetivos | Objetivos propios con actualización rápida | Prototipo: Talento → Objetivos |

## 8. Datos principales

Ciclos, objetivos (`goals`), resultados clave (`key_results`), actualizaciones, alineaciones.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección OKR.

- **RN-OKR-01**: Si se calcula el avance de un objetivo → es el promedio ponderado del avance de sus resultados clave.
- **RN-OKR-02**: Si un resultado clave tiene valor inicial, meta y valor actual → su avance es (actual − inicial) / (meta − inicial), acotado entre 0 y el máximo permitido.
- **RN-OKR-03** (parámetro): Si la suma de pesos de los objetivos de una persona en el ciclo no es 100 (cuando la política usa pesos) → no puede enviarlos a aprobación.
- **RN-OKR-04**: Si un objetivo está aprobado → cambiar su meta o peso exige nueva aprobación y queda historial.
- **RN-OKR-05**: Si el ciclo está cerrado → no se registran avances.
- **RN-OKR-06** (parámetro): Si un objetivo no recibe actualización en el intervalo definido (parámetro) → se recuerda al dueño y se marca «sin actualizar».
- **RN-OKR-07**: Si el objetivo es privado → solo lo ven su dueño, su línea de jefes y RRHH.
- **RN-OKR-08**: Si la persona cambia de unidad a mitad de ciclo → conserva sus objetivos; el nuevo jefe decide cerrarlos, ajustarlos o mantenerlos.

## 10. Estados

- ME-27 · Objetivo (`05-maquinas-de-estado.md`): estados Borrador, En aprobación, Activo, En riesgo, Cerrado, Cancelado.

## 11. Eventos

**Emite:**

- `objective.created`, `objective.approved`, `objective.updated`, `objective.at_risk`, `objective.closed`.

**Consume:**

- `employment.manager_changed`, `employee.terminated`, `review_cycle.launched`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Resultado clave decreciente (reducir un valor).
- Objetivo compartido por varias personas.
- Meta modificada tras superarse.

## 14. Criterios de aceptación generales

- Los objetivos corporativos, de área e individuales se relacionan jerárquicamente y muestran avance por resultados clave.
