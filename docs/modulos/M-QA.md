# M-QA · Calidad, seguridad y salida a producción

Fase Transversal · Épica `QA` · Reglas `RN-QA-nn` · Módulo comercial: transversal

## 1. Objetivo

Asegurar que cada fase entregue software correcto, seguro y utilizable, con criterios verificables antes de pasar a la siguiente.

## 2. Alcance

- Definición de terminado por épica: entidades, relaciones, permisos, servicios, backend, frontend, datos reales, CRUD, permisos, integridad, responsive, corrección de errores.
- Pruebas automáticas: unidad, integración, contrato de API, aislamiento entre organizaciones, permisos por ruta, máquinas de estado, regresión de cálculo de planilla con casos de referencia, accesibilidad, rendimiento.
- Revisión de «ningún botón falso» y de persistencia de extremo a extremo.
- Seguridad: revisión de dependencias, pruebas de intrusión, gestión de secretos, cifrado, copias y restauración probadas.
- Organización demo coherente como banco de pruebas del ciclo completo.
- Si existe código previo: inventario (funciona, mejorable, parcial, no existe, duplicado, obsoleto) antes de reemplazar.
- Lista de salida a producción y validación legal del paquete de país.

## 3. Fuera de alcance

- Estimaciones y calendario (los define el área de desarrollo).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §101 Performance; §102 Arquitectura de datos; §113 Demo; §114 No crear botones falsos; §115 Persistencia; §120 Estrategia de implementación; §121 Proceso de desarrollo; §122 Si ya existe código; §123 Continuidad automática; §124 Objetivo final.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de SPEC.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Equipo de desarrollo, responsable de calidad, responsable de seguridad, especialista laboral (valida el paquete de país).

Este módulo no define permisos propios: aplica los de los recursos que muestra (`07-permisos-y-roles.md`).

## 6. Flujos principales

**Cierre de una épica.** 1) Se cumplen los criterios de aceptación del módulo. 2) Pasan las pruebas automáticas transversales. 3) Se recorre el flujo en la organización demo en escritorio y móvil. 4) Se revisan permisos y auditoría. 5) Se documentan decisiones.

**Continuidad.** El equipo (y los agentes de desarrollo) avanzan al siguiente paso lógico sin pedir confirmación en cada cambio menor; se detienen solo ante decisiones críticas no determinables técnicamente, que se registran como decisión pendiente.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Lista de salida a producción | Comprobaciones por área con evidencia | Prototipo: no aplica (documento del kit) |
| Organización demo | Empresa con gerencia general, RRHH, ventas, tecnología y operaciones, y datos enlazados | Prototipo: Ingreso (selector de organización y de usuario demo) |

## 8. Datos principales

Casos de referencia de cálculo, semilla demo, resultados de pruebas, registro de decisiones.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección QA.

- **RN-QA-01**: Si un endpoint no tiene prueba de aislamiento y de permiso → la integración continua falla.
- **RN-QA-02**: Si una pantalla contiene un control sin operación real → la épica no se da por terminada.
- **RN-QA-03**: Si una acción no persiste o no actualiza las vistas relacionadas (saldo, calendario, tablero, actividad) → es un defecto bloqueante.
- **RN-QA-04**: Si el paquete de país cambia → se ejecutan los casos de referencia de cálculo y cualquier diferencia no esperada bloquea la publicación.
- **RN-QA-05**: Si existe código previo que funciona y aporta valor → se conserva o se refactoriza; solo se elimina con justificación técnica registrada.
- **RN-QA-06**: Si una decisión es crítica y no determinable técnicamente → se detiene ese punto y se consulta; en lo demás se continúa sin pedir confirmación.
- **RN-QA-07**: Si los datos demo no permiten recorrer el ciclo completo de vacante a offboarding → la semilla se considera incompleta.
- **RN-QA-08**: Si un parámetro legal del paquete sigue «a confirmar» → la salida a producción de nómina para ese cálculo queda bloqueada hasta la validación del especialista.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

Ninguno propio.

**Consume:**

Ninguno.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Fase entregada con errores críticos pendientes: no se avanza.
- Diferencia de céntimos por redondeo frente al caso de referencia.

## 14. Criterios de aceptación generales

- Cada fase termina con sus épicas aceptadas, sin errores críticos pendientes y con la organización demo recorriendo el flujo completo.
