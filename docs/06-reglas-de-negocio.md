# 06 · Reglas de negocio

Ayni HR · kit de desarrollo. Reglas numeradas `RN-<ÉPICA>-nn` con los códigos de épica de `SPEC.md`.

## Convenciones

- Cada regla es verificable: **condición → resultado**. Debe existir al menos una prueba automática por regla, nombrada con su código.
- Columna «¿Parámetro?»: **Sí** = el valor (tasa, tope, plazo, umbral, política) se lee de un parámetro con vigencia por fecha y por nivel (país/régimen en `M-PAR`, organización/empresa/sede/grupo en `M-CFG`); nunca es una constante en código. **No** = comportamiento fijo del producto.
- «Valor de referencia 2026, parámetro con vigencia» = dato tomado de `docs/research.md`, cargado como valor inicial del paquete Perú. «A confirmar con la norma vigente» = no verificado; figura en la lista de puntos a validar con un especialista laboral (`14-localizacion-peru.md`, sección final) y bloquea el cierre de planilla que lo use hasta confirmarse (RN-PAR-03).
- Las reglas se validan siempre en el servidor (regla transversal 8). La interfaz puede anticiparlas, no sustituirlas.
- Las reglas transversales de `SPEC.md` (aislamiento, vigencias, acciones de estado, auditoría, datos sensibles, adaptadores, IA asistida) se concretan en las épicas PLT, TEN, SEG, AUD, HIS, INT e IAR y aplican a todos los módulos aunque no se repitan en cada uno.
- Correcciones C-xx y ampliaciones E-xx: la correspondencia regla ↔ corrección/ampliación está en `00-trazabilidad.md`.
- Reglas de la serie `RN-PE-…` (quinta categoría `RN-PE-QTA`, micro y pequeña empresa `RN-PE-MYP`, construcción civil `RN-PE-CC`, agrario `RN-PE-AGR`): son reglas del paquete Perú y están definidas, con su estado de verificación, en `18-localizacion-peru-detalle-tecnico.md` (secciones 2.6, 2.7 y 4.2 a 4.4). Las reglas de este documento que las resumen remiten a ellas.

## Fase 0 · Cimientos

### PLT · Plataforma técnica

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-PLT-01 | Si una petición de cambio de estado no corresponde a una transición de la máquina de la entidad → se rechaza con error de validación y no se modifica nada. | No |
| RN-PLT-02 | Si una acción llega dos veces con la misma clave de idempotencia → se devuelve el resultado original y no se repite el efecto. | No |
| RN-PLT-03 | Si un cambio de negocio se confirma → su evento y su auditoría se escriben en la misma transacción; si falla cualquiera, no se confirma ninguno. | No |
| RN-PLT-04 | Si la entrega de un evento a un consumidor falla → se reintenta con espera creciente hasta el número máximo de intentos (parámetro) y luego pasa a «fallido» visible en monitoreo. | Sí |
| RN-PLT-05 | Si un usuario exporta o busca → solo obtiene filas dentro de su alcance y columnas para las que tiene permiso de campo; nunca se confía en lo que la interfaz ocultó. | No |
| RN-PLT-06 | Si una exportación supera el umbral de filas (parámetro) → se ejecuta en segundo plano y se entrega por enlace temporal. | Sí |
| RN-PLT-07 | Si se descarga un archivo → el enlace firmado caduca tras el tiempo configurado (parámetro) y la descarga queda en el registro de accesos cuando el archivo es sensible. | Sí |
| RN-PLT-08 | Si se elimina un registro con historia laboral (relación laboral, boleta, marcación, contrato firmado) → la eliminación se rechaza; solo procede archivar o anular según su máquina de estados. | No |
| RN-PLT-09 | Si se restaura un registro cuya referencia padre sigue eliminada → se exige restaurar primero el padre. | No |
| RN-PLT-10 | Si un archivo subido supera el tamaño máximo o no es de un tipo permitido (parámetros) o no pasa el antivirus → se rechaza con mensaje claro. | Sí |

### TEN · Multiempresa y aislamiento

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-TEN-01 | Si una consulta o escritura no lleva organización activa → la base no devuelve ni modifica filas. | No |
| RN-TEN-02 | Si un registro referencia a otro de distinta organización → la clave foránea compuesta lo impide. | No |
| RN-TEN-03 | Si un endpoint nuevo se publica sin la prueba automática de aislamiento → la integración continua falla. | No |
| RN-TEN-04 | Si un usuario pertenece a varias organizaciones → cada sesión opera sobre una sola; cambiar exige elegir de nuevo y queda en actividad. | No |
| RN-TEN-05 | Si una empresa tiene relaciones laborales, periodos de planilla o documentos → no se elimina; solo se archiva y deja de aceptar altas. | No |
| RN-TEN-06 | Si una persona tiene relaciones laborales en dos empresas del mismo grupo → se ve una sola persona con dos relaciones, cada una con su régimen y planilla. | No |
| RN-TEN-07 | Si la organización alcanza el máximo de empresas de su plan (límite del plan) → no se puede crear otra hasta ampliar el plan. | Sí |

### SEG · Identidad, roles, permisos por campo y alcance

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-SEG-01 | Si se intenta borrar o renombrar un rol de sistema → se rechaza; solo se pueden duplicar. | No |
| RN-SEG-02 | Si un cambio dejara a la organización sin ningún usuario activo con el rol Administrador empresa → se rechaza. | No |
| RN-SEG-03 | Si un usuario intenta concederse permisos a sí mismo o asignar un rol con permisos que él no tiene → se rechaza, salvo que sea Administrador empresa. | No |
| RN-SEG-04 | Si el usuario tiene el permiso de acción pero el registro está fuera de su alcance → la respuesta es «no encontrado». | No |
| RN-SEG-05 | Si el usuario no tiene el permiso de un campo sensible → el campo no viaja en la respuesta de la API (no se enmascara en el cliente) y no puede filtrar, ordenar ni exportar por él. | No |
| RN-SEG-06 | Si un usuario consulta o descarga un dato marcado sensible → se inserta una fila en el registro de accesos con usuario, dato, registro y momento. | No |
| RN-SEG-07 | Si se superan los intentos fallidos permitidos (parámetro) → la cuenta se bloquea por el tiempo configurado y se notifica al titular. | Sí |
| RN-SEG-08 | Si un rol tiene MFA obligatorio (parámetro por rol) → no se completa el ingreso sin segundo factor; Administrador, Payroll e Investigador lo traen activado por defecto. | Sí |
| RN-SEG-09 | Si el colaborador no tiene correo → puede ingresar con documento + clave u OTP; el correo nunca es campo obligatorio de persona ni de usuario. | No |
| RN-SEG-10 | Si la relación laboral de un usuario con rol Empleado termina → su acceso pasa a modo ex-colaborador (solo sus boletas, certificados y liquidación) por el periodo configurado; los demás roles se retiran. | No |
| RN-SEG-11 | Si una delegación vence (fecha fin obligatoria) → las aprobaciones vuelven al titular automáticamente. | Sí |
| RN-SEG-12 | Si un usuario pertenece al rol Manager → su alcance «equipo» se calcula con la línea de reporte vigente (directos e indirectos) a la fecha de consulta. | No |
| RN-SEG-13 | Si una persona es a la vez aprobadora y solicitante de un mismo trámite → no puede aprobarse a sí misma; el paso sube al siguiente nivel. | No |
| RN-SEG-14 | Si la sesión supera el tiempo de inactividad configurado (parámetro) → se cierra y se pide ingresar de nuevo. | Sí |

### SAS · Consola SaaS

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-SAS-01 | Si una organización no tiene contratado un módulo → sus rutas de API responden «módulo no contratado», su menú no lo muestra y sus permisos no surten efecto aunque el rol los tenga. | No |
| RN-SAS-02 | Si el número de colaboradores activos alcanza el límite del plan → se avisa al administrador al llegar al porcentaje de alerta (parámetro) y, al tope, no se permiten nuevas altas (los datos existentes siguen operativos). | Sí |
| RN-SAS-03 | Si el almacenamiento alcanza el límite del plan → se bloquean nuevas subidas y se mantiene la lectura. | Sí |
| RN-SAS-04 | Si vence la prueba gratuita sin conversión → la organización pasa a «Suspendida» tras el periodo de gracia (parámetro). | Sí |
| RN-SAS-05 | Si una organización está suspendida → sus usuarios no pueden ingresar a operar; los colaboradores conservan acceso de solo lectura a sus boletas y certificados. | No |
| RN-SAS-06 | Si se retira un módulo → sus datos no se borran; quedan en solo lectura durante el periodo de transición (parámetro) y luego se ocultan hasta reactivarlo. | No |
| RN-SAS-07 | Si una función está tras un feature flag → se evalúa en el orden organización, plan, grupo beta, país; el primero que la define decide. | No |
| RN-SAS-08 | Si soporte suplanta a un usuario → necesita motivo, la sesión caduca sola y cada acción lleva la marca «soporte» en la auditoría de la organización. | No |
| RN-SAS-09 | Si se pide restaurar una copia → nunca se sobrescribe producción directamente; se restaura en aislado, se verifica y se promueve con doble aprobación. | No |
| RN-SAS-10 | Si se cancela una organización → sus datos se conservan durante el periodo de retención (parámetro por jurisdicción), se ofrece exportación completa y después se eliminan o anonimizan. | Sí |
| RN-SAS-11 | Si un plan tiene organizaciones suscritas → no se elimina; se desactiva para nuevas ventas y las existentes conservan sus condiciones. | No |
| RN-SAS-12 | Si la consola muestra datos de una organización → solo metadatos y consumo; nunca sueldos, fichas ni documentos. | No |

### AUD · Auditoría, actividad y logs

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-AUD-01 | Si se crea, modifica, archiva, restaura o cambia de estado un registro de negocio → se inserta una fila de auditoría con valores anterior y nuevo por campo. | No |
| RN-AUD-02 | Si alguien intenta modificar o borrar una fila de auditoría por la aplicación o la API → no existe operación para hacerlo; la base lo impide por permisos del rol de conexión. | No |
| RN-AUD-03 | Si el campo auditado es sensible → su valor se muestra solo a quien tenga `audit.logs.sensitive_values.view` y además el permiso del campo en su módulo; los demás ven «valor protegido». | No |
| RN-AUD-04 | Si el cambio lo origina una automatización, una importación, soporte o la IA → la fila registra el origen y el usuario responsable de haberla lanzado. | No |
| RN-AUD-05 | Si se registra una denuncia anónima → la auditoría del caso no guarda IP, dispositivo ni usuario del denunciante. | No |
| RN-AUD-06 | Si una fila de auditoría supera el plazo de retención de su jurisdicción (parámetro) → se archiva en frío; no se borra antes del plazo legal del dato que respalda. | Sí |
| RN-AUD-07 | Si actividad, auditoría y logs técnicos se consultan → cada uno vive en almacenamiento y pantalla distintos; los logs técnicos nunca contienen datos personales en claro. | No |

### UI · Sistema de diseño y armazón

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-UI-01 | Si un control visible (crear, editar, eliminar, aprobar, rechazar, descargar, exportar, filtrar, buscar, firmar, duplicar, restaurar) no tiene una operación real detrás → no se publica la pantalla. | No |
| RN-UI-02 | Si el usuario no tiene el permiso o el módulo no está contratado → el control no se muestra y el servidor rechaza la llamada igualmente. | No |
| RN-UI-03 | Si una lista está vacía → se muestra un estado vacío con explicación y la acción para crear el primer registro (si el usuario puede). | No |
| RN-UI-04 | Si una acción cambia datos → al terminar, todas las vistas afectadas (saldo, calendario, tablero, pendientes) reflejan el cambio sin recargar la sesión. | No |
| RN-UI-05 | Si un formulario falla en servidor → cada error se muestra junto a su campo con un mensaje en lenguaje claro. | No |
| RN-UI-06 | Si la pantalla mide menos que el ancho móvil de referencia → las tablas se vuelven tarjetas o se desplazan dentro de su contenedor; nunca se desborda la página. | No |
| RN-UI-07 | Si un texto visible no proviene de los archivos de traducción → la revisión de calidad lo marca como defecto. | No |
| RN-UI-08 | Si una acción masiva afecta a más registros que el umbral seguro (parámetro) o a datos sensibles → pide confirmación con resumen y se ejecuta en segundo plano. | Sí |

### WFL · Motor de aprobaciones y workflows

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-WFL-01 | Si un trámite requiere aprobación → el motor selecciona el flujo publicado más específico (empresa + tipo + condición) y, si no hay ninguno, usa el flujo por defecto del tipo. | No |
| RN-WFL-02 | Si el paso es secuencial → el siguiente nivel no recibe la solicitud hasta que el anterior aprueba. | No |
| RN-WFL-03 | Si el paso es paralelo → se cumple cuando aprueba el mínimo requerido (todos, uno o N; parámetro del paso). | Sí |
| RN-WFL-04 | Si cualquier aprobador rechaza → el trámite termina rechazado y el motivo es obligatorio. | No |
| RN-WFL-05 | Si el aprobador resuelto es el propio solicitante → el paso se salta al siguiente nivel; si no hay siguiente, va a RRHH. | No |
| RN-WFL-06 | Si un paso supera su plazo (parámetro por flujo) → se envía recordatorio y, al segundo vencimiento, se escala según la regla configurada. | Sí |
| RN-WFL-07 | Si el aprobador está ausente (vacaciones aprobadas) o sin usuario activo → la solicitud va a su suplente o delegado; si no hay, a su jefe. | No |
| RN-WFL-08 | Si se publica una nueva versión de un flujo → los trámites en curso continúan con la versión anterior. | No |
| RN-WFL-09 | Si una automatización intenta contratar, cesar, sancionar, promover o cambiar un sueldo sin paso de aprobación humana → no se permite publicarla. | No |
| RN-WFL-10 | Si una acción de automatización falla → se registra el error, se reintenta según política y no se ejecutan las acciones siguientes que dependan de ella. | No |
| RN-WFL-11 | Si una regla se dispara en bucle (más ejecuciones que el máximo por registro y ventana; parámetro) → se pausa y se alerta al administrador. | Sí |
| RN-WFL-12 | Si se aprueba en masa → solo se permite para tipos marcados como seguros y cada aprobación se audita individualmente. | No |
| RN-WFL-13 | Si un trámite se cancela por el solicitante antes de la decisión final → todos los pasos pendientes se anulan y se notifica a los aprobadores. | No |

### NOT · Notificaciones y mensajería

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-NOT-01 | Si un evento genera un aviso → el destinatario se calcula en el momento del envío con los permisos y el alcance vigentes; nadie recibe datos que no puede ver. | No |
| RN-NOT-02 | Si el mensaje va por un canal externo (correo, SMS, WhatsApp) → no incluye montos de sueldo, datos de salud ni contenido de denuncias; solo el aviso y un enlace que exige ingresar. | No |
| RN-NOT-03 | Si el aviso cae en el horario de silencio del usuario (parámetro) y no es urgente → se entrega al terminar el silencio. | Sí |
| RN-NOT-04 | Si se generan varios avisos del mismo tipo en la ventana de agrupación (parámetro) → se envía uno solo con el resumen. | Sí |
| RN-NOT-05 | Si un aviso es de tipo obligatorio → el usuario no puede desactivar el canal en la aplicación. | No |
| RN-NOT-06 | Si la entrega falla → se reintenta según la política del canal y, agotados los intentos, se usa el canal alternativo configurado. | Sí |
| RN-NOT-07 | Si el colaborador no tiene correo → los avisos van por aplicación, push o WhatsApp/SMS; ningún flujo queda bloqueado por falta de correo. | No |
| RN-NOT-08 | Si una persona está con vacaciones o descanso aprobado → los avisos no urgentes de trabajo respetan la desconexión digital y se entregan al regreso. | No |

### CFG · Configuración, parámetros, campos personalizados, i18n y multimoneda

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-CFG-01 | Si un parámetro existe en varios niveles → gana el más específico vigente: grupo, sede, empresa, organización, valor por defecto del paquete de país. | No |
| RN-CFG-02 | Si se cambia un parámetro → se crea un registro con vigencia; el valor anterior no se sobrescribe y los cálculos de fechas pasadas siguen usando el valor de su fecha. | No |
| RN-CFG-03 | Si un parámetro es legal (lo publica el paquete de país) → la organización no puede bajarlo por debajo del mínimo legal; solo mejorarlo cuando la norma lo permite. | No |
| RN-CFG-04 | Si un campo personalizado se marca sensible → hereda las reglas de permiso por campo, cifrado y registro de accesos. | No |
| RN-CFG-05 | Si un campo personalizado tiene valores cargados → no se elimina ni cambia de tipo; se archiva. | No |
| RN-CFG-06 | Si un monto se guarda → lleva siempre su moneda; nunca se suman montos de monedas distintas sin tipo de cambio de la fecha. | No |
| RN-CFG-07 | Si falta el tipo de cambio de la fecha → se usa el último anterior disponible y se marca el resultado como «tipo de cambio referencial» (comportamiento parámetro: bloquear o usar anterior). | Sí |
| RN-CFG-08 | Si una fecha u hora se muestra → usa la zona horaria de la empresa del registro; se guarda en UTC. | No |
| RN-CFG-09 | Si un valor de catálogo está en uso → no se elimina; se archiva y deja de ofrecerse para nuevos registros. | No |

## Fase 1 · Core HR

### ORG · Estructura organizacional

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-ORG-01 | Si una unidad tiene posiciones activas o subunidades → no se archiva hasta moverlas o cerrarlas. | No |
| RN-ORG-02 | Si se mueve una unidad bajo uno de sus propios descendientes → se rechaza (el árbol no admite ciclos). | No |
| RN-ORG-03 | Si cambia el responsable de una unidad o el jefe de una persona → el organigrama, el alcance de managers y los aprobadores se recalculan desde la fecha de vigencia sin intervención manual. | No |
| RN-ORG-04 | Si un puesto de catálogo tiene posiciones o historial → no se elimina; se archiva y no admite nuevas posiciones. | No |
| RN-ORG-05 | Si una sede tiene relaciones laborales activas → no se archiva. | No |
| RN-ORG-06 | Si la distribución de un centro de costo entre varios → los porcentajes deben sumar 100. | No |
| RN-ORG-07 | Si un puesto no tiene categoría laboral asignada → aparece como pendiente en el cuadro de categorías y funciones (M-COM) y en el tablero de cumplimiento. | No |
| RN-ORG-08 | Si el directorio se muestra a un Empleado → solo incluye nombre, puesto, unidad, sede y los datos de contacto laboral que la organización decidió publicar. | No |

### POS · Posiciones y headcount

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-POS-01 | Si la empresa tiene activa la política «contratar solo contra posición» → no se puede registrar un ingreso ni emitir una oferta sin una posición aprobada o vacante. | Sí |
| RN-POS-02 | Si una posición ya tiene un ocupante activo → no admite otro, salvo que esté marcada como compartida con número de cupos. | No |
| RN-POS-03 | Si una posición está congelada o cerrada → no se puede abrir vacante ni asignar ocupante. | No |
| RN-POS-04 | Si el ocupante cesa o se traslada → la posición pasa a «Vacante» en la fecha efectiva. | No |
| RN-POS-05 | Si el costo de una solicitud supera el presupuesto disponible de la unidad → la aprobación exige el nivel adicional configurado (parámetro). | Sí |
| RN-POS-06 | Si se cierra una posición ocupada → se rechaza; primero debe quedar vacante. | No |
| RN-POS-07 | Si se calcula el costo proyectado → usa la compensación vigente del ocupante o el punto medio de la banda si está vacante, más los factores de carga del régimen (parámetros del paquete de país). | No |
| RN-POS-08 | Si un escenario se modifica → no altera posiciones reales hasta convertirlo de forma explícita en solicitudes. | No |

### COL · Colaboradores y ficha 360

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-COL-01 | Si se registra un ingreso con un documento de identidad que ya existe en la organización → no se crea otra persona; se ofrece reingreso o relación adicional. | No |
| RN-COL-02 | Si una persona tiene una relación activa en una empresa → no puede abrirse otra relación activa en la misma empresa con fechas que se solapen. | No |
| RN-COL-03 | Si se cesa una relación → la persona, sus relaciones anteriores y todo su historial se conservan; nunca se borran. | No |
| RN-COL-04 | Si la fecha de ingreso es futura → la relación queda «Por ingresar» y pasa a «Activa» automáticamente ese día. | No |
| RN-COL-05 | Si otro módulo necesita sueldo, jefe, puesto o saldo → lo lee de la relación y su historia vigente; ningún módulo guarda su propia copia editable. | No |
| RN-COL-06 | Si el colaborador propone un cambio en un campo de la lista «requiere aprobación» (parámetro por empresa) → no se aplica hasta que RRHH lo aprueba. | Sí |
| RN-COL-07 | Si se registra un hijo como derechohabiente → se exige sustento y fecha de nacimiento; de ello depende la asignación familiar (M-BSO). | No |
| RN-COL-08 | Si el campo sexo, discapacidad u otro dato sensible se registra → se guarda con finalidad y base legal, y la discapacidad además con consentimiento expreso (M-PRI). | No |
| RN-COL-09 | Si se registra un cese con fecha anterior al último periodo de planilla cerrado → se rechaza o se exige el flujo de ajuste retroactivo (M-PER). | No |
| RN-COL-10 | Si el cese se registra con activos sin devolver, préstamos con saldo o vacaciones aprobadas futuras → se advierte y se crean tareas de offboarding; el bloqueo es configurable. | Sí |
| RN-COL-11 | Si cambia el número de cuenta de pago → requiere permiso de campo, se notifica al titular por un canal distinto y queda auditado. | No |
| RN-COL-12 | Si el usuario no tiene permiso de un campo sensible → la pestaña o el bloque correspondiente no se muestra ni se envía. | No |
| RN-COL-13 | Si el alta supera el límite de colaboradores del plan → se rechaza con mensaje al administrador. | Sí |
| RN-COL-14 | Si la empresa opera en Perú → la ficha incluye los datos del registro laboral (tipo de trabajador, ocupación, situación educativa, indicadores de jornada, tipo de pago, seguros de riesgo) con vigencia, sin duplicar lo que ya vive en persona, relación, contrato o afiliaciones. | No |
| RN-COL-15 | Si faltan datos obligatorios del registro laboral → la contratación puede guardarse, pero el alta queda bloqueada con la lista de faltantes. | No |

### HIS · Historia con vigencia

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-HIS-01 | Si se registra un cambio en una dimensión con vigencia → se inserta un registro nuevo y se cierra el anterior el día previo; nunca se actualiza el valor en sitio. | No |
| RN-HIS-02 | Si dos registros de la misma dimensión y relación se solaparan en fechas → la base lo impide. | No |
| RN-HIS-03 | Si entre dos registros de una dimensión obligatoria quedara un hueco de fechas → se rechaza. | No |
| RN-HIS-04 | Si la fecha de vigencia es futura → el cambio no afecta permisos, organigrama ni planilla hasta ese día. | No |
| RN-HIS-05 | Si la fecha de vigencia cae en un periodo de planilla cerrado → el cambio se acepta solo con permiso de corrección y genera una diferencia retroactiva en el periodo abierto; el periodo cerrado no se altera. | No |
| RN-HIS-06 | Si se corrige un movimiento → el registro original queda marcado como reemplazado y visible en la línea de tiempo con el motivo. | No |
| RN-HIS-07 | Si un movimiento cambia puesto, categoría o remuneración → se genera la evidencia de comunicación de la política salarial (Ley 30709) para firma o acuse. | No |
| RN-HIS-08 | Si un incremento salarial proviene de un ciclo de revisión o de una promoción → queda enlazado a su origen y a su aprobación. | No |
| RN-HIS-09 | Si se pide un dato «a la fecha X» → se devuelve el registro cuya vigencia contiene X. | No |

### CON · Contratos y adendas

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-CON-01 | Si el contrato es a plazo fijo (modalidad) → exige fecha de fin y causa objetiva escrita. | No |
| RN-CON-02 | Si la suma de plazos de una misma modalidad para la persona en la empresa supera el máximo de esa modalidad (parámetro del paquete de país) → no se permite renovar bajo esa modalidad. | Sí |
| RN-CON-03 | Si la suma de todos los contratos a modalidad supera el tope total (parámetro del paquete de país, a confirmar con la norma vigente) → se bloquea la renovación y se propone convertir a indeterminado. | Sí |
| RN-CON-04 | Si faltan N días para el vencimiento (parámetro, varias anticipaciones) → se genera alerta a RRHH y al jefe con explicación. | Sí |
| RN-CON-05 | Si un contrato a plazo vence sin renovación ni cese registrado y la persona sigue activa → se marca «vencido sin regularizar», se alerta como riesgo de desnaturalización y aparece en cumplimiento. | No |
| RN-CON-06 | Si un contrato no está firmado por ambas partes → no pasa a «Vigente» (salvo carga de contrato físico escaneado con marca de origen). | No |
| RN-CON-07 | Si el periodo de prueba supera el máximo del tipo de puesto (parámetro del paquete de país, a confirmar) → se rechaza. | Sí |
| RN-CON-08 | Si una adenda firmada cambia remuneración, puesto o jornada → se registra el movimiento con vigencia correspondiente de forma automática. | No |
| RN-CON-09 | Si el contrato es de tiempo parcial o modalidad formativa → el régimen de beneficios de la relación se ajusta según el paquete de país. | No |
| RN-CON-10 | Si se pacta teletrabajo → el acuerdo debe contener todos los campos mínimos (modalidad, jornada, desconexión, lugar, supervisión, equipos, compensación) antes de enviarse a firma. | No |

### DOC · Gestión documental, plantillas y firma

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-DOC-01 | Si se sube una nueva versión de un documento → la anterior se conserva y queda consultable. | No |
| RN-DOC-02 | Si un documento está firmado → no se modifica ni se reemplaza; un cambio exige un documento nuevo (adenda o nueva versión con nueva firma). | No |
| RN-DOC-03 | Si un firmante firma → se registran hash del documento, sello de tiempo, IP, método de verificación (OTP) y se añade a la constancia de evidencia. | No |
| RN-DOC-04 | Si el envío es secuencial → el firmante siguiente no recibe el documento hasta que firme el anterior. | No |
| RN-DOC-05 | Si un firmante rechaza → el envío termina «Rechazado» con motivo y se notifica al emisor. | No |
| RN-DOC-06 | Si el envío no se completa antes de su fecha de caducidad (parámetro) → pasa a «Vencido» y se puede reenviar. | Sí |
| RN-DOC-07 | Si el hash del documento almacenado no coincide con el de la constancia → se marca como alterado y se alerta. | No |
| RN-DOC-08 | Si el tipo documental es confidencial → solo lo ven el titular y quienes tengan el permiso de confidenciales dentro de su alcance; cada descarga queda en el registro de accesos. | No |
| RN-DOC-09 | Si un documento con vencimiento llega a la ventana de aviso (parámetro por tipo) → se genera alerta al responsable y al titular. | Sí |
| RN-DOC-10 | Si una plantilla usa una variable que el emisor no puede ver (sueldo) → la generación exige que el emisor tenga el permiso del campo. | No |
| RN-DOC-11 | Si una plantilla cambia → los documentos ya generados conservan la versión con la que se emitieron. | No |
| RN-DOC-12 | Si un tipo documental requiere firma con certificado digital (parámetro por tipo) → el envío usa el proveedor de firma digital; en otro caso, la firma electrónica propia. | Sí |
| RN-DOC-13 | Si el colaborador cesa → conserva acceso a sus documentos personales en modo ex-colaborador durante el periodo configurado. | No |

### ESS · Portal del colaborador

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-ESS-01 | Si el usuario tiene alcance «propio» → toda consulta del portal se limita a sus relaciones laborales; no existe parámetro para pedir datos de otra persona. | No |
| RN-ESS-02 | Si el módulo de un bloque no está contratado → el bloque no aparece en el portal. | No |
| RN-ESS-03 | Si el colaborador ve o descarga una boleta → se registra fecha y hora de acceso como constancia de entrega. | No |
| RN-ESS-04 | Si el colaborador tiene varias relaciones activas → elige con cuál opera y ve boletas y saldos separados por empresa. | No |
| RN-ESS-05 | Si el colaborador cesó → mantiene acceso de solo lectura a boletas, certificados, liquidación y documentos de cese por el periodo configurado (parámetro). | Sí |
| RN-ESS-06 | Si una acción del portal exige confirmar identidad (firmar, cambiar cuenta de pago) → se pide OTP aunque la sesión esté activa. | No |

### MSS · Portal del manager

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-MSS-01 | Si el usuario tiene alcance «equipo» → ve a quienes le reportan directa o indirectamente según la línea de reporte vigente; nadie más. | No |
| RN-MSS-02 | Si el rol Manager no trae permiso de un campo sensible → la ficha de sus reportes omite sueldo, salud, sanciones y documentos confidenciales. | No |
| RN-MSS-03 | Si una ausencia solicitada deja al equipo por debajo de la dotación mínima (parámetro por unidad) → el manager ve la advertencia antes de aprobar. | Sí |
| RN-MSS-04 | Si el manager propone un cambio de sueldo o de puesto → nunca se aplica directo; pasa por aprobación y lo ejecuta RRHH o Payroll. | No |
| RN-MSS-05 | Si el manager está ausente → su suplente recibe las aprobaciones y ve al equipo durante la suplencia. | No |

### IMP · Importador y puesta en marcha

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-IMP-01 | Si un archivo no ha pasado por validación y vista previa → no se puede importar. | No |
| RN-IMP-02 | Si hay filas con error → el usuario elige entre importar solo las válidas o corregir todo primero (política por tipo de datos). | Sí |
| RN-IMP-03 | Si una fila apunta a un catálogo inexistente (unidad, puesto, banco) → es error, salvo que el usuario active «crear faltantes» y tenga permiso para administrar ese catálogo. | No |
| RN-IMP-04 | Si la importación incluye columnas sensibles (sueldo, cuenta) → el usuario debe tener el permiso de edición de ese campo; si no, la columna se ignora con aviso. | No |
| RN-IMP-05 | Si se importan históricos (boletas, movimientos pasados) → se cargan como historia de solo lectura marcada «importado», sin disparar eventos de negocio ni notificaciones. | No |
| RN-IMP-06 | Si se importan colaboradores vigentes → cada alta respeta las mismas reglas que el alta manual (documento único, límites del plan, vigencias sin solape). | No |
| RN-IMP-07 | Si se deshace un lote → solo es posible mientras ningún registro del lote haya sido usado por otro proceso (planilla cerrada, firma, aprobación); en ese caso se indica qué lo impide. | No |
| RN-IMP-08 | Si el mismo archivo se sube dos veces → se detecta por huella y se advierte antes de continuar. | No |
| RN-IMP-09 | Si se importan saldos de vacaciones → entran como saldo inicial con fecha de corte y sustento, no como movimientos inventados. | No |

## Fase 2 · Tiempo

### ASI · Asistencia y marcación

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-ASI-01 | Si una marca se registra → no se puede editar ni borrar; cualquier ajuste es una marca correctiva nueva con motivo, autor y aprobación, enlazada a la original. | No |
| RN-ASI-02 | Si se genera el registro legal de asistencia → incluye razón social, RUC, nombre y documento del trabajador, fecha, hora y minutos de ingreso y salida y sobretiempo. | No |
| RN-ASI-03 | Si un registro de asistencia tiene menos de cinco años (valor de referencia 2026, parámetro con vigencia) → no puede purgarse por la política de retención. | Sí |
| RN-ASI-04 | Si la entrada ocurre después de la hora del horario más la tolerancia (parámetro por política) → se calcula tardanza en minutos desde la hora de inicio. | Sí |
| RN-ASI-05 | Si un día laborable del horario no tiene marcas ni ausencia aprobada → se registra falta injustificada al cerrar el día. | No |
| RN-ASI-06 | Si hay entrada sin salida al cerrar el día → se marca incidencia «marca incompleta»; no se asume hora de salida. | No |
| RN-ASI-07 | Si la marca se hace fuera de la geocerca de la sede asignada → se acepta con incidencia o se rechaza según la política (parámetro). | Sí |
| RN-ASI-08 | Si la política exige selfie o biometría → no se admite la marca sin ella y el colaborador debe tener consentimiento vigente; sin consentimiento se ofrece un método alternativo. | Sí |
| RN-ASI-09 | Si una marca llega de un dispositivo sin conexión → se conserva la hora del dispositivo y la de recepción; una diferencia de reloj mayor que la tolerancia genera incidencia. | No |
| RN-ASI-10 | Si el colaborador está marcado como no sujeto a control de asistencia → no se le calculan tardanzas, faltas ni horas extra. | No |
| RN-ASI-11 | Si el tiempo trabajado excede la jornada del horario → el exceso se registra como «sobretiempo por calificar» y solo se convierte en horas extra pagables o compensables cuando se aprueba (M-BHO). | No |
| RN-ASI-12 | Si las horas trabajadas caen en la franja nocturna (parámetro del paquete de país) → se contabilizan como horas nocturnas para la planilla. | Sí |
| RN-ASI-13 | Si la asistencia del periodo está cerrada → no se admiten correcciones salvo reapertura con permiso y motivo. | No |
| RN-ASI-14 | Si dos marcas del mismo tipo llegan dentro de la ventana de duplicado (parámetro) → la segunda se guarda marcada como duplicada y no entra al cálculo. | No |

### TUR · Horarios, jornadas y turnos

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-TUR-01 | Si un turno planificado hace superar la jornada máxima diaria o semanal (parámetro del paquete de país, a confirmar con la norma vigente) → se advierte o se bloquea según la política. | Sí |
| RN-TUR-02 | Si entre el fin de un turno y el inicio del siguiente hay menos que el descanso mínimo (parámetro) → se advierte o se bloquea. | Sí |
| RN-TUR-03 | Si la persona tiene una ausencia o vacaciones aprobadas en la fecha → no se le puede asignar turno. | No |
| RN-TUR-04 | Si un plan de turnos no está publicado → no rige para el cálculo de asistencia ni se notifica al equipo. | No |
| RN-TUR-05 | Si se modifica un turno ya publicado → se notifica al afectado y queda historial del cambio. | No |
| RN-TUR-06 | Si la cobertura de un turno queda por debajo del mínimo (parámetro por unidad) → se advierte al planificador. | Sí |
| RN-TUR-07 | Si una persona no tiene horario asignado en una fecha → hereda el de su grupo, sede o empresa, en ese orden. | No |
| RN-TUR-08 | Si un día es feriado según el calendario de su sede → no se espera asistencia salvo turno asignado, y el trabajo se califica como feriado trabajado. | No |

### VAC · Vacaciones

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-VAC-01 | Si la relación está en régimen general → devenga los días anuales del régimen (parámetro del paquete de país, a confirmar con la norma vigente); si es micro o pequeña empresa → 15 días por año (valor de referencia 2026, parámetro con vigencia). | Sí |
| RN-VAC-02 | Si se consulta el saldo → se calcula sumando los movimientos del libro de vacaciones; no existe un campo de saldo editable. | No |
| RN-VAC-03 | Si se aprueba una solicitud → los días pasan de «disponibles» a «programadas» en el mismo acto, y calendario, tablero e incidencias de planilla se actualizan. | No |
| RN-VAC-04 | Si los días solicitados superan el saldo disponible más el adelanto permitido por la política (parámetro) → se rechaza el envío. | Sí |
| RN-VAC-05 | Si la solicitud no respeta la anticipación mínima o el fraccionamiento mínimo de la política (parámetros; el fraccionamiento legal a confirmar con la norma vigente) → se rechaza o se pide excepción a RRHH. | Sí |
| RN-VAC-06 | Si las fechas se solapan con otra ausencia aprobada de la misma persona → se rechaza. | No |
| RN-VAC-07 | Si el rango incluye días no laborables → se cuentan o no según la regla de cómputo de la política (días calendario o hábiles; parámetro). | Sí |
| RN-VAC-08 | Si las fechas caen en un periodo de planilla cerrado → no se puede aprobar ni cancelar sin el flujo de ajuste de RRHH. | No |
| RN-VAC-09 | Si un periodo vacacional no se goza dentro del año siguiente a su generación (regla y efecto a confirmar con la norma vigente) → se marca «vencido», se alerta y se calcula la indemnización vacacional según el paquete de país. | Sí |
| RN-VAC-10 | Si una persona acumula más periodos que el máximo de la política → se alerta a su jefe y a RRHH con explicación. | Sí |
| RN-VAC-11 | Si durante el goce se registra un descanso médico → las vacaciones no se interrumpen de forma automática; RRHH decide según política y los días devueltos se registran como movimiento. | No |
| RN-VAC-12 | Si la relación cesa → los días pendientes y truncos pasan a la liquidación (M-LIQ) y el saldo queda en cero. | No |
| RN-VAC-13 | Si RRHH ajusta un saldo → exige motivo y sustento y queda como movimiento auditable. | No |

### AUS · Ausencias y permisos

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-AUS-01 | Si el tipo exige sustento → no se aprueba sin el documento adjunto (se puede registrar en estado «pendiente de sustento» por el plazo configurado). | No |
| RN-AUS-02 | Si el tipo es sin goce de haber → genera descuento proporcional en planilla y, según el paquete de país, no computa para ciertos beneficios (parámetro por tipo). | Sí |
| RN-AUS-03 | Si los días de descanso médico del año superan los que asume el empleador (parámetro del paquete de país, a confirmar con la norma vigente) → los días siguientes se tratan como subsidio y se separan en planilla. | Sí |
| RN-AUS-04 | Si el tipo tiene duración fijada por ley (maternidad, paternidad, fallecimiento; parámetros del paquete de país, a confirmar) → el sistema propone la duración y no permite menos. | Sí |
| RN-AUS-05 | Si el tipo es confidencial (salud) → el jefe ve «ausencia justificada» y fechas, nunca el diagnóstico ni el certificado. | No |
| RN-AUS-06 | Si una ausencia aprobada se solapa con marcas de asistencia → se genera incidencia para que RRHH resuelva. | No |
| RN-AUS-07 | Si la ausencia supera el máximo de días u horas del tipo en el periodo (parámetro) → requiere excepción de RRHH. | Sí |
| RN-AUS-08 | Si la ausencia cae en un periodo de planilla cerrado → solo RRHH la registra y su efecto va como ajuste al periodo abierto. | No |
| RN-AUS-09 | Si se registra «trabajo remoto» ocasional → no descuenta ni afecta asistencia; exige marcación remota si la política lo pide. | No |

### BHO · Horas extra y bolsa de horas

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-BHO-01 | Si el sobretiempo no está aprobado → no se paga ni se acredita; permanece «por calificar» y visible para RRHH (el trabajo efectivo no reconocido es un riesgo que se alerta). | No |
| RN-BHO-02 | Si se califican horas extra → las dos primeras del día llevan la sobretasa del primer tramo y las siguientes la del segundo (parámetros del paquete de país, a confirmar con la norma vigente). | Sí |
| RN-BHO-03 | Si el destino es compensación → requiere acuerdo aceptado por el colaborador y las horas se acreditan a la bolsa con la equivalencia de la política (parámetro). | Sí |
| RN-BHO-04 | Si las horas de la bolsa no se usan dentro del plazo de la política (parámetro) → se pagan en la planilla siguiente con su sobretasa; no se pierden. | Sí |
| RN-BHO-05 | Si la persona supera el tope de horas extra del periodo (parámetro por política) → se bloquea una nueva autorización o se exige nivel superior. | Sí |
| RN-BHO-06 | Si el colaborador no está sujeto a control de asistencia → no se le registran horas extra. | No |
| RN-BHO-07 | Si el trabajo ocurre en feriado o día de descanso sin descanso sustitutorio → se califica con la regla propia de esos días del paquete de país. | No |
| RN-BHO-08 | Si las horas aprobadas difieren de las realmente marcadas → se pagan o acreditan las efectivamente trabajadas hasta el máximo aprobado; el exceso vuelve a «por calificar». | No |
| RN-BHO-09 | Si la relación cesa con saldo en la bolsa → el saldo se paga en la liquidación. | No |

### TSH · Hojas de tiempo por proyecto

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-TSH-01 | Si las horas registradas del día difieren de las de asistencia más allá de la tolerancia (parámetro) → se advierte y la hoja no puede enviarse sin justificación. | Sí |
| RN-TSH-02 | Si la persona no está asignada al proyecto en esa fecha → no puede imputarle horas. | No |
| RN-TSH-03 | Si una hoja está aprobada → no se edita; una corrección exige devolución por el aprobador. | No |
| RN-TSH-04 | Si se calcula el costo hora → se deriva del costo laboral del periodo cerrado de planilla y solo lo ven quienes tienen el permiso de costo. | No |
| RN-TSH-05 | Si el periodo de hojas está bloqueado → no admite registros nuevos. | No |
| RN-TSH-06 | Si una hoja no se envía antes del límite semanal (parámetro) → se recuerda al colaborador y a su jefe. | Sí |
| RN-TSH-07 | Si existen hojas aprobadas en el periodo → la distribución contable del costo de la persona usa esos porcentajes en lugar de su centro de costo fijo. | No |

## Fase 3 · Nómina Perú

### NOM · Motor de nómina

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-NOM-01 | Si se calcula un periodo → cada parámetro y fórmula se toma con la vigencia de la fecha del periodo, no con la del día del cálculo. | No |
| RN-NOM-02 | Si se repite el cálculo con las mismas entradas → el resultado es idéntico (el motor no depende de la hora ni del orden de proceso). | No |
| RN-NOM-03 | Si una regla legal cambia → se publica como nueva versión de parámetro o fórmula con fecha; ningún despliegue de código es necesario. | No |
| RN-NOM-04 | Si un resultado se guarda → conserva la fórmula, las entradas y la versión de parámetros que lo produjeron. | No |
| RN-NOM-05 | Si una relación ingresa, cesa o cambia de sueldo dentro del periodo → los conceptos proporcionales se prorratean por tramos de vigencia y días según la base del régimen (parámetro). | No |
| RN-NOM-06 | Si una fórmula referencia un concepto o parámetro inexistente o crea un ciclo → no se puede publicar. | No |
| RN-NOM-07 | Si un cambio afecta un periodo cerrado → el motor calcula la diferencia y la lleva al periodo abierto; el periodo cerrado nunca se reescribe. | No |
| RN-NOM-08 | Si se redondea → se aplica la regla de redondeo del paquete de país por concepto (parámetro). | Sí |
| RN-NOM-09 | Si la relación pertenece a un régimen → solo se le aplican los conceptos habilitados para ese régimen. | No |
| RN-NOM-10 | Si falta un dato obligatorio para calcular (régimen pensionario, fecha de ingreso, sueldo vigente) → la relación queda «con error» y no bloquea el cálculo de las demás. | No |
| RN-NOM-11 | Si la persona tiene dos relaciones en el grupo → cada una se calcula en su planilla; los conceptos que la norma consolida (renta de quinta) reciben la información de la otra como ingreso de otro empleador. | No |
| RN-NOM-12 | Si se calcula una relación → el motor obtiene del paquete de país la estrategia del régimen vigente en cada tramo (general, micro, pequeña, construcción civil, agrario, formativo); el núcleo no tiene condicionales por régimen (`18-localizacion-peru-detalle-tecnico.md` §4.7). | No |
| RN-NOM-13 | Si se proyecta la renta de quinta → remuneración ordinaria × meses que faltan + gratificaciones por percibir con su bonificación extraordinaria + lo ya percibido + rentas de otros empleadores declaradas, menos la deducción en UIT, con la escala y los divisores del parámetro; el ingreso extraordinario del mes se grava aparte por diferencia de impuestos y se retiene íntegro. | Sí |
| RN-NOM-14 | Si el trabajador tiene gastos deducibles adicionales (las 3 UIT de la declaración anual) → el motor no los considera: solo los aplica la administración tributaria en la determinación anual. | No |
| RN-NOM-15 | Si el trabajador tiene más de un empleador y declaró que otro retiene → la retención de quinta es cero y el libro registra el motivo; si esta razón social retiene, acumula las rentas declaradas de los demás. | No |
| RN-NOM-16 | Si el trabajador no es domiciliado → la retención es el porcentaje de `quinta_no_domiciliado_tasa_pct` sobre el total afecto del mes, sin proyección ni deducción. | Sí |
| RN-NOM-17 | Si la relación es de construcción civil → el jornal, la bonificación unificada, la movilidad y las demás bonificaciones se toman de la tabla salarial vigente en cada día trabajado, según la categoría del trabajador en la obra (reglas RN-PE-CC del documento 18). | Sí |
| RN-NOM-18 | Si la relación es agraria con prorrateo → la gratificación y la CTS se pagan dentro de la remuneración diaria como conceptos separados, y la bonificación especial por trabajo agrario se paga como concepto no remunerativo (reglas RN-PE-AGR del documento 18). | Sí |
| RN-NOM-19 | Si la razón social no tiene acreditación vigente del régimen especial, o la relación es anterior a la acreditación → no se propone el régimen de micro o pequeña empresa (reglas RN-PE-MYP del documento 18). | No |

### CPT · Conceptos y fórmulas

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-CPT-01 | Si se modifica un concepto usado en periodos cerrados → se crea una versión nueva con vigencia; la anterior no cambia. | No |
| RN-CPT-02 | Si un concepto tiene resultados históricos → no se elimina; se archiva. | No |
| RN-CPT-03 | Si un concepto proviene de la plantilla del país → la organización puede cambiar nombre y cuenta contable, pero no su afectación legal sin crear un concepto propio y dejar constancia. | No |
| RN-CPT-04 | Si la fórmula no supera la validación (sintaxis, referencias, ciclo) → no se guarda como publicable. | No |
| RN-CPT-05 | Si un concepto no tiene mapeo a código de declaración siendo declarable → la validación previa de PLAME lo reporta como error. | No |
| RN-CPT-06 | Si un concepto es no remunerativo → no suma a ninguna base salvo que la afectación se marque de forma expresa con sustento. | No |
| RN-CPT-07 | Si quien edita un concepto intenta publicarlo → se exige que la publicación la haga otra persona con `payroll.concepts.publish` cuando la política de doble control está activa (parámetro; activa por defecto). | No |
| RN-CPT-08 | Si un descuento voluntario llevaría el neto por debajo del mínimo protegido (parámetro del paquete de país, a confirmar) → se descuenta hasta el límite y el resto se difiere. | Sí |
| RN-CPT-09 | Si un concepto afecta a la base de quinta → declara su naturaleza en la proyección (ordinario, gratificación o extraordinario); sin ella no se puede activar. | No |
| RN-CPT-10 | Si un concepto declara a qué regímenes aplica → el motor solo lo ofrece a las relaciones de esos regímenes. | No |

### PAR · Parámetros legales con vigencia

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-PAR-01 | Si un cálculo necesita una tasa, tope o monto legal → lo obtiene del parámetro vigente en la fecha del hecho para el país y régimen de la relación; nunca de una constante. | No |
| RN-PAR-02 | Si se registra un nuevo valor → se inserta con su vigencia y fuente; el anterior queda cerrado, no modificado. | No |
| RN-PAR-03 | Si un parámetro está en estado «a confirmar» → se puede usar en simulación, pero el cierre de un periodo que lo use exige confirmación expresa del responsable de planilla. | No |
| RN-PAR-04 | Si falta el parámetro o la tabla del periodo (por ejemplo, comisiones de AFP del mes) → el cálculo de los afectados queda «con error» con mensaje claro. | No |
| RN-PAR-05 | Si la UIT de referencia es S/ 5 500 y la RMV S/ 1 130 (valores de referencia 2026, parámetros con vigencia) → se cargan como valores iniciales del paquete Perú y todo lo que depende de ellos se deriva, no se copia. | Sí |
| RN-PAR-06 | Si la plataforma publica una actualización → se notifica a Payroll y Administrador de cada organización afectada con la norma y la fecha. | No |
| RN-PAR-07 | Si la organización registra un valor propio más favorable donde la norma lo permite → se usa el propio; si es menos favorable que el legal → se rechaza. | No |
| RN-PAR-08 | Si se publica un parámetro con vigencia retroactiva → se listan los periodos cerrados afectados y se proponen diferencias. | No |
| RN-PAR-09 | Si un parámetro es un plazo → su unidad distingue días calendario (`days`) de días hábiles (`business_days`); el cálculo de vencimientos usa el calendario de la sede. | No |
| RN-PAR-10 | Si se publica una tabla salarial por convenio (construcción civil) → se carga como un lote de parámetros con la misma vigencia y fuente legal, sin verificar hasta que el responsable la confirme contra el acta. | No |
| RN-PAR-11 | Si el periodo de pago cae después del fin de vigencia de la tabla salarial y no hay tabla nueva → se calcula con la última y los afectados quedan marcados «tabla salarial vencida»; al cargarse la nueva se generan los reintegros. | Sí |

### PER · Periodos, incidencias, cálculo, simulación y cierre

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-PER-01 | Si un usuario calculó o modificó incidencias de un periodo → no puede aprobar ese mismo periodo, aunque tenga el permiso (segregación de funciones). | No |
| RN-PER-02 | Si el periodo no está «Aprobado» → no se puede cerrar. | No |
| RN-PER-03 | Si el periodo está «Cerrado» → sus resultados, incidencias y boletas no se modifican; las correcciones van como ajuste al periodo abierto. | No |
| RN-PER-04 | Si existen relaciones «con error» de cálculo → el periodo no puede enviarse a aprobación. | No |
| RN-PER-05 | Si el neto de una persona varía respecto al periodo anterior más que el umbral (parámetro, por defecto configurable por empresa) → se genera una anomalía con las causas calculadas (conceptos que explican la diferencia). | Sí |
| RN-PER-06 | Si una anomalía no está marcada como revisada → el envío a aprobación lo advierte y exige confirmación. | No |
| RN-PER-07 | Si la asistencia del periodo no está cerrada → el cálculo definitivo lo advierte; solo se permite simular. | No |
| RN-PER-08 | Si se reabre un periodo → exige motivo, permiso específico y que no esté declarado ni pagado; las boletas publicadas se retiran y se notifica. | No |
| RN-PER-09 | Si una incidencia viene de otro módulo (ausencia, horas extra, cuota) → no se edita en planilla; se corrige en su origen. | No |
| RN-PER-10 | Si una incidencia manual supera el monto que requiere visto bueno (parámetro) → necesita aprobación antes de entrar al cálculo. | Sí |
| RN-PER-11 | Si el periodo anterior del mismo grupo no está cerrado → no se puede cerrar el siguiente. | No |
| RN-PER-12 | Si se registra el pago → se guarda fecha, medio y lote; el periodo pasa a «Pagado» y se emite `payroll.paid`. | No |
| RN-PER-13 | Si se cierra el periodo → se disparan en orden boletas, archivo de pago, provisiones y asiento; un fallo en uno no revierte el cierre y queda como pendiente visible. | No |
| RN-PER-14 | Si el grupo de planilla es semanal o quincenal → cada periodo de pago lleva el periodo tributario al que consolida; la retención de quinta y el aporte de salud se determinan por mes tributario. | No |

### BOL · Boletas de pago

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-BOL-01 | Si el periodo se cierra → se genera una boleta por relación con resultado y se publica en el portal del titular. | No |
| RN-BOL-02 | Si el trabajador abre o descarga su boleta → se registra fecha y hora como constancia de entrega. | No |
| RN-BOL-03 | Si la política de la empresa exige acuse o firma del trabajador (parámetro) → la boleta queda «pendiente de acuse» y se recuerda hasta obtenerlo. | Sí |
| RN-BOL-04 | Si una boleta ya fue publicada → no se sobrescribe; una corrección genera una nueva versión y conserva la anterior anulada. | No |
| RN-BOL-05 | Si una boleta tiene menos años que el plazo de conservación de documentos de pago (cinco años como valor de referencia, parámetro a confirmar con la norma vigente) → no puede purgarse. | Sí |
| RN-BOL-06 | Si un usuario distinto del titular consulta una boleta → necesita `payroll.payslips.view` y `payroll.results.view`, y el acceso queda en el registro de accesos sensibles. | No |
| RN-BOL-07 | Si el trabajador no tiene acceso digital → la boleta puede marcarse «entregada en físico» con cargo escaneado. | No |
| RN-BOL-08 | Si se descarga en bloque → el archivo se entrega por enlace temporal y queda registrado quién lo pidió. | No |

### BSO · Beneficios sociales

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-BSO-01 | Si la relación está en régimen general → la gratificación equivale a una remuneración computable por semestre completo, proporcional por meses completos laborados (valor de referencia 2026, parámetro con vigencia); pequeña empresa: media remuneración; microempresa: no corresponde. | Sí |
| RN-BSO-02 | Si se paga gratificación → se añade la bonificación extraordinaria del 9 % de la gratificación (valor de referencia 2026, parámetro con vigencia); el porcentaje para afiliados a EPS es un parámetro a confirmar con la norma vigente. | Sí |
| RN-BSO-03 | Si se calcula CTS → la remuneración computable incluye el sueldo más un sexto de la última gratificación y los depósitos se programan en la primera quincena de mayo y de noviembre (valores de referencia 2026, parámetros con vigencia). | Sí |
| RN-BSO-04 | Si la relación es de pequeña empresa → la CTS equivale a 15 remuneraciones diarias por año (valor de referencia 2026, parámetro con vigencia); microempresa: no corresponde. | Sí |
| RN-BSO-05 | Si el trabajador no tiene entidad depositaria registrada → el proceso de CTS lo reporta como error y no genera su línea. | No |
| RN-BSO-06 | Si se realiza el depósito de CTS → se emite a cada trabajador la liquidación con fecha, entidad, cuenta, periodo y cálculo. | No |
| RN-BSO-07 | Si el trabajador tiene uno o más hijos con derecho registrados y sustentados → percibe asignación familiar del 10 % de la RMV vigente (valor de referencia 2026, parámetro con vigencia); el monto se deriva de la RMV, no se digita. | Sí |
| RN-BSO-08 | Si la empresa supera el umbral de trabajadores que obliga a repartir utilidades (más de 20, valor de referencia 2026) y genera renta → se reparte el porcentaje de su actividad (industria 10 %, comercio y restaurantes 8 %, otras 5 % como valores de referencia; lista completa a confirmar con la norma vigente). | Sí |
| RN-BSO-09 | Si se distribuyen utilidades → la mitad se reparte en proporción a los días laborados y la mitad en proporción a las remuneraciones (criterio y tope individual: parámetros a confirmar con la norma vigente). | Sí |
| RN-BSO-10 | Si una persona cesó durante el ejercicio → participa en las utilidades por el tiempo laborado y se le liquida aunque ya no esté activa. | No |
| RN-BSO-11 | Si un concepto no está marcado como computable para un beneficio → no entra a su base (M-CPT define afectaciones). | No |
| RN-BSO-12 | Si una remuneración es variable (comisiones, horas extra) → entra a la base computable por promedio del periodo y bajo el requisito de regularidad del paquete de país (parámetros a confirmar). | Sí |
| RN-BSO-13 | Si se procesa la gratificación o la CTS → el factor y la forma salen del régimen de la relación: una remuneración (general), media (pequeña empresa), no corresponde (microempresa), 40 jornales (construcción civil); las relaciones agrarias con prorrateo se omiten y queda en la traza. | Sí |

### PRE · Préstamos, adelantos y adelanto de sueldo

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-PRE-01 | Si el colaborador pide adelanto de sueldo → el máximo es el porcentaje de la política sobre la remuneración neta estimada devengada a la fecha, menos adelantos ya otorgados en el periodo (parámetros). | Sí |
| RN-PRE-02 | Si se otorga un adelanto → se descuenta de forma automática y completa en el cálculo del periodo al que corresponde. | No |
| RN-PRE-03 | Si la suma de cuotas y descuentos voluntarios supera el porcentaje máximo del neto de la política (parámetro) → no se aprueba el préstamo con ese cronograma. | Sí |
| RN-PRE-04 | Si una cuota no puede descontarse completa (neto insuficiente, licencia sin goce) → se descuenta lo posible y el saldo se reprograma según la política, quedando registrado. | No |
| RN-PRE-05 | Si la relación cesa con saldo → el saldo se descuenta en la liquidación hasta el límite permitido y el remanente queda como cuenta por cobrar documentada. | No |
| RN-PRE-06 | Si el colaborador no cumple la antigüedad mínima o está en periodo de prueba → no es elegible (parámetros). | Sí |
| RN-PRE-07 | Si el periodo de planilla ya está en aprobación o cerrado → los adelantos nuevos se imputan al periodo siguiente. | No |
| RN-PRE-08 | Si se refinancia → se cierra el cronograma anterior y se crea uno nuevo enlazado; el historial se conserva. | No |
| RN-PRE-09 | Si se paga por billetera o transferencia → el desembolso sale por adaptador con idempotencia y solo se marca «Pagado» con confirmación. | No |

### DEC · Declaraciones y archivos

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-DEC-01 | Si el periodo no está cerrado → no se generan archivos definitivos de PLAME, AFPnet ni pago (solo borradores de validación). | No |
| RN-DEC-02 | Si la validación previa encuentra errores → el archivo no puede marcarse como presentable hasta corregirlos en su origen. | No |
| RN-DEC-03 | Si un archivo fue presentado → no se regenera encima; un cambio produce una rectificatoria enlazada con nueva versión. | No |
| RN-DEC-04 | Si ocurre un ingreso, un cese o una modificación declarable → se crea la novedad de T-Registro con su fecha límite (alta: dentro del día del ingreso; modificación: 5 días hábiles; baja: día siguiente, tipo de día a confirmar; parámetros `treg_*`) y se alerta antes de vencer. | Sí |
| RN-DEC-05 | Si un trabajador no tiene completos los datos exigidos (régimen pensionario, de salud, tipo de contrato, establecimiento) → la novedad de alta queda bloqueada con la lista de faltantes. | No |
| RN-DEC-06 | Si un concepto declarable no tiene código de la administración tributaria → PLAME no se puede validar. | No |
| RN-DEC-07 | Si se genera el archivo de AFPnet → lleva por trabajador la identificación, los indicadores de relación laboral, la remuneración asegurable y los aportes voluntarios; el aporte, la comisión y la prima los calcula AFPnet, y el sistema concilia su propio cálculo contra la liquidación devuelta. | No |
| RN-DEC-08 | Si el banco rechaza pagos → cada rechazo se concilia contra su línea y la persona queda «pago pendiente» hasta resolver. | No |
| RN-DEC-09 | Si se genera un archivo de pago → el total debe coincidir con el neto del periodo cerrado; cualquier diferencia impide exportarlo. | No |
| RN-DEC-10 | Si un archivo se descarga → se registra quién y cuándo, y se guarda su hash. | No |
| RN-DEC-11 | Si se genera un archivo oficial → se usa la estructura (`statutory_file_layouts`) vigente en la fecha del periodo; con una estructura no verificada el archivo solo sale como borrador. | No |
| RN-DEC-12 | Si se genera un archivo → se guardan su huella SHA-256, tamaño, totales de control, estructura y versión del generador; regenerar el mismo periodo cerrado produce los mismos bytes. | No |
| RN-DEC-13 | Si un concepto con monto no tiene mapeo vigente a un código de la tabla de conceptos de la autoridad en la fecha del periodo → la validación previa falla; el mapeo tiene vigencia y marca de verificado. | No |
| RN-DEC-14 | Si se intenta el alta de un trabajador en el registro laboral → debe pasar las comprobaciones TR-01 a TR-22 (`18-localizacion-peru-detalle-tecnico.md` §1.5); cada fallo indica campo, pantalla de origen y si bloquea. | No |
| RN-DEC-15 | Si cambia un dato declarable de la ficha del registro laboral → se cierra la fila vigente, se abre otra y se crea la novedad de modificación; lo declarado se compara con lo derivado del núcleo. | No |
| RN-DEC-16 | Si se presenta la planilla de aportes previsionales → debe declararse y pagarse dentro de `afpnet_plazo_dias_habiles` del mes siguiente; el calendario alerta con los días hábiles de la sede. | Sí |
| RN-DEC-17 | Si la planilla es semanal o quincenal → la declaración mensual consolida todas las corridas cerradas del mismo periodo tributario (`statutory_period_code`) y no se genera si falta cerrar alguna. | No |
| RN-DEC-18 | Si un trabajador presenta una declaración jurada de rentas de otros empleadores → se guarda con su documento, no se sobrescribe (una nueva reemplaza a la anterior) y alimenta la proyección de quinta desde el mes en que se valida. | No |
| RN-DEC-19 | Si se calcula la retención de quinta → se inserta una fila en el libro de retenciones con entradas, parámetros y traza; un recálculo es otra fila, nunca una modificación. | No |
| RN-DEC-20 | Si hay retención en exceso → se devuelve al cese (en la liquidación) o en la regularización de diciembre, se compensa contra las retenciones del periodo y el saldo se arrastra o se solicita a la autoridad (ME-49). | No |

### PRV · Provisiones y asientos contables

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-PRV-01 | Si se genera un asiento → el total del debe es igual al del haber; si no cuadra, no se puede exportar y se indica el concepto sin regla. | No |
| RN-PRV-02 | Si un concepto con importe no tiene regla de contabilización → el asiento queda «incompleto» y lo señala. | No |
| RN-PRV-03 | Si se calcula la provisión mensual → usa las mismas fórmulas de devengo del beneficio (gratificación, CTS, vacaciones) según régimen, sobre la remuneración computable del mes (parámetros del paquete de país). | Sí |
| RN-PRV-04 | Si el beneficio se paga → la provisión acumulada se revierte contra el pago y la diferencia se registra como ajuste. | No |
| RN-PRV-05 | Si una relación reparte su costo entre varios centros → las líneas se dividen con los porcentajes vigentes (o los de hojas de tiempo aprobadas). | No |
| RN-PRV-06 | Si un asiento ya fue exportado → no se modifica; una corrección genera un asiento de ajuste o reversa. | No |
| RN-PRV-07 | Si el periodo se reabre → el asiento no exportado se anula; si ya se exportó, se exige reversa. | No |
| RN-PRV-08 | Si la exportación se envía por API → usa idempotencia y registra la referencia devuelta por el ERP. | No |

### LIQ · Liquidación de beneficios sociales

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-LIQ-01 | Si se registra un cese → se crea la liquidación en borrador con la fecha y el motivo del cese. | No |
| RN-LIQ-02 | Si se paga la liquidación → debe hacerse dentro del plazo legal desde el cese (parámetro del paquete de país, a confirmar con la norma vigente); el sistema alerta antes de vencer. | Sí |
| RN-LIQ-03 | Si corresponde indemnización por despido → se calcula con la fórmula y el tope del régimen de la relación (parámetros del paquete de país, a confirmar con la norma vigente) y solo cuando el motivo de cese la genera. | Sí |
| RN-LIQ-04 | Si hay periodos vacacionales no gozados o truncos → se liquidan según la regla del régimen y el saldo de vacaciones queda en cero. | No |
| RN-LIQ-05 | Si existen saldos de préstamos o adelantos → se descuentan hasta el límite permitido y el remanente se documenta. | No |
| RN-LIQ-06 | Si quien calculó la liquidación intenta aprobarla → se rechaza (segregación de funciones). | No |
| RN-LIQ-07 | Si la liquidación se aprueba → no se modifica; un error posterior se corrige con liquidación complementaria. | No |
| RN-LIQ-08 | Si se paga la liquidación → se emiten certificado de trabajo, carta de liberación de CTS y constancia de cese, y se generan las bajas en T-Registro y seguros. | No |
| RN-LIQ-09 | Si se simula → no se crea ningún registro de cese ni se notifica al trabajador; la simulación queda en auditoría como acceso sensible. | No |
| RN-LIQ-10 | Si el motivo es fallecimiento → el pago se registra a favor de los beneficiarios declarados y se bloquea hasta contar con el sustento. | No |
| RN-LIQ-11 | Si se liquida un cese → se calcula el ajuste final de quinta con lo realmente percibido; si resulta exceso, se devuelve en la liquidación y se registra para su compensación. | No |

### LOC · Locadores y cuarta categoría

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-LOC-01 | Si la relación es de tipo locador → no entra a ninguna planilla ni genera beneficios sociales, asistencia obligatoria ni vacaciones. | No |
| RN-LOC-02 | Si el recibo supera el monto mínimo sujeto a retención y el prestador no tiene suspensión vigente → se retiene el porcentaje del impuesto (parámetros del paquete de país, a confirmar con la norma vigente). | Sí |
| RN-LOC-03 | Si el recibo no tiene conformidad del responsable del servicio → no entra al lote de pago. | No |
| RN-LOC-04 | Si un mismo número de recibo del mismo prestador se registra dos veces → se rechaza. | No |
| RN-LOC-05 | Si un prestador acumula indicadores de laboralidad (horario fijo, exclusividad, continuidad mayor al umbral; parámetros) → se alerta a RRHH con explicación; la decisión es humana. | Sí |
| RN-LOC-06 | Si el contrato de servicios venció → no se aceptan recibos por periodos posteriores sin renovación. | No |

## Fase 4 · Talento

### ATS · Reclutamiento, portal de empleos y base de talento

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-ATS-01 | Si la empresa exige posición aprobada → no se crea una vacante sin posición en estado aprobada o vacante. | Sí |
| RN-ATS-02 | Si la vacante no está aprobada → no se puede publicar. | No |
| RN-ATS-03 | Si un candidato postula → debe aceptar el aviso de privacidad; sin aceptación no se guarda la postulación. | No |
| RN-ATS-04 | Si un candidato no contratado supera el plazo de conservación (parámetro por organización y jurisdicción) sin renovar su consentimiento → sus datos se anonimizan. | Sí |
| RN-ATS-05 | Si un candidato ya existe (mismo documento o correo) → la nueva postulación se añade a su perfil; no se duplica. | No |
| RN-ATS-06 | Si se descarta un candidato → se exige motivo de un catálogo; el motivo no se muestra al candidato salvo el mensaje elegido. | No |
| RN-ATS-07 | Si la IA resume un CV o sugiere orden de revisión → se muestra como apoyo con su explicación; ninguna postulación se descarta ni avanza de forma automática. | No |
| RN-ATS-08 | Si una postulación llega a «Contratado» → requiere una oferta aceptada (M-ENT). | No |
| RN-ATS-09 | Si la vacante cubre todas sus plazas → se cierra y las postulaciones activas restantes pasan a descarte con motivo «vacante cubierta», previa confirmación del reclutador. | No |
| RN-ATS-10 | Si un colaborador refiere a un candidato → queda como fuente «referido» con el referente, que solo ve el estado general. | No |
| RN-ATS-11 | Si el formulario pide datos sensibles (discapacidad para ajustes razonables) → son opcionales, con finalidad explícita, y no visibles para el equipo evaluador. | No |
| RN-ATS-12 | Si una vacante publica rango salarial (parámetro por empresa) → se toma de la banda del puesto. | Sí |

### ENT · Entrevistas y ofertas

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-ENT-01 | Si un entrevistador no ha enviado su evaluación → no ve las de los demás (evita sesgo de arrastre). | No |
| RN-ENT-02 | Si se agenda una entrevista → se valida que entrevistadores no tengan ausencia aprobada ni otra entrevista en ese horario. | No |
| RN-ENT-03 | Si el monto de la oferta supera el máximo de la banda del puesto o el presupuesto de la posición → la aprobación exige el nivel adicional configurado (parámetro). | Sí |
| RN-ENT-04 | Si la oferta no está aprobada → no se puede enviar. | No |
| RN-ENT-05 | Si la oferta no se responde antes de su vencimiento (parámetro) → pasa a «Vencida» y la posición queda libre. | Sí |
| RN-ENT-06 | Si el candidato acepta → la aceptación se registra con la evidencia de firma y se crea el proceso de pre-onboarding. | No |
| RN-ENT-07 | Si una oferta enviada necesita cambios → se retira y se emite una nueva versión; la anterior se conserva. | No |
| RN-ENT-08 | Si ya existe una oferta aceptada para la misma posición de cupo único → no se puede enviar otra. | No |
| RN-ENT-09 | Si la IA propone preguntas o un resumen → el entrevistador decide usarlos; la recomendación de contratar siempre la registra una persona. | No |

### ONB · Pre-onboarding y onboarding

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-ONB-01 | Si se registra un ingreso → se inicia el proceso con la plantilla que coincide con más criterios (puesto, unidad, sede, empresa); si ninguna aplica, la plantilla por defecto. | No |
| RN-ONB-02 | Si una tarea tiene fecha relativa → se calcula desde la fecha de ingreso y se recalcula si esa fecha cambia. | No |
| RN-ONB-03 | Si el responsable de una tarea es un rol o una relación (jefe, TI de la sede) → se resuelve a una persona al crear la tarea y se reasigna si esa persona cambia. | No |
| RN-ONB-04 | Si quedan tareas obligatorias sin completar → el proceso no puede cerrarse como «Completado». | No |
| RN-ONB-05 | Si se calcula el avance → es tareas completadas sobre tareas totales, ponderadas si la plantilla define pesos. | No |
| RN-ONB-06 | Si el futuro colaborador no ha ingresado → su acceso se limita al pre-ingreso; no ve directorio ni datos internos. | No |
| RN-ONB-07 | Si el ingreso se cancela antes de la fecha → el proceso se cancela, las tareas pendientes se anulan y los datos de pre-ingreso se tratan como de candidato no contratado. | No |
| RN-ONB-08 | Si una tarea supera su fecha → se recuerda al responsable y, tras el plazo de escalamiento (parámetro), a su jefe y a RRHH. | Sí |
| RN-ONB-09 | Si la empresa opera en Perú → la plantilla por defecto incluye las tareas de cumplimiento de ingreso (reglamentos, política salarial, capacitaciones obligatorias, Vida Ley, T-Registro) y no se pueden quitar sin dejar constancia. | No |
| RN-ONB-10 | Si se acerca el fin del periodo de prueba (parámetro de anticipación) → se crea la evaluación de periodo de prueba para el jefe. | Sí |

### OFF · Offboarding

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-OFF-01 | Si se registra un cese → se inicia el proceso de salida con la plantilla del tipo de cese. | No |
| RN-OFF-02 | Si llega la fecha efectiva de cese → se desactivan accesos internos y se retiran roles; queda el modo ex-colaborador. | No |
| RN-OFF-03 | Si el saliente tiene aprobaciones pendientes o es jefe → se exige designar a quién pasan antes de cerrar el proceso. | No |
| RN-OFF-04 | Si hay activos o EPP sin devolver → la tarea queda abierta y se informa a liquidación para el descuento según política y norma. | No |
| RN-OFF-05 | Si el motivo detallado o la entrevista de salida se consultan → requieren el permiso sensible; el jefe solo ve el tipo de salida. | No |
| RN-OFF-06 | Si el proceso no tiene liquidación pagada ni documentos entregados → no puede cerrarse como «Completado». | No |
| RN-OFF-07 | Si la salida es por despido → debe existir un caso disciplinario cerrado con esa medida o una justificación registrada por RRHH. | No |
| RN-OFF-08 | Si el cese se anula antes de la fecha efectiva → el proceso se cancela y se revierten las tareas no ejecutadas. | No |
| RN-OFF-09 | Si se configura encuesta de salida → se envía de forma automática con la anticipación de la plantilla (parámetro). | Sí |

### DES · Evaluación de desempeño

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-DES-01 | Si una campaña se lanza → la población se congela con sus jefes vigentes a la fecha de corte; los cambios posteriores se gestionan caso a caso. | No |
| RN-DES-02 | Si las respuestas de pares o de subordinados son menos que el mínimo de anonimato (parámetro) → no se muestran desglosadas; se agrupan con otra categoría o se omiten. | Sí |
| RN-DES-03 | Si una evaluación fue enviada → no se edita salvo reapertura por RRHH con motivo. | No |
| RN-DES-04 | Si se calcula el resultado → se aplican los pesos por sección y por tipo de evaluador definidos en la campaña. | No |
| RN-DES-05 | Si se calibra una calificación → se guarda la original, la ajustada, quién y por qué. | No |
| RN-DES-06 | Si los resultados no están publicados → el evaluado no los ve. | No |
| RN-DES-07 | Si el evaluado cesa durante la campaña → sus evaluaciones pendientes se cancelan y las que debía responder se reasignan o se excluyen. | No |
| RN-DES-08 | Si un evaluador no completa antes de la fecha límite → se recuerda y, vencido el plazo de gracia (parámetro), RRHH puede cerrar sin esa respuesta. | Sí |
| RN-DES-09 | Si el resultado alimenta revisión salarial, 9-box o sucesión → es un insumo; ninguna de esas decisiones se toma de forma automática. | No |
| RN-DES-10 | Si un evaluador es jefe desde hace menos que el tiempo mínimo (parámetro) → se ofrece incluir al jefe anterior como evaluador adicional. | No |

### OKR · Objetivos

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-OKR-01 | Si se calcula el avance de un objetivo → es el promedio ponderado del avance de sus resultados clave. | No |
| RN-OKR-02 | Si un resultado clave tiene valor inicial, meta y valor actual → su avance es (actual − inicial) / (meta − inicial), acotado entre 0 y el máximo permitido. | No |
| RN-OKR-03 | Si la suma de pesos de los objetivos de una persona en el ciclo no es 100 (cuando la política usa pesos) → no puede enviarlos a aprobación. | Sí |
| RN-OKR-04 | Si un objetivo está aprobado → cambiar su meta o peso exige nueva aprobación y queda historial. | No |
| RN-OKR-05 | Si el ciclo está cerrado → no se registran avances. | No |
| RN-OKR-06 | Si un objetivo no recibe actualización en el intervalo definido (parámetro) → se recuerda al dueño y se marca «sin actualizar». | Sí |
| RN-OKR-07 | Si el objetivo es privado → solo lo ven su dueño, su línea de jefes y RRHH. | No |
| RN-OKR-08 | Si la persona cambia de unidad a mitad de ciclo → conserva sus objetivos; el nuevo jefe decide cerrarlos, ajustarlos o mantenerlos. | No |

### CMP · Competencias y habilidades

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-CMP-01 | Si una competencia está en uso en perfiles o evaluaciones → no se elimina; se archiva. | No |
| RN-CMP-02 | Si se registra un nivel de persona → guarda fuente, fecha y autor; el nivel vigente es el más reciente de la fuente de mayor jerarquía definida. | No |
| RN-CMP-03 | Si se calcula la brecha → es nivel requerido menos nivel actual por competencia; sin nivel actual se muestra «sin evaluar», no cero. | No |
| RN-CMP-04 | Si el colaborador declara una habilidad → queda «declarada» hasta que su jefe la valide. | No |
| RN-CMP-05 | Si cambia la escala de niveles de una competencia → los niveles históricos conservan la escala con la que se registraron. | No |
| RN-CMP-06 | Si una validación de habilidad supera su vigencia (parámetro por habilidad) → se marca «por revalidar». | Sí |

### FBK · Reuniones 1:1 y feedback continuo

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-FBK-01 | Si una nota es privada → solo la ve su autor; ni RRHH ni administradores acceden a su contenido desde la aplicación. | No |
| RN-FBK-02 | Si el feedback es «privado» → lo ven emisor y receptor; si es «con jefe» → además el jefe vigente del receptor. | No |
| RN-FBK-03 | Si un compromiso queda pendiente → se arrastra a la agenda de la siguiente reunión de la serie. | No |
| RN-FBK-04 | Si cambia el jefe → la serie 1:1 se cierra, el nuevo jefe no hereda las notas compartidas anteriores salvo que el colaborador las comparta. | No |
| RN-FBK-05 | Si RRHH consulta indicadores → ve frecuencia y cumplimiento, nunca el contenido. | No |
| RN-FBK-06 | Si no se realiza un 1:1 dentro de la frecuencia definida (parámetro) → se recuerda a ambos. | Sí |

## Fase 5 · Desarrollo y compensación

### LMS · Aprendizaje y plan anual de capacitación

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-LMS-01 | Si un curso tiene evaluación → se completa solo al alcanzar la nota mínima; los intentos se limitan al máximo configurado. | No |
| RN-LMS-02 | Si una inscripción obligatoria supera su fecha límite sin completarse → pasa a «Vencido» y se alerta al colaborador, a su jefe y al responsable. | Sí |
| RN-LMS-03 | Si el curso tiene vigencia (recertificación) → al acercarse el vencimiento del certificado se crea una nueva inscripción automática (anticipación: parámetro). | Sí |
| RN-LMS-04 | Si un curso se asigna por regla (puesto, unidad) → quien entra a la población después recibe la inscripción automáticamente. | No |
| RN-LMS-05 | Si el contenido de un curso cambia de versión → las inscripciones completadas conservan la versión cursada. | No |
| RN-LMS-06 | Si un curso es de tipo legal (SST, hostigamiento sexual) → su asistencia y evaluación alimentan el registro obligatorio correspondiente. | No |
| RN-LMS-07 | Si la persona cesa → sus inscripciones pendientes se cancelan y sus certificados se conservan. | No |
| RN-LMS-08 | Si una solicitud de capacitación supera el presupuesto disponible del plan → exige aprobación adicional (parámetro). | Sí |
| RN-LMS-09 | Si se emite un certificado → lleva código de verificación consultable. | No |

### CAR · Desarrollo, carrera, sucesión y 9-box

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-CAR-01 | Si una persona se ubica en la matriz 9-box → no se produce ningún cambio automático en sueldo, puesto ni estado; toda acción posterior requiere decisión y aprobación humana. | No |
| RN-CAR-02 | Si se propone un sucesor → la persona propuesta no ve su condición de sucesor salvo que RRHH decida comunicarla. | No |
| RN-CAR-03 | Si se muestra una ruta de carrera → incluye las competencias requeridas del puesto destino y la brecha de la persona. | No |
| RN-CAR-04 | Si una acción del plan es un curso → crea la inscripción y su avance se refleja en el plan. | No |
| RN-CAR-05 | Si una posición crítica no tiene sucesor «preparado ahora» → aparece como riesgo en el tablero de sucesión. | No |
| RN-CAR-06 | Si se muestra un riesgo de rotación → se listan los factores que lo componen y su peso; nunca un puntaje sin explicación, y no dispara acciones automáticas. | No |
| RN-CAR-07 | Si el modelo de riesgo usa datos personales para perfilar → requiere evaluación de impacto registrada antes de activarse (M-PRI). | Sí |
| RN-CAR-08 | Si la ubicación 9-box se modifica → se conserva el historial por sesión con autor y sustento. | No |
| RN-CAR-09 | Si el sucesor o el titular cesan o cambian de posición → el plan de sucesión se marca «por revisar». | No |

### COM · Compensaciones, bandas, revisión salarial e igualdad salarial

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-COM-01 | Si se aplica un ciclo → cada incremento aprobado crea un registro de compensación con vigencia en M-HIS; el módulo no guarda sueldos propios. | No |
| RN-COM-02 | Si las propuestas de un manager superan su presupuesto asignado → no puede enviarlas sin solicitar excepción. | Sí |
| RN-COM-03 | Si una propuesta deja el sueldo fuera de la banda del puesto → exige justificación y aprobación del nivel adicional (parámetro). | Sí |
| RN-COM-04 | Si una etapa del ciclo no está aprobada → la siguiente no puede iniciarse. | No |
| RN-COM-05 | Si el ciclo está aplicado → no se modifica; correcciones por movimiento individual. | No |
| RN-COM-06 | Si quien propone el incremento es el beneficiario o su subordinado directo → se rechaza; nadie propone ni aprueba su propio sueldo. | No |
| RN-COM-07 | Si un puesto no tiene categoría asignada → se reporta como incumplimiento del cuadro de categorías y funciones. | No |
| RN-COM-08 | Si una persona ingresa, cambia de puesto o de remuneración → se genera la comunicación de la política salarial y se registra su acuse como evidencia. | No |
| RN-COM-09 | Si la brecha salarial entre sexos dentro de una categoría supera el umbral de revisión (parámetro de la organización) → se exige registrar la justificación con criterios objetivos o un plan de corrección. | Sí |
| RN-COM-10 | Si un grupo del reporte de brecha tiene menos personas que el mínimo de confidencialidad (parámetro) → se muestra agregado para no revelar sueldos individuales. | Sí |
| RN-COM-11 | Si el sistema sugiere un incremento por lineamiento → es una referencia; la propuesta y la aprobación son humanas. | No |
| RN-COM-12 | Si se compara la posición en banda → usa la banda vigente del puesto y la moneda de la relación, con conversión por tipo de cambio de la fecha. | No |

### BNF · Beneficios

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-BNF-01 | Si la persona no cumple las reglas de elegibilidad → el beneficio no se le muestra como disponible. | No |
| RN-BNF-02 | Si un beneficio tiene costo para el trabajador → la inscripción exige su aceptación expresa del descuento. | No |
| RN-BNF-03 | Si la inscripción se pide fuera de la ventana definida (parámetro) → requiere excepción de RRHH, salvo evento de vida (ingreso, nacimiento, matrimonio). | Sí |
| RN-BNF-04 | Si la persona deja de ser elegible (cambio de puesto, cese) → la inscripción termina en la fecha del cambio y cesa el concepto de planilla. | No |
| RN-BNF-05 | Si un beneficio es remunerativo → su concepto de planilla lleva las afectaciones correspondientes (M-CPT). | No |
| RN-BNF-06 | Si se inscribe a un dependiente → debe existir como derechohabiente registrado. | No |

## Fase 6 · Experiencia

### ENC · Encuestas, clima y eNPS

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-ENC-01 | Si la encuesta es anónima → la respuesta se guarda sin identificador de persona, usuario, IP ni dispositivo; la participación se registra aparte y sin clave que permita unirlas. | No |
| RN-ENC-02 | Si un segmento tiene menos respuestas que el umbral mínimo (parámetro; no puede fijarse por debajo del mínimo de plataforma) → sus resultados no se muestran y se agrupan en el nivel superior. | Sí |
| RN-ENC-03 | Si la combinación de filtros permite aislar a una persona por diferencia entre segmentos → el sistema oculta también el segmento complementario. | No |
| RN-ENC-04 | Si una encuesta ya se lanzó → no se puede cambiar su condición de anónima ni añadir segmentaciones nuevas. | No |
| RN-ENC-05 | Si una persona ya respondió → no puede responder de nuevo; en modo anónimo se controla con un token de un solo uso sin relación con la respuesta. | No |
| RN-ENC-06 | Si se calcula eNPS → es porcentaje de promotores (9–10) menos porcentaje de detractores (0–6) sobre respuestas válidas. | No |
| RN-ENC-07 | Si la IA resume comentarios → trabaja sobre el conjunto que el usuario puede ver y no cita textos que identifiquen al autor en segmentos pequeños. | No |
| RN-ENC-08 | Si la encuesta es anónima → los comentarios abiertos solo se muestran a quien tenga el permiso y agrupados al nivel que cumple el umbral. | No |
| RN-ENC-09 | Si la participación no alcanza el mínimo definido para dar por válida la medición (parámetro) → se advierte antes de publicar resultados. | Sí |
| RN-ENC-10 | Si un plan de acción vence sin avance → se alerta a su responsable y a RRHH. | No |

### REC · Reconocimientos

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-REC-01 | Si una persona intenta reconocerse a sí misma → se rechaza. | No |
| RN-REC-02 | Si el programa usa puntos → cada persona tiene un cupo por periodo para dar (parámetro) y no puede excederlo. | Sí |
| RN-REC-03 | Si un reconocimiento se oculta por moderación → deja de verse en el muro, se conserva con el motivo y se notifica al autor. | No |
| RN-REC-04 | Si el muro está desactivado para la organización → los reconocimientos son visibles solo para emisor, receptor y jefe. | No |
| RN-REC-05 | Si el receptor cesa → sus reconocimientos se conservan en el historial y dejan de mostrarse en el muro activo. | No |
| RN-REC-06 | Si los puntos tienen valor canjeable → su tratamiento en planilla (remunerativo o no) se define por parámetro y concepto antes de activar el canje. | Sí |

### CIN · Comunicación interna

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-CIN-01 | Si una publicación tiene segmento → solo la ven quienes pertenecen a él al momento de consultar, más sus autores. | No |
| RN-CIN-02 | Si la publicación requiere confirmación → queda en pendientes del destinatario hasta que confirme; la confirmación guarda fecha y hora. | No |
| RN-CIN-03 | Si alguien entra al segmento después de publicar (ingreso, traslado) y la publicación sigue vigente → la recibe. | No |
| RN-CIN-04 | Si un colaborador desactiva mostrar su cumpleaños → no aparece en celebraciones; la preferencia por defecto la define la organización. | No |
| RN-CIN-05 | Si la publicación la crea un rol que requiere aprobación (parámetro) → no sale hasta ser aprobada. | Sí |
| RN-CIN-06 | Si un comunicado con lectura obligatoria se edita tras publicarse → se crea una versión nueva y se pide confirmar de nuevo. | No |
| RN-CIN-07 | Si la confirmación no llega dentro del plazo (parámetro) → se recuerda al destinatario y luego a su jefe. | Sí |

### HSD · Mesa de ayuda y base de conocimiento

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-HSD-01 | Si se crea una solicitud → su plazo de primera respuesta y de resolución se calcula con el SLA de su categoría y prioridad sobre el calendario laboral de la empresa (parámetros). | Sí |
| RN-HSD-02 | Si la solicitud está «Esperando información» → el reloj del SLA se detiene y se reanuda al responder el solicitante. | No |
| RN-HSD-03 | Si el solicitante no responde en el plazo configurado → la solicitud se cierra automáticamente con aviso (parámetro). | Sí |
| RN-HSD-04 | Si un plazo está por vencer o vence → se alerta al agente y se escala según la regla de la cola. | Sí |
| RN-HSD-05 | Si la categoría es confidencial → solo la ven el solicitante y los agentes con el permiso; no aparece en búsquedas ni reportes generales. | No |
| RN-HSD-06 | Si una solicitud resuelta no se reabre dentro del plazo (parámetro) → pasa a «Cerrado» y ya no admite reapertura; se crea una nueva enlazada. | Sí |
| RN-HSD-07 | Si un agente es el propio solicitante → no puede atender su solicitud. | No |
| RN-HSD-08 | Si un artículo se publica → tiene versión, autor y fecha de revisión; los artículos vencidos se marcan «por revisar». | No |
| RN-HSD-09 | Si la IA responde una consulta → cita el artículo fuente y ofrece crear una solicitud; no inventa políticas ni datos personales. | No |
| RN-HSD-10 | Si la solicitud pide un certificado de emisión automática → se genera el documento (M-DOC) y la solicitud se resuelve sola. | No |

## Fase 7 · Operación y cumplimiento

### SST · Seguridad y salud en el trabajo

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-SST-01 | Si se registra un accidente de trabajo o incidente peligroso → su registro se conserva 10 años; enfermedades ocupacionales 20 años; los demás registros 5 años (valores de referencia 2026, parámetros con vigencia). | Sí |
| RN-SST-02 | Si un incidente se cierra → debe tener investigación, causas y todas sus medidas en estado verificada o descartada con motivo. | No |
| RN-SST-03 | Si el evento es accidente mortal o incidente peligroso → se crea la tarea de notificación a la autoridad con su plazo (parámetro del paquete de país, a confirmar con la norma vigente). | Sí |
| RN-SST-04 | Si un usuario sin `sst.medical_exams.view` consulta a una persona → solo ve la aptitud y las restricciones laborales, nunca diagnósticos ni resultados. | No |
| RN-SST-05 | Si un examen ocupacional está por vencer (anticipación y periodicidad por tipo de puesto: parámetros) → se alerta a SST y al colaborador. | Sí |
| RN-SST-06 | Si una medida correctiva supera su plazo → pasa a «Vencida», se alerta al responsable y a SST y cuenta en el tablero. | No |
| RN-SST-07 | Si la empresa tiene 20 o más trabajadores (`sst_comite_minimo_trabajadores`, comparación mayor o igual) → cumplimiento exige comité vigente; con menos de 20, supervisor elegido por los trabajadores. | Sí |
| RN-SST-08 | Si un puesto o sede está marcado de riesgo → sus ocupantes deben figurar en la póliza SCTR vigente; si no, se alerta. | No |
| RN-SST-09 | Si se entrega un EPP → queda con firma del trabajador y alimenta el registro de equipos de seguridad. | No |
| RN-SST-10 | Si la empresa es micro o pequeña → se usan los registros simplificados del paquete de país (norma a confirmar). | No |
| RN-SST-11 | Si un colaborador ingresa o cambia a un puesto con riesgos distintos → se le asigna la inducción y capacitación específica y se registra la entrega de recomendaciones de SST. | No |
| RN-SST-12 | Si se reporta un acto o condición insegura de forma anónima → se registra sin identificar al reportante. | No |
| RN-SST-13 | Si faltan los días de aviso para el fin del mandato (`sst_eleccion_aviso_dias`), la dotación cruza el umbral o hay vacancia sin suplente → se planifica un proceso electoral (ME-46) con el plazo de cada etapa tomado de los parámetros `sst_eleccion_*`. | Sí |
| RN-SST-14 | Si se emite un voto en una elección → se registra quién votó en el padrón y se incrementa el contador del candidato, sin guardar vínculo entre votante y preferencia. | No |
| RN-SST-15 | Si un integrante de la junta electoral se inscribe como candidato (o un candidato se designa en la junta) → se rechaza. | No |
| RN-SST-16 | Si la sesión no alcanza el quórum (mitad más uno de los miembros) → queda «Sin quórum» y se crea una sesión nueva dentro de `sst_sin_quorum_reprogramacion_dias`, que sesiona con los presentes. | Sí |
| RN-SST-17 | Si un mes calendario termina sin sesión ordinaria realizada y el comité está vigente → la obligación del mes pasa a «incumple» con explicación. | No |
| RN-SST-18 | Si el acta se firma → se asienta en el libro inalterable de cumplimiento con la huella del documento y ya no se edita; una corrección es un acta aclaratoria nueva. | No |
| RN-SST-19 | Si un miembro acumula las inasistencias de `sst_vacancia_inasistencias_consecutivas` o `sst_vacancia_inasistencias_alternas` → se propone la vacancia y se llama al suplente. | Sí |
| RN-SST-20 | Si se elige a los representantes → el comité debe instalarse dentro de `sst_instalacion_dias_habiles`; vencido el plazo se alerta y la obligación pasa a «en riesgo». | Sí |

### DEN · Canal de denuncias y hostigamiento

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-DEN-01 | Si la denuncia es anónima → no se guarda IP, usuario, dispositivo ni sesión; la bitácora técnica del portal de denuncias excluye esos datos. | No |
| RN-DEN-02 | Si se entrega el código de seguimiento → el sistema guarda solo su huella; no se puede recuperar, y sin código y clave nadie accede al seguimiento. | No |
| RN-DEN-03 | Si un usuario no es receptor o investigador asignado → no ve la existencia del caso, ni en búsquedas, reportes, auditoría general ni notificaciones. | No |
| RN-DEN-04 | Si una persona es mencionada en la denuncia (denunciado o testigo) → no puede ser asignada como investigadora ni ver el caso, aunque tenga el rol. | No |
| RN-DEN-05 | Si el Administrador empresa revisa la auditoría → ve que hubo actividad en el canal, sin contenido ni identidades. | No |
| RN-DEN-06 | Si la queja es por hostigamiento sexual → las medidas de protección deben dictarse y ejecutarse dentro de 3 días hábiles desde la recepción (`host_medidas_proteccion_dias_habiles`), y la atención médica y psicológica debe ofrecerse dentro de 1 día hábil (`host_atencion_dias_habiles`); el caso crea ambos plazos con alerta. | Sí |
| RN-DEN-07 | Si la queja es por hostigamiento sexual → cada etapa lleva su plazo como parámetro: traslado al comité 1 día hábil, informe del comité 15 días calendario desde la recepción, decisión 10 días calendario desde el informe, y comunicación a la autoridad de trabajo 6 días hábiles tras la recepción y 6 tras la decisión; no existe un parámetro de duración total, y el expediente alerta antes de cada vencimiento. | Sí |
| RN-DEN-08 | Si el centro de trabajo tiene 20 o más trabajadores → cumplimiento exige comité de intervención vigente (dos representantes del empleador, uno de ellos de Recursos Humanos, y dos de los trabajadores, con paridad de género); con menos de 20, un delegado. | Sí |
| RN-DEN-09 | Si se adjunta evidencia → se eliminan metadatos del archivo antes de guardarlo. | No |
| RN-DEN-10 | Si un caso se cierra → requiere resultado, fundamento y medidas; el denunciante lo ve con su código. | No |
| RN-DEN-11 | Si se generan estadísticas → categorías con menos casos que el mínimo se agrupan para no identificar. | No |
| RN-DEN-12 | Si la denuncia llega por otro canal (mesa de ayuda, jefe) → se registra en el canal a nombre del receptor como «recibida por tercero», con consentimiento. | No |
| RN-DEN-13 | Si pasa el plazo de retención del caso cerrado (parámetro por jurisdicción) → se anonimiza conservando estadística. | Sí |
| RN-DEN-14 | Si se registra un acto del procedimiento de hostigamiento (recepción, atención, medida, traslado, informe, decisión, comunicación) → se asienta en el libro inalterable del caso, encadenado por huella, sin identidad del denunciante ni hora (solo fecha). | No |
| RN-DEN-15 | Si un miembro del comité o el receptor es mencionado en la queja → no puede intervenir: se llama al suplente antes de trasladar el caso. | No |
| RN-DEN-16 | Si vence un plazo del procedimiento sin el acto correspondiente → el plazo queda «cumplido tarde» o «vencido» con los días de atraso, se alerta al titular de Recursos Humanos y se asienta en el libro. | Sí |
| RN-DEN-17 | Si la empresa no es microempresa acreditada → se exige una capacitación anual especializada para Recursos Humanos y el comité, además de la capacitación al inicio de la relación laboral y la evaluación anual. | Sí |

### DIS · Casos disciplinarios

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-DIS-01 | Si se pretende aplicar una medida → debe existir imputación notificada y haberse cumplido el plazo de descargos o recibido los descargos. | No |
| RN-DIS-02 | Si se notifica la imputación → el plazo de descargos se calcula según el tipo de falta (parámetro del paquete de país, a confirmar con la norma vigente). | Sí |
| RN-DIS-03 | Si la medida es despido o suspensión → la decide una persona con `discipline.cases.resolve` distinta de quien condujo el caso. | No |
| RN-DIS-04 | Si un usuario no tiene permiso disciplinario → no ve la existencia de casos ni medidas en la ficha, reportes ni búsquedas; el jefe solo ve lo que él reportó y la medida comunicada. | No |
| RN-DIS-05 | Si la medida es suspensión → crea una ausencia sin goce por los días decididos. | No |
| RN-DIS-06 | Si la medida es despido → inicia el offboarding con ese motivo. | No |
| RN-DIS-07 | Si una medida supera su vigencia como antecedente (parámetro de política) → deja de contarse para reincidencia y se marca histórica. | Sí |
| RN-DIS-08 | Si la IA resume el expediente → no propone la medida; solo ordena hechos y documentos. | No |
| RN-DIS-09 | Si el caso proviene de una denuncia → enlaza solo la conclusión necesaria, sin exponer la identidad del denunciante. | No |

### ACT · Activos, EPP y uniformes

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-ACT-01 | Si un activo no está «Disponible» → no puede asignarse. | No |
| RN-ACT-02 | Si un activo unitario está asignado → no admite una segunda asignación simultánea. | No |
| RN-ACT-03 | Si se asigna un activo → requiere acta firmada por quien recibe para quedar «Asignado». | No |
| RN-ACT-04 | Si la relación cesa con activos asignados → se crean las tareas de devolución en offboarding y la liquidación recibe la lista de pendientes. | No |
| RN-ACT-05 | Si un activo se da de baja o se marca perdido → exige motivo y conserva su historial. | No |
| RN-ACT-06 | Si un EPP alcanza su vida útil o fecha de vencimiento (parámetro por tipo) → se genera la tarea de reposición antes de vencer. | Sí |
| RN-ACT-07 | Si el puesto tiene kit de EPP → el ingreso o cambio a ese puesto crea la tarea de entrega. | No |
| RN-ACT-08 | Si las licencias asignadas igualan los cupos del software → no se asigna otra sin ampliar. | Sí |

### GAS · Gastos, viáticos y reembolsos

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-GAS-01 | Si un gasto no tiene comprobante y su categoría lo exige → no puede incluirse en un informe. | No |
| RN-GAS-02 | Si un gasto supera el tope de su categoría para el puesto o destino (parámetro de política) → se marca como exceso y requiere justificación y aprobación adicional, o se reembolsa solo hasta el tope según la política. | Sí |
| RN-GAS-03 | Si el mismo comprobante (emisor, número, monto) ya fue registrado → se advierte como posible duplicado y finanzas debe resolver. | No |
| RN-GAS-04 | Si un gasto se registra pasado el plazo máximo desde su fecha (parámetro) → requiere excepción. | Sí |
| RN-GAS-05 | Si un anticipo no se rinde dentro del plazo (parámetro) → se alerta y, según política, se bloquean nuevos anticipos. | Sí |
| RN-GAS-06 | Si la rendición es menor que el anticipo → se registra la devolución o el descuento autorizado por el trabajador. | No |
| RN-GAS-07 | Si el aprobador es quien registró el gasto → sube al siguiente nivel. | No |
| RN-GAS-08 | Si el gasto está en otra moneda → se convierte con el tipo de cambio de la fecha del gasto. | No |
| RN-GAS-09 | Si el pago va por planilla → entra como concepto no remunerativo del periodo abierto; si va por tesorería → en lote de pago propio. | No |
| RN-GAS-10 | Si el informe está «Pagado» → no se modifica. | No |

### CUM · Cumplimiento Perú y SUNAFIL

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-CUM-01 | Si una obligación depende de un umbral (número de trabajadores, mujeres en edad fértil por sede, promedio anual) → el sistema calcula el indicador con datos vigentes y aplica el umbral del parámetro. | No |
| RN-CUM-02 | Si el semáforo muestra un estado → incluye siempre la explicación con los datos y la regla que lo producen. | No |
| RN-CUM-03 | Si faltan datos para evaluar → el estado es «sin datos» con la lista de lo que falta; nunca «cumple» por omisión. | No |
| RN-CUM-04 | Si la empresa supera el umbral que obliga a la cuota de empleo de personas con discapacidad (umbral y porcentaje: parámetros a confirmar con la norma vigente) → se calcula la cuota sobre el promedio anual de trabajadores y se compara con las personas registradas con consentimiento. | Sí |
| RN-CUM-05 | Si una sede alcanza el número de mujeres en edad fértil que obliga a lactario (parámetro a confirmar con la norma vigente) → la obligación pasa a «aplica» y pide evidencia de implementación. | Sí |
| RN-CUM-06 | Si un trabajador activo no figura en la póliza Vida Ley → la obligación pasa a «incumple» desde el primer día de la relación. | No |
| RN-CUM-07 | Si se contrata o renueva la póliza Vida Ley → se registra y se alerta su registro ante la autoridad dentro de 30 días (valor de referencia 2026, parámetro con vigencia). | Sí |
| RN-CUM-08 | Si se registra un requerimiento de la casilla → el plazo corre desde la notificación aunque nadie la haya abierto; el sistema alerta según anticipación (parámetro). | Sí |
| RN-CUM-09 | Si se genera el expediente → cada documento incluido conserva su hash y el índice lista lo que falta por trabajador. | No |
| RN-CUM-10 | Si una evidencia manual tiene vigencia → al vencer, la obligación vuelve a «por vencer» o «incumple». | No |
| RN-CUM-11 | Si la obligación no aplica a la empresa (régimen, tamaño) → se muestra «no aplica» con la razón. | No |
| RN-CUM-12 | Si un proceso de cumplimiento asienta un acto → la fila del libro (`compliance_ledger_entries`) guarda la huella de la fila anterior de su cadena; la tabla es de solo inserción y no tiene columnas de IP ni de sesión. | No |
| RN-CUM-13 | Si la verificación de una cadena encuentra una fila que no cuadra → se abre un incidente de seguridad y el expediente solo se exporta con la advertencia de integridad. | No |
| RN-CUM-14 | Si se exporta un expediente → el paquete lleva un manifiesto con la huella de cada pieza y de cada asiento, y la exportación queda asentada en la misma cadena. | No |
| RN-CUM-15 | Si se registra una notificación de la casilla electrónica → se exige el instante del depósito; surte efectos ese día (o el primer día hábil siguiente si es inhábil) y el plazo que fija el documento corre desde el día hábil siguiente. | Sí |
| RN-CUM-16 | Si no se registra la revisión diaria de la casilla de una razón social → se alerta al responsable y, al segundo día hábil, al administrador. | No |
| RN-CUM-17 | Si una obligación debe comunicarse a la autoridad → se crea la comunicación con su plazo (parámetro) y solo se da por cumplida con el cargo de presentación adjunto. | Sí |

### PRI · Privacidad y retención

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-PRI-01 | Si un tratamiento requiere consentimiento (biometría, imagen, discapacidad, conservación de datos de candidato) → no se ejecuta sin consentimiento vigente y registrado con versión y fecha. | No |
| RN-PRI-02 | Si el titular revoca un consentimiento → el tratamiento se detiene desde ese momento y se ofrece la alternativa (otro método de marcación). | No |
| RN-PRI-03 | Si se registra una solicitud de derechos → se calcula su fecha límite según el tipo (plazos del paquete de país, a confirmar con la norma vigente) y se alerta antes de vencer. | Sí |
| RN-PRI-04 | Si se pide cancelar datos que la empresa debe conservar por obligación legal (planilla, asistencia, SST) → se rechaza esa parte con el fundamento y se atiende el resto. | No |
| RN-PRI-05 | Si se detecta un incidente de seguridad con datos personales → se crea la tarea de notificación con plazo de 48 horas (valor de referencia 2026, parámetro con vigencia). | Sí |
| RN-PRI-06 | Si un dato cumple su plazo de retención y no hay obligación ni litigio que lo impida → entra al lote de anonimización o eliminación, que exige aprobación antes de ejecutarse. | Sí |
| RN-PRI-07 | Si se anonimiza un registro → se conservan los agregados estadísticos y se elimina toda posibilidad de reidentificación; la acción es irreversible y deja acta. | No |
| RN-PRI-08 | Si se activa una función de biometría o de perfilado con IA → exige una evaluación de impacto registrada y aprobada. | No |
| RN-PRI-09 | Si los datos se alojan o transfieren fuera del país → debe existir el registro de la transferencia y sus garantías. | No |
| RN-PRI-10 | Si existe una retención legal por litigio o inspección sobre una persona → sus datos quedan excluidos de cualquier purga hasta levantarla. | No |
| RN-PRI-11 | Si el titular pide portabilidad o acceso → recibe sus datos en formato estructurado, sin datos de terceros. | No |

### SIN · Sindicatos y convenios

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-SIN-01 | Si se descuenta cuota sindical → debe existir afiliación vigente con autorización del trabajador. | No |
| RN-SIN-02 | Si el trabajador se desafilia → el descuento cesa desde la fecha de vigencia de la desafiliación. | No |
| RN-SIN-03 | Si un usuario no tiene el permiso de afiliación → no ve quién está afiliado en ficha, reportes ni exportaciones; la boleta del titular sí muestra su descuento. | No |
| RN-SIN-04 | Si una cláusula de convenio define un beneficio económico → se aplica solo a la población de su ámbito y dentro de su vigencia. | No |
| RN-SIN-05 | Si un dirigente usa licencia sindical → se descuenta de su cupo (parámetro del convenio o de la norma, a confirmar); agotado, requiere autorización. | Sí |
| RN-SIN-06 | Si el convenio vence sin uno nuevo → la continuidad de sus cláusulas es una decisión registrada por RRHH, no automática. | No |
| RN-SIN-07 | Si la afiliación se usa en analítica → solo en agregados que cumplan el umbral de confidencialidad. | No |

## Fase 8 · Inteligencia

### ANA · People analytics y tableros

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-ANA-01 | Si un indicador se muestra → se calcula solo con las personas dentro del alcance del usuario. | No |
| RN-ANA-02 | Si el indicador usa datos de un campo sensible (costo, sueldo, diversidad) → exige el permiso correspondiente; sin él, el widget no aparece. | No |
| RN-ANA-03 | Si un segmento tiene menos personas que el mínimo de confidencialidad (parámetro) y el indicador es sensible → se oculta o se agrega. | Sí |
| RN-ANA-04 | Si se calcula la rotación → es bajas del periodo sobre dotación promedio del periodo, con la definición visible; cada indicador tiene una única fórmula publicada. | No |
| RN-ANA-05 | Si se consulta un periodo pasado → se usa la estructura y la dotación vigentes en ese periodo (historia con vigencia), no las actuales. | No |
| RN-ANA-06 | Si un indicador de diversidad usa sexo, edad, discapacidad o nacionalidad → solo se muestra agregado y cuando la norma permite tratar ese dato para esa finalidad. | No |
| RN-ANA-07 | Si un módulo no está contratado → sus indicadores no aparecen en la biblioteca. | No |
| RN-ANA-08 | Si el locador no es trabajador → no cuenta en dotación ni rotación; se reporta aparte. | No |

### RPT · Reportes y constructor

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-RPT-01 | Si el usuario construye un reporte → solo ve fuentes y campos de módulos contratados para los que tiene permiso, incluidos permisos de campo. | No |
| RN-RPT-02 | Si un reporte guardado se comparte → cada persona lo ejecuta con sus propios permisos y alcance; las columnas no permitidas se omiten. | No |
| RN-RPT-03 | Si un reporte programado se entrega → se ejecuta con los permisos vigentes del destinatario en ese momento y no adjunta datos sensibles en correo; entrega un enlace. | No |
| RN-RPT-04 | Si el destinatario perdió el acceso o cesó → la entrega se cancela y se avisa al dueño. | No |
| RN-RPT-05 | Si la IA genera un reporte → produce una definición visible que el usuario puede revisar y editar; se ejecuta con los permisos del usuario. | No |
| RN-RPT-06 | Si las fuentes de denuncias, casos disciplinarios o datos médicos existen → no están disponibles en el constructor general. | No |
| RN-RPT-07 | Si la ejecución supera el límite de filas o tiempo (parámetros) → pasa a segundo plano. | Sí |
| RN-RPT-08 | Si se exporta → queda registrado en actividad con la definición usada. | No |

### ALE · Alertas explicadas

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-ALE-01 | Si una alerta no tiene explicación (regla aplicada, datos y registros afectados) → no se muestra. | No |
| RN-ALE-02 | Si el destinatario no puede ver alguno de los registros afectados → la alerta se le muestra solo con los que sí puede ver, o no se le envía. | No |
| RN-ALE-03 | Si la condición deja de cumplirse → la alerta pasa a «Resuelta» automáticamente. | No |
| RN-ALE-04 | Si una alerta se pospone → reaparece en la fecha elegida, con un máximo de posposiciones (parámetro). | Sí |
| RN-ALE-05 | Si una regla repetiría la misma alerta para el mismo registro → se actualiza la existente en lugar de crear otra. | No |
| RN-ALE-06 | Si la alerta proviene de un modelo (anomalía, riesgo) → muestra los factores y su contribución, y se rotula como estimación. | No |
| RN-ALE-07 | Si se evalúa «contratos por vencer», «vacaciones acumuladas» u otras reglas de plazo → usan las anticipaciones configuradas (parámetros por regla). | Sí |
| RN-ALE-08 | Si una alerta sugiere una acción sobre una persona (cesar, sancionar, cambiar sueldo) → nunca la ejecuta; solo enlaza al flujo humano. | No |

### IAR · Asistente de IA y gobierno

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-IAR-01 | Si la IA accede a datos → lo hace con las mismas comprobaciones de permiso, alcance y campo que el usuario que la invoca; no existe un acceso privilegiado de la IA. | No |
| RN-IAR-02 | Si se usa la IA → se registra usuario, momento, caso de uso, fuentes consultadas y respuesta. | No |
| RN-IAR-03 | Si una acción implica contratar, cesar, sancionar, promover o cambiar un sueldo → la IA no puede ejecutarla ni dejarla aprobada; solo prepara información para que una persona decida en el flujo normal. | No |
| RN-IAR-04 | Si la IA sugiere, ordena o puntúa → muestra los criterios usados; sin explicación no se muestra el resultado. | No |
| RN-IAR-05 | Si la organización no activó un caso de uso → la función no aparece. | No |
| RN-IAR-06 | Si el caso de uso implica perfilar personas (riesgo de rotación, orden de candidatos) → requiere evaluación de impacto aprobada y se rotula como estimación revisable. | No |
| RN-IAR-07 | Si los datos pertenecen a denuncias, salud o casos disciplinarios → nunca se envían al proveedor de IA. | No |
| RN-IAR-08 | Si el registro de usos supera su plazo de retención (parámetro) → se purga conservando metadatos. | Sí |
| RN-IAR-09 | Si la IA ejecuta una acción con efecto (crear solicitud, borrador) → pide confirmación explícita al usuario y la acción queda auditada con origen «IA» y el usuario responsable. | No |
| RN-IAR-10 | Si la respuesta proviene de documentos → cita la fuente; si no hay fuente, lo dice. | No |

## Fase 9 · Ecosistema y expansión

### API · API pública, webhooks y MCP

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-API-01 | Si una llamada usa una credencial → solo accede a los recursos de sus alcances y nunca a más de lo que permite el módulo contratado. | No |
| RN-API-02 | Si un alcance no incluye campos sensibles → la respuesta los omite igual que para un usuario sin permiso de campo. | No |
| RN-API-03 | Si la aplicación supera su límite de llamadas (parámetro del plan) → recibe respuesta de límite excedido con el tiempo de espera. | Sí |
| RN-API-04 | Si se envía un webhook → va firmado, con identificador de evento y sin datos sensibles en el cuerpo; el receptor consulta el detalle por API. | No |
| RN-API-05 | Si la entrega de un webhook falla → se reintenta según la política y, agotados los intentos (parámetros), la suscripción se marca con error y se avisa. | Sí |
| RN-API-06 | Si se introduce un cambio incompatible → se publica en una nueva versión de la API; la anterior se mantiene durante el periodo de convivencia anunciado. | No |
| RN-API-07 | Si un asistente actúa por MCP → lo hace con los permisos del usuario que autorizó, y las acciones de alto impacto no están expuestas como herramientas. | No |
| RN-API-08 | Si una credencial no se usa durante el periodo configurado o su creador pierde el rol → se marca para revisión. | No |
| RN-API-09 | Si se crea una credencial → el secreto se muestra una única vez y se almacena solo su huella. | No |

### INT · Integraciones

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-INT-01 | Si un módulo necesita un servicio externo → lo llama a través de la interfaz del adaptador; ningún módulo referencia a un proveedor concreto. | No |
| RN-INT-02 | Si un efecto externo se envía (pago, mensaje, asiento, firma) → sale por la bandeja de salida con clave de idempotencia; un reintento no lo duplica. | No |
| RN-INT-03 | Si un conector falla → el proceso de negocio continúa en estado «pendiente de integración» visible, sin perder datos. | No |
| RN-INT-04 | Si se guardan credenciales → se cifran y nunca se devuelven completas por la API ni se escriben en logs. | No |
| RN-INT-05 | Si un adaptador no tiene implementación falsa → no pasa la revisión de calidad. | No |
| RN-INT-06 | Si una sincronización supera el número de fallos consecutivos (parámetro) → se pausa y se alerta al administrador. | Sí |
| RN-INT-07 | Si un conector de reloj biométrico entrega marcas → entran como marcaciones con su origen y siguen las mismas reglas de inalterabilidad. | No |
| RN-INT-08 | Si se usa SSO → el alta y la baja de usuarios pueden sincronizarse, pero los roles y el alcance se administran en Ayni HR. | No |

### WHA · Autoservicio por WhatsApp

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-WHA-01 | Si un número no está vinculado y verificado → no recibe ningún dato personal. | No |
| RN-WHA-02 | Si la última verificación de identidad supera el tiempo configurado (parámetro) → se pide de nuevo antes de entregar documentos o crear trámites. | Sí |
| RN-WHA-03 | Si se entrega una boleta o constancia → se hace por enlace de un solo uso con caducidad o por documento protegido, según la política (parámetro); nunca montos en texto del chat. | Sí |
| RN-WHA-04 | Si el trámite se crea por WhatsApp → pasa por las mismas validaciones, aprobaciones y auditoría que por el portal, con origen «WhatsApp». | No |
| RN-WHA-05 | Si la marcación llega por WhatsApp → exige ubicación compartida y sigue las reglas de geocerca. | No |
| RN-WHA-06 | Si el colaborador cesa o cambia de número → la vinculación se revoca. | No |
| RN-WHA-07 | Si la consulta no se entiende o es sensible → se deriva a un agente o al portal. | No |

### PAI · Paquetes de país

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-PAI-01 | Si una regla depende del país → vive en el paquete como dato (parámetro, fórmula, plantilla, obligación) o como extensión declarada del paquete; el núcleo no contiene condiciones por país. | No |
| RN-PAI-02 | Si una empresa tiene un país → usa el paquete de ese país; empresas de países distintos conviven en la misma organización. | No |
| RN-PAI-03 | Si se publica una versión del paquete → los periodos cerrados no cambian; los cambios rigen desde su vigencia. | No |
| RN-PAI-04 | Si la organización ajustó un elemento del paquete → una actualización no lo pisa; se muestra la diferencia y la organización decide. | No |
| RN-PAI-05 | Si un paquete se publica → debe pasar sus casos de regresión de cálculo. | No |
| RN-PAI-06 | Si no existe paquete para un país → la empresa puede usar Core HR y los módulos no legales, y la nómina queda no disponible con mensaje claro. | No |

### WLB · Marca blanca

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-WLB-01 | Si el plan no incluye marca blanca → solo se permite logo y nombre. | No |
| RN-WLB-02 | Si el color elegido no cumple el contraste mínimo de accesibilidad (parámetro) → el sistema lo ajusta o lo rechaza con explicación. | Sí |
| RN-WLB-03 | Si el dominio propio no está verificado → no se activa y se sigue usando el dominio de la plataforma. | No |
| RN-WLB-04 | Si se cambia la marca → los documentos ya emitidos conservan la marca con la que se generaron. | No |
| RN-WLB-05 | Si hay varias empresas con marca propia → cada documento y correo usa la marca de la empresa de la relación. | No |

### EST · Modo estudio contable

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-EST-01 | Si el personal del estudio entra a un cliente → opera con el rol y el alcance que ese cliente delegó, dentro del aislamiento de esa organización; ninguna consulta mezcla clientes. | No |
| RN-EST-02 | Si el cliente revoca la delegación → el acceso del estudio termina de inmediato y los datos permanecen en el cliente. | No |
| RN-EST-03 | Si se ejecuta una acción en lote → corre por separado en cada cliente y un fallo en uno no afecta a los demás. | No |
| RN-EST-04 | Si el estudio calcula una planilla → la aprobación la hace una persona distinta (del estudio o del cliente), igual que en cualquier organización. | No |
| RN-EST-05 | Si el tablero de cartera muestra datos → solo estados y fechas; los montos requieren entrar al cliente con permiso. | No |
| RN-EST-06 | Si una acción del estudio se audita → queda en la auditoría del cliente con el usuario real del estudio. | No |
| RN-EST-07 | Si el estudio supera el número de clientes de su plan → no puede vincular otro. | Sí |

## Transversal

### QA · Calidad, seguridad y salida a producción

| Código | Regla (condición → resultado) | ¿Parámetro? |
|---|---|---|
| RN-QA-01 | Si un endpoint no tiene prueba de aislamiento y de permiso → la integración continua falla. | No |
| RN-QA-02 | Si una pantalla contiene un control sin operación real → la épica no se da por terminada. | No |
| RN-QA-03 | Si una acción no persiste o no actualiza las vistas relacionadas (saldo, calendario, tablero, actividad) → es un defecto bloqueante. | No |
| RN-QA-04 | Si el paquete de país cambia → se ejecutan los casos de referencia de cálculo y cualquier diferencia no esperada bloquea la publicación. | No |
| RN-QA-05 | Si existe código previo que funciona y aporta valor → se conserva o se refactoriza; solo se elimina con justificación técnica registrada. | No |
| RN-QA-06 | Si una decisión es crítica y no determinable técnicamente → se detiene ese punto y se consulta; en lo demás se continúa sin pedir confirmación. | No |
| RN-QA-07 | Si los datos demo no permiten recorrer el ciclo completo de vacante a offboarding → la semilla se considera incompleta. | No |
| RN-QA-08 | Si un parámetro legal del paquete sigue «a confirmar» → la salida a producción de nómina para ese cálculo queda bloqueada hasta la validación del especialista. | No |
