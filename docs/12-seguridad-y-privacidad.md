# 12 · Seguridad y privacidad

Los datos de RRHH son de los más sensibles de una empresa: sueldos, salud, denuncias, sanciones, biometría, afiliación sindical, datos de familiares. Principios: mínimo privilegio, defensa en capas, todo acceso sensible deja rastro, y lo que la interfaz oculta lo valida el backend.

Las referencias normativas vienen de `../../docs/research.md` §4, que cita varias desde fuentes secundarias. **Este documento no es asesoría legal**: los plazos y obligaciones deben validarse con el área legal antes de la salida a producción (D-15).

## 1. Clasificación de datos

| Clase | Ejemplos | Tratamiento |
|---|---|---|
| **Pública** | Vacantes publicadas, marca | Sin restricción |
| **Interna** | Nombre, puesto, unidad, correo laboral, organigrama | Permiso del recurso + alcance |
| **Personal** | Documento de identidad, dirección, teléfono, fecha de nacimiento, cuenta bancaria, familiares | Permiso del recurso + alcance; cifrado de campo para documento y cuenta; enmascarado en listados |
| **Sensible** | Sueldo y planilla, salud (exámenes, diagnósticos, descansos médicos, discapacidad), sanciones, afiliación sindical, biometría, evaluaciones de desempeño, antecedentes | Permiso **por campo** + cifrado de campo + registro de cada acceso + nunca en búsqueda, logs ni webhooks |
| **Restringida** | Denuncias, hostigamiento, identidad del denunciante, medidas de protección | Módulo aislado, clave propia, acceso solo por asignación al caso, auditoría separada |

La clase de cada columna se declara en el modelo de datos y de ahí salen el cifrado, el filtro de Resources, la exclusión del índice de búsqueda y la política de retención.

## 2. Modelo de amenazas

| Amenaza | Vector | Controles |
|---|---|---|
| Fuga entre organizaciones | Error de consulta, caché compartida, trabajo en cola sin contexto, archivo con ruta adivinable | RLS forzado + scope de aplicación + FK compuestas; caché con prefijo; `RestoreTenant`; URL firmadas; prueba de aislamiento sobre todas las rutas |
| Acceso indebido dentro de la organización | Manipular identificadores (IDOR), rol demasiado amplio | Policy + alcance en la consulta; 404 fuera de alcance; matriz de permisos probada; permisos por campo |
| Escalada de privilegios | Asignarse roles; asignación masiva de campos; `status` por `PATCH` | Nadie concede lo que no tiene; lista blanca de campos en FormRequest; estados solo por acción |
| Robo de cuenta | Relleno de credenciales, phishing, OTP adivinado | MFA, límite de intentos, bloqueo progresivo, detección de contraseñas filtradas, OTP de un uso con caducidad y límite, aviso de nuevo dispositivo |
| Secuestro de sesión | XSS, robo de cookie | Cookie `HttpOnly` `Secure` `SameSite`; CSP estricta; rotación de sesión al autenticar; caducidad |
| Inyección | SQL, plantillas de documentos, fórmulas, cabeceras CSV | Consultas parametrizadas; plantillas con motor en modo seguro (sin ejecución de código); evaluador de fórmulas propio sin `eval`; neutralizar `= + - @` al exportar a CSV o Excel |
| XSS | Campos libres, HTML de comunicados, nombres de archivo | Escapado por defecto; HTML enriquecido saneado en servidor con lista blanca; CSP |
| CSRF | Sesión web | Token CSRF en escrituras; `SameSite` |
| SSRF | URL de webhook, importación por URL, vista previa | Solo HTTPS; bloqueo de rangos internos y metadatos; sin redirecciones; resolución fijada |
| Archivo malicioso | Subida de CV, comprobantes, evidencias | Verificación de tipo real, tamaño, antivirus; almacenamiento privado; descarga con `Content-Disposition: attachment`; dominio aparte para contenido de usuario |
| Abuso interno | RRHH curioseando sueldos; soporte de plataforma leyendo datos | Registro de accesos sensibles visible para el cliente; sesión de soporte con motivo, vigencia y auditoría; alertas por volumen anómalo de accesos o descargas |
| Extracción masiva | Exportar todo, recorrer la API | Permiso de exportación aparte; exportaciones auditadas y con caducidad; límite de solicitudes; alerta por volumen |
| Manipulación de registros | Alterar asistencia, auditoría o planilla cerrada | Tablas de solo inserción a nivel de privilegios; periodo cerrado inmutable; reapertura auditada |
| Desanonimización | Encuesta con segmento pequeño; cruce de filtros; metadatos de denuncia | Umbral mínimo por segmento; no guardar vínculo persona–respuesta; sin IP ni usuario en denuncias anónimas; limpieza de metadatos de adjuntos |
| Fraude en marcación | GPS falso, marcar por otro, foto de una foto | Geocerca + selfie con detección de vida (opcional) + dispositivo registrado + señales de riesgo explicadas; revisión humana |
| Fraude de pagos | Cambio de cuenta bancaria antes del pago | Cambio de cuenta con aprobación y verificación por segundo factor; aviso al titular; periodo de espera parametrizable |
| Inyección de instrucciones a la IA | Texto malicioso en un CV o comentario | La IA solo usa herramientas de solo lectura con permisos del usuario; el contenido de terceros se trata como dato, no como instrucción; sin acciones de alto impacto |
| Proveedor comprometido | Webhook entrante falso | Verificación de firma; deduplicación; secretos por conexión |
| Cadena de suministro | Dependencia vulnerable | Versiones fijadas; análisis de dependencias; revisión de paquetes nuevos |
| Pérdida de datos | Borrado accidental, fallo de infraestructura, secuestro de datos | Copias cifradas, restauración probada, papelera, retención |

## 3. Autenticación

- **Identificadores de acceso**: correo **o** documento de identidad (C-09). El correo no es obligatorio para tener cuenta.
- **Factores**: clave; OTP de un solo uso por SMS, WhatsApp o correo; aplicación autenticadora (TOTP); llaves de acceso (passkeys) como evolución; SSO (OIDC/SAML).
- **Claves**: algoritmo de hash resistente (Argon2id); longitud mínima y comprobación contra listas de contraseñas filtradas; sin reglas de composición arbitrarias; política configurable por organización (longitud, caducidad si la exige, historial).
- **Límite de intentos** por cuenta y por IP con espera creciente; bloqueo temporal y aviso al titular. Mensajes de error que no revelan si la cuenta existe.
- **OTP**: un solo uso, caducidad corta, número máximo de intentos, límite de envíos por destino; nunca se registra en logs.
- **Recuperación**: enlace o código de un solo uso; para personal sin correo, por SMS o WhatsApp al teléfono verificado o restablecimiento por RRHH con auditoría. Restablecer la clave cierra las demás sesiones.
- **Invitación**: enlace de un solo uso con caducidad; el usuario define su clave; nunca se envían claves.
- **Tokens de API**: ver `08-api.md` §3.

### MFA

- Disponible para todos; **obligatorio** para roles con acceso a datos sensibles (Administrador, RRHH, Payroll, Investigador, Contador, Superadministrador) y configurable para el resto (D-08).
- Métodos: TOTP (preferido), OTP por SMS o WhatsApp, códigos de respaldo de un solo uso.
- **Verificación reforzada** (pedir el segundo factor otra vez) antes de: cerrar o reabrir planilla, generar archivo de pago, cambiar cuenta bancaria, exportar datos sensibles, cambiar roles, crear tokens, abrir sesión de soporte, anonimizar.
- Restablecer el MFA de un usuario es una acción auditada con permiso propio.

## 4. Sesiones

- Sesión en servidor (Redis), cookie `HttpOnly`, `Secure`, `SameSite=Lax`; identificador rotado al autenticar y al elevar privilegios.
- Caducidad por inactividad y caducidad absoluta, parametrizables por organización; más cortas para roles sensibles y para la consola de plataforma.
- Lista de sesiones y dispositivos del usuario con cierre remoto; cierre de todas al cambiar la clave, suspender al usuario o cesar la relación (según política, D-23).
- Kioskos: sesión de dispositivo sin acceso a datos, solo marcación; cada marcación identifica a la persona (PIN, QR, biometría del reloj).
- Cambio de organización (estudio contable) o de contexto: nueva sesión acotada; se vacía la caché del cliente.
- Cabeceras: HSTS, CSP, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` (cámara y geolocalización solo donde se usan), sin incrustación en marcos de terceros.

## 5. Autorización

- **Permisos** `modulo.recurso.accion`; **roles** = conjuntos de permisos; roles de sistema editables por copia; roles personalizados.
- **Alcance** por asignación de rol: organización, razón social, sede, unidad (con descendientes), equipo, propio.
- **Permisos por campo** para datos sensibles: `…campo.view` y `…campo.edit`. Grupos mínimos: `salary` (sueldo, compensaciones, resultados de planilla, bandas, propuestas), `health` (exámenes, diagnósticos, restricciones, discapacidad), `bank` (cuenta), `identity` (documento completo, dirección), `discipline`, `union`, `performance_private` (notas privadas, calibración), `biometric`.
- Evaluación en servidor en cuatro puntos: Policy (acción), consulta (alcance), Resource (campos), exportación y reportes (columnas).
- Sin permiso por campo, el campo **no viaja**: ni enmascarado ni vacío.
- Separación de funciones recomendada y configurable: quien calcula la planilla no la aprueba; quien propone un aumento no lo aprueba; nadie aprueba lo propio.
- Delegaciones temporales con fecha de fin y auditoría.
- Cambios de roles y permisos: auditados, con verificación reforzada.

## 6. Cifrado

| Capa | Medida |
|---|---|
| En tránsito | TLS en todo (clientes, servicios internos, base, Redis, almacenamiento) |
| En reposo | Cifrado de volúmenes, base, copias y almacenamiento de archivos |
| De campo | Columnas personales y sensibles cifradas en la aplicación con cifrado autenticado (AES-256-GCM) y **clave de datos por organización**, envuelta por una clave maestra en un servicio de gestión de claves |
| Búsqueda sobre cifrado | Índice ciego (HMAC con clave propia) para igualdad sobre documento de identidad; sin búsqueda parcial sobre campos cifrados |
| Denuncias | Clave separada por organización solo para ese módulo |
| Secretos de integración | Cifrados; nunca legibles por API |
| Claves | Rotación periódica de la maestra; reenvoltura de claves de datos sin recifrar todo; acceso a claves auditado |
| Copias | Cifradas con clave distinta de la de producción |

Sueldos: decisión A-10 (cifrado de campo frente a rendimiento de agregaciones); recomendación en `13`.

No se registran en logs: claves, OTP, tokens, documentos de identidad, sueldos, contenido de formularios ni cuerpos de solicitudes.

## 7. Archivos

- Almacenamiento privado; nunca URL públicas. Ruta por organización con identificador opaco.
- Subida directa con URL firmada de corta vida, de un solo uso, con tipo y tamaño fijados.
- Verificación tras subir: tipo real, tamaño, antivirus, `sha256`. Hasta `ready` no se puede descargar.
- Descarga: se valida el permiso del recurso dueño → URL firmada de corta vida → `Content-Disposition: attachment` y dominio distinto del de la aplicación.
- **Control de descargas**: permiso de descarga separado del de ver; documentos sensibles con marca de agua (usuario, fecha) en la vista previa; registro de cada descarga; alerta por descargas masivas.
- Limpieza de metadatos en adjuntos de denuncias anónimas.
- Versiones inmutables; borrar es archivar hasta que la retención permita eliminar.
- Vistas previas generadas en servidor aislado (sin red).

## 8. Registros: tres almacenes distintos (C-07)

| Almacén | Contenido | Quién lo ve | Mutabilidad | Retención |
|---|---|---|---|---|
| **Auditoría de negocio** | Quién, qué acción, sobre qué registro, cuándo, desde dónde, valor anterior, valor nuevo, `request_id`, actor real si hubo suplantación o sesión de soporte | Administrador y Auditor de la organización (según permiso); cada persona ve lo suyo | Solo inserción (privilegios revocados); encadenado por hash para detectar manipulación | Política de la organización, con mínimo legal |
| **Registro de accesos a datos sensibles** | Quién leyó qué campos sensibles de qué registro, cuándo, por qué vía (pantalla, exportación, API, IA) | Administrador, oficial de datos; el titular puede pedirlo | Solo inserción | Igual |
| **Actividad del usuario** | Inicios de sesión, pantallas, acciones no sensibles | El propio usuario; RRHH según permiso | Solo inserción | Corta |
| **Logs técnicos** | Errores, trazas, rendimiento; sin datos personales | Equipo de plataforma | — | Corta |

Reglas de auditoría:

- Se audita: altas, cambios y ceses; sueldo; contratos; permisos y roles; estados de planilla; aprobaciones; firmas; exportaciones; configuración; parámetros; accesos de soporte; acciones de workflows y de IA; restauraciones y anonimizaciones.
- Los valores sensibles en antes y después se guardan cifrados; solo se muestran a quien tiene el permiso por campo.
- En el módulo de denuncias, la auditoría va a su propio almacén y **no** registra al denunciante anónimo.
- Lecturas sensibles en lote (listado, exportación) generan una fila por lote con el número de registros, no una por registro.
- Exportable para auditores externos.

## 9. Copias y restauración

- Copias automáticas de base (completa periódica + archivo continuo para recuperación a un punto en el tiempo) y de archivos (versionado del almacenamiento), cifradas y en ubicación separada.
- Copias manuales desde la consola SaaS antes de operaciones de riesgo.
- **Restauración probada** de forma periódica en un entorno aislado; una copia que no se ha restaurado no cuenta.
- Objetivos de punto y tiempo de recuperación: decisión A-13.
- **Restauración por organización**: no se restaura la base completa sobre producción. Se restaura a un entorno aislado y se reimporta la organización afectada con una herramienta que respeta `tenant_id` (A-13 fija el alcance: organización completa o recurso puntual).
- La restauración es una operación de plataforma con doble autorización, verificación reforzada y auditoría visible para el cliente.
- Las copias respetan la retención: lo eliminado por derecho de cancelación no se «resucita» al restaurar (lista de supresiones que se reaplica tras restaurar).
- La consola muestra historial, estado, retención y resultado de la última prueba de restauración.

## 10. Retención, anonimización y eliminación

- **Papelera**: borrar es soft delete; restauración con permiso; la información laboral crítica no se elimina de inmediato.
- **Políticas de retención** por tipo de dato, país y organización: plazo, acción al vencer (anonimizar, eliminar, archivar) y base legal. Plazos citados en `research.md` que el paquete de Perú trae como **parámetros** a validar: registro de asistencia 5 años; registros SST 10 años para accidentes e incidentes peligrosos, 20 para enfermedades ocupacionales y 5 para el resto; documentos de pago 5 años (citado de memoria en la fuente). El resto de plazos los define el área legal (D-15).
- **Retención manda sobre cancelación**: si una norma obliga a conservar, el dato se **bloquea** (se excluye del uso ordinario y solo se accede para la obligación) en lugar de eliminarse.
- **Anonimización** de una persona: sustituye identificadores directos (nombre, documento, contacto, foto, cuenta) y generaliza cuasi-identificadores; conserva lo necesario para estadística y obligaciones; irreversible; requiere que no existan relaciones activas ni obligaciones pendientes; acción con verificación reforzada.
- **Candidatos**: retención propia y más corta; consentimiento para permanecer en la base de talento; anonimización automática al vencer.
- **Encuestas anónimas**: no hay qué anonimizar porque no se guarda el vínculo.
- **Baja de una organización**: exportación completa en formato abierto → periodo de gracia → eliminación con certificado; las copias expiran por su propio ciclo (D-13).
- Trabajo programado de retención por organización, con informe de lo que se anonimizó o eliminó y simulación previa.
- **Exportación del titular** (portabilidad): paquete con sus datos en formato estructurado.

## 11. Ley 29733 y DS 016-2024-JUS: qué exige al sistema

Según `research.md` §4 (fuente secundaria): reglamento vigente desde el 30-03-2025. Requisitos de producto (E-16):

| Exigencia | Qué debe hacer el sistema |
|---|---|
| Consentimiento y finalidad por tratamiento | Registro de consentimientos por persona: finalidad, versión del texto, fecha, canal, revocación. Textos versionados. Tratamientos que no requieren consentimiento (ejecución del contrato, obligación legal) documentados con su base |
| Deber de información | Aviso de privacidad por organización, mostrado al crear la cuenta, al postular y antes de capturar biometría o geolocalización |
| Derechos de acceso, rectificación, cancelación, oposición y portabilidad | Bandeja de solicitudes del titular con plazo, responsable, estado y respuesta; exportación de datos del titular; rectificación por autoservicio con aprobación; cancelación sujeta a retención legal |
| Inscripción de bancos de datos | Inventario de bancos de datos y tratamientos por razón social (finalidad, categorías, destinatarios, encargados, transferencias, medidas) exportable para el trámite |
| Notificación de incidentes en 48 horas | Registro de incidentes con hora de detección, cuenta regresiva, afectados, medidas, constancia de notificación; alerta a los responsables. El plazo es un parámetro |
| Oficial de datos personales | Rol y datos de contacto por organización; destinatario de alertas de privacidad |
| Evaluación de impacto para biometría y perfilado con IA | Registro de evaluaciones de impacto por tratamiento; **bloqueo**: no se puede activar marcación facial ni funciones de perfilado sin una evaluación registrada y aprobada |
| Transferencia internacional | Inventario de encargados y países (alojamiento, correo, WhatsApp, IA, firma); garantías registradas; aviso al titular. Condiciona A-08 y A-09 |
| Cifrado y control de acceso | §5 y §6 de este documento |
| Encargados de tratamiento | Lista de proveedores por conexión activa, con qué datos reciben |

Ayni HR actúa como **encargado** del tratamiento de las organizaciones clientes (que son las titulares de los bancos de datos) y como titular respecto de los datos de la propia plataforma; el contrato y los avisos deben reflejarlo (D-15).

## 12. Biometría y geolocalización

- **Principio**: la plataforma no almacena plantillas biométricas. En relojes, la plantilla vive en el dispositivo y Ayni HR recibe el evento de marcación.
- **Selfie de marcación**: se guarda como evidencia con retención corta y acceso restringido (`biometric.view`); sin reconocimiento facial salvo que la organización lo active.
- **Reconocimiento facial o detección de vida** (opcional, D-16): requiere evaluación de impacto aprobada, consentimiento informado y **método alternativo** para quien no consienta (PIN, QR); el proveedor entrega resultado (coincide o no), no la plantilla.
- **Geolocalización**: solo en el instante de marcar; nunca seguimiento continuo; precisión y punto guardados con la marcación; aviso visible.
- Las señales de riesgo de marcación (fuera de geocerca, dispositivo nuevo, reloj del dispositivo alterado) se muestran con explicación y nunca sancionan por sí solas.
- Datos biométricos y de ubicación: clase sensible, permiso por campo, registro de accesos.

## 13. Gobierno de IA (C-08)

- **Bitácora de cada uso**: quién, caso de uso, proveedor y modelo, qué datos se consultaron, herramientas llamadas, entrada y salida (cifradas, con retención propia).
- **Respeta permisos**: herramientas ejecutadas como el usuario; mismo alcance y permisos por campo.
- **Sin decisiones automáticas de alto impacto**: contratar, descartar candidatos, cesar, sancionar, promover y cambiar sueldos los decide una persona. La IA produce borradores, resúmenes y sugerencias marcados como tales.
- **Explicación obligatoria** en alertas, anomalías y puntajes: factores visibles y datos de origen.
- **Datos excluidos**: denuncias, casos disciplinarios, salud, biometría y afiliación sindical nunca se envían a un proveedor de IA.
- **Minimización** antes de enviar; sin entrenamiento de modelos de terceros con datos de clientes (condición contractual, A-09).
- **Contenido de terceros** (CV, comentarios) se trata como dato no confiable.
- **Evaluación de impacto** registrada antes de activar funciones de perfilado (resumen y ordenamiento de candidatos, riesgo de rotación).
- **Selección**: los resúmenes de CV no incluyen ni infieren sexo, edad, origen, estado civil ni salud; cualquier ordenamiento muestra criterios y admite revisión.
- **Control por organización**: la IA es un módulo; se puede apagar por organización y por caso de uso; cuota de uso.
- **Derecho a revisión humana**: toda salida de IA que afecte a una persona puede ser impugnada y revisada.
- Agentes externos por MCP: mismas reglas, identidad por token, lista cerrada de herramientas.

## 14. Canal de denuncias (C-06, E-15)

- Formulario público accesible sin sesión, en dominio o ruta separada; identificada o **anónima**.
- Anónima: no se guarda usuario, relación laboral, IP, agente ni cookie; el servidor web excluye esas rutas del registro de accesos; adjuntos sin metadatos.
- **Código de seguimiento** generado de forma aleatoria, mostrado una sola vez y guardado con hash; con él el denunciante consulta el estado y conversa con el investigador sin identificarse.
- Acceso: solo investigadores **asignados al caso**; ni el administrador ni el soporte de plataforma. Conflicto de interés: si el denunciado es investigador, el caso no se le muestra ni se le puede asignar.
- Cifrado con clave propia; sin búsqueda global, sin analítica individual, sin IA.
- Trazabilidad sin comprometer el anonimato: auditoría de las acciones de los investigadores en almacén separado.
- Hostigamiento sexual (Ley 27942, según `research.md`): comité de intervención o delegado según tamaño de la empresa; medidas de protección; plazos del procedimiento como **parámetros** (la fuente da cifras distintas para la duración total: validar con el reglamento); capacitación al ingreso y anual enlazada con Aprendizaje.
- Alertas de plazo al investigador, nunca con contenido del caso en la notificación.
- Protección frente a represalias: el sistema no muestra al denunciado quién denunció ni permite inferirlo por permisos.

## 15. Operación segura

- Entornos separados; datos reales solo en producción; preproducción con datos sintéticos.
- Secretos en un gestor de secretos; nada en el repositorio.
- Acceso de operaciones a producción: mínimo, nominal, con MFA, registrado.
- Suplantación de usuario por soporte: solo dentro de una sesión de soporte, con aviso visible en la interfaz y actor real en la auditoría.
- Análisis de dependencias y de secretos en cada cambio; prueba de intrusión antes de la salida a producción y de forma periódica (D-24 fija certificaciones objetivo).
- Plan de respuesta a incidentes enlazado con el registro de incidentes de privacidad (§11).
- Divulgación responsable: dirección de contacto de seguridad publicada.

## 16. Lista de verificación por historia

- [ ] ¿Qué clase de datos toca? ¿Algún campo nuevo es sensible? → permiso por campo, cifrado, registro de acceso, exclusión de búsqueda.
- [ ] ¿Policy y alcance en cada ruta?
- [ ] ¿Entradas validadas con lista blanca? ¿HTML saneado?
- [ ] ¿Archivos por URL firmada y con verificación?
- [ ] ¿Auditoría con antes y después?
- [ ] ¿Sale algún dato a un tercero? → adaptador, minimización, inventario de encargados.
- [ ] ¿Requiere verificación reforzada?
- [ ] ¿Logs sin datos personales?
- [ ] ¿Tiene política de retención?
