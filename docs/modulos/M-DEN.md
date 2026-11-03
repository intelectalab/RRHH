# M-DEN · Canal de denuncias y hostigamiento

Fase 7 · Operación y cumplimiento · Épica `DEN` · Reglas `RN-DEN-nn` · Módulo comercial: Denuncias (`grievances`)

## 1. Objetivo

Ofrecer un canal independiente y confidencial para denunciar, con anonimato real, acceso muy restringido y el procedimiento de hostigamiento sexual con sus plazos.

## 2. Alcance

- Denuncia identificada o anónima desde un portal sin inicio de sesión; categorías (hostigamiento sexual, acoso laboral, discriminación, fraude, seguridad, otros).
- Código seguro de seguimiento y clave; comunicación bidireccional con el denunciante; evidencias.
- Asignación a investigadores autorizados; conflicto de interés; bitácora del caso.
- Procedimiento de hostigamiento sexual: comité de intervención o delegado, medidas de protección, etapas y plazos, informe, decisión, comunicación.
- Medidas de protección y no represalia; cierre con resultado.
- Estadísticas sin datos identificables; capacitación obligatoria de prevención (con M-LMS).

## 3. Fuera de alcance

- La sanción (M-DIS la ejecuta). Asesoría legal externa.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §58 Canal de denuncias.

**Correcciones que incorpora:** C-06 Anonimato real.

**Ampliaciones que incorpora:** E-15 Hostigamiento sexual (Ley 27942).

## 5. Actores y permisos

- Denunciante (cualquier persona, incluso externa). Investigador de denuncias. Comité de intervención. Oficial de cumplimiento. Nadie más.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `grievances.cases.view` **(S)** | Ver denuncias | solo sobre lo propio o asignado: Investigador de denuncias |
| `grievances.cases.add_protection_measure` **(S)** | Dictar medidas de protección en denuncias | solo sobre lo propio o asignado: Investigador de denuncias |
| `grievances.cases.admit` **(S)** | Admitir denuncias | Investigador de denuncias |
| `grievances.cases.assign` **(S)** | Asignar denuncias | Investigador de denuncias |
| `grievances.cases.close` **(S)** | Cerrar denuncias | Investigador de denuncias |
| `grievances.cases.decide` **(S)** | Decidir denuncias | solo sobre lo propio o asignado: Investigador de denuncias |
| `grievances.cases.dismiss` **(S)** | Descartar denuncias | Investigador de denuncias |
| `grievances.cases.issue_report` **(S)** | Emitir el informe de denuncias | solo sobre lo propio o asignado: Investigador de denuncias |
| `grievances.cases.offer_care` **(S)** | Ofrecer atención médica y psicológica en denuncias | solo sobre lo propio o asignado: Investigador de denuncias |
| `grievances.cases.refer_to_committee` **(S)** | Trasladar al comité denuncias | solo sobre lo propio o asignado: Investigador de denuncias |
| `grievances.cases.resolve` **(S)** | Resolver denuncias | Investigador de denuncias |
| `grievances.cases.start_investigation` **(S)** | Iniciar la investigación de denuncias | solo sobre lo propio o asignado: Investigador de denuncias |
| `grievances.cases.investigate` **(S)** **(+)** | Registrar actuaciones, medidas y conclusiones | solo sobre lo propio o asignado: Investigador de denuncias |
| `grievances.harassment_committees.view` | Ver comités de intervención frente al hostigamiento sexual y delegados | Administrador empresa, RRHH, Auditor, Investigador de denuncias |
| `grievances.harassment_committees.create` | Crear comités de intervención frente al hostigamiento sexual y delegados | Administrador empresa, RRHH |
| `grievances.harassment_committees.update` | Editar comités de intervención frente al hostigamiento sexual y delegados | Administrador empresa, RRHH |
| `grievances.statistics.view` **(+)** | Ver estadísticas agregadas sin datos identificables | Administrador empresa, RRHH, Auditor, Investigador de denuncias |
| `grievances.settings.update` **(+)** | Configurar categorías e investigadores | Administrador empresa |
| `grievances.settings.manage_committee` **(+)** | Administrar el comité de intervención y su vigencia | Administrador empresa, RRHH |
| `grievances.cases.identity.view` **(S)** **(+)** | Ver la identidad del denunciante identificado y de las personas mencionadas | solo sobre lo propio o asignado: Investigador de denuncias |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Denuncia.** 1) El denunciante abre el portal, elige categoría y modo. 2) Describe los hechos y adjunta evidencia (se limpian metadatos). 3) Recibe código y define una clave. 4) El caso llega a los receptores autorizados. 5) Se clasifica y se asigna, excluyendo a personas mencionadas. 6) Se investiga con actuaciones registradas. 7) Se concluye y se cierra con resultado. 8) El denunciante consulta avances y responde preguntas con su código.

**Hostigamiento sexual.** 1) Recibida la queja (o iniciada de oficio), se ofrece atención médica y psicológica (1 día hábil) y se traslada al comité o delegado (1 día hábil). 2) Se dictan y ejecutan las medidas de protección (3 días hábiles). 3) Se comunica la queja y las medidas a la autoridad de trabajo (6 días hábiles). 4) El comité investiga, recibe descargos y emite su informe (15 días calendario desde la recepción). 5) Recursos Humanos traslada el informe, recibe alegatos y decide (10 días calendario). 6) Comunica la decisión a la autoridad (6 días hábiles) y cierra. Máquina ME-48; plazos y norma en `18-localizacion-peru-detalle-tecnico.md` §5.2.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Portal de denuncias | Formulario público, modo anónimo o identificado, adjuntos, entrega de código | Prototipo: Cumplimiento → Canal de denuncias |
| Seguimiento con código | Estado, mensajes con el investigador, aportar evidencia | Prototipo: Cumplimiento → Canal de denuncias |
| Bandeja de casos | Casos asignados con categoría, estado, plazos | Prototipo: Cumplimiento → Casos de denuncia |
| Expediente | Hechos, personas mencionadas, evidencias, actuaciones, medidas de protección, plazos, conclusión | Prototipo: Cumplimiento → Casos de denuncia |
| Estadísticas | Casos por categoría, estado, tiempos, sin datos identificables | Prototipo: Cumplimiento → Casos de denuncia |
| Comité y configuración | Miembros, vigencia, investigadores, categorías | Prototipo: Cumplimiento → Casos de denuncia |
| Procedimiento de hostigamiento | Línea de tiempo con los siete plazos, su vencimiento y cumplimiento; etapa; medidas; comunicaciones a la autoridad con su cargo; libro del caso | Prototipo: Cumplimiento → Casos de denuncia |
| Comité de intervención | Miembros, paridad, vigencia, capacitación y evaluación anual | Prototipo: Cumplimiento → Casos de denuncia |

## 8. Datos principales

Denuncias (`complaints`), mensajes, evidencias, actuaciones, medidas de protección, asignaciones, comité de intervención, códigos de seguimiento (solo huella). Almacenamiento y cifrado separados del resto.  Etapa del procedimiento de hostigamiento (`complaints.procedure_stage`), plazos en curso (`compliance_deadlines`), comunicaciones a la autoridad (`authority_communications`) y asientos del libro inalterable del caso.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección DEN.

- **RN-DEN-01**: Si la denuncia es anónima → no se guarda IP, usuario, dispositivo ni sesión; la bitácora técnica del portal de denuncias excluye esos datos.
- **RN-DEN-02**: Si se entrega el código de seguimiento → el sistema guarda solo su huella; no se puede recuperar, y sin código y clave nadie accede al seguimiento.
- **RN-DEN-03**: Si un usuario no es receptor o investigador asignado → no ve la existencia del caso, ni en búsquedas, reportes, auditoría general ni notificaciones.
- **RN-DEN-04**: Si una persona es mencionada en la denuncia (denunciado o testigo) → no puede ser asignada como investigadora ni ver el caso, aunque tenga el rol.
- **RN-DEN-05**: Si el Administrador empresa revisa la auditoría → ve que hubo actividad en el canal, sin contenido ni identidades.
- **RN-DEN-06** (parámetro): Si la queja es por hostigamiento sexual → las medidas de protección deben dictarse y ejecutarse dentro de 3 días hábiles desde la recepción (`host_medidas_proteccion_dias_habiles`), y la atención médica y psicológica debe ofrecerse dentro de 1 día hábil (`host_atencion_dias_habiles`); el caso crea ambos plazos con alerta.
- **RN-DEN-07** (parámetro): Si la queja es por hostigamiento sexual → cada etapa lleva su plazo como parámetro: traslado al comité 1 día hábil, informe del comité 15 días calendario desde la recepción, decisión 10 días calendario desde el informe, y comunicación a la autoridad de trabajo 6 días hábiles tras la recepción y 6 tras la decisión; no existe un parámetro de duración total, y el expediente alerta antes de cada vencimiento.
- **RN-DEN-08** (parámetro): Si el centro de trabajo tiene 20 o más trabajadores → cumplimiento exige comité de intervención vigente (dos representantes del empleador, uno de ellos de Recursos Humanos, y dos de los trabajadores, con paridad de género); con menos de 20, un delegado.
- **RN-DEN-09**: Si se adjunta evidencia → se eliminan metadatos del archivo antes de guardarlo.
- **RN-DEN-10**: Si un caso se cierra → requiere resultado, fundamento y medidas; el denunciante lo ve con su código.
- **RN-DEN-11**: Si se generan estadísticas → categorías con menos casos que el mínimo se agrupan para no identificar.
- **RN-DEN-12**: Si la denuncia llega por otro canal (mesa de ayuda, jefe) → se registra en el canal a nombre del receptor como «recibida por tercero», con consentimiento.
- **RN-DEN-13** (parámetro): Si pasa el plazo de retención del caso cerrado (parámetro por jurisdicción) → se anonimiza conservando estadística.
- **RN-DEN-14**: Si se registra un acto del procedimiento de hostigamiento (recepción, atención, medida, traslado, informe, decisión, comunicación) → se asienta en el libro inalterable del caso, encadenado por huella, sin identidad del denunciante ni hora (solo fecha).
- **RN-DEN-15**: Si un miembro del comité o el receptor es mencionado en la queja → no puede intervenir: se llama al suplente antes de trasladar el caso.
- **RN-DEN-16** (parámetro): Si vence un plazo del procedimiento sin el acto correspondiente → el plazo queda «cumplido tarde» o «vencido» con los días de atraso, se alerta al titular de Recursos Humanos y se asienta en el libro.
- **RN-DEN-17** (parámetro): Si la empresa no es microempresa acreditada → se exige una capacitación anual especializada para Recursos Humanos y el comité, además de la capacitación al inicio de la relación laboral y la evaluación anual.

## 10. Estados

- ME-37 · Denuncia (`05-maquinas-de-estado.md`): estados Recibida, Admitida, En investigación, Con informe, Resuelta, Cerrada, No admitida.
- ME-48 · Procedimiento de hostigamiento sexual (`05-maquinas-de-estado.md`): estados Recibida, Trasladada al comité, En investigación, Informe emitido, Decidida, Cerrada.

## 11. Eventos

**Emite:**

- `grievance.received` (sin datos), `grievance.deadline_approaching` (solo a asignados), `grievance.closed` (sin datos).

**Consume:**

- Ninguno de otros módulos (aislamiento deliberado).

## 12. Integraciones

Ninguna integración saliente con contenido del caso.

## 13. Casos borde

- Denuncia contra un investigador o contra el administrador.
- Denunciante que pierde su código.
- Denuncia de un tercero externo (proveedor, postulante).
- Denuncia manifiestamente ajena al canal: se cierra orientando al canal correcto.

## 14. Criterios de aceptación generales

- Una prueba automática confirma que ningún rol distinto de los asignados obtiene datos de un caso por ninguna ruta.
- La denuncia anónima no deja rastro identificable en base de datos, logs ni auditoría.
- El expediente de hostigamiento controla medidas de protección y plazos.
