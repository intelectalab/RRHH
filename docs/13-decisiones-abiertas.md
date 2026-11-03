# 13 · Decisiones abiertas

Decisiones que el negocio o el área de desarrollo deben cerrar. Mientras una decisión esté abierta, el código sigue la **recomendación** detrás de una interfaz o parámetro que permita cambiarla, y Claude Code debe detenerse y preguntar si una historia depende de ella de forma irreversible.

- **A-xx**: arquitectura (las cierra el área de desarrollo).
- **D-xx**: producto, negocio o legal.
- «Cerrar antes de» indica la fase cuyo inicio queda bloqueado por la decisión.
- Al cerrar una decisión: se registra en `docs/adr/` y se marca aquí como cerrada con la fecha y la opción elegida.

## Resumen

| Código | Decisión | Cerrar antes de / Estado |
|---|---|---|
| A-01 | Stack tecnológico | **Cerrada (2026-11-02)** · [ADR 0001](adr/0001-A-01-stack-tecnologico.md) |
| A-02 | Modelo de aislamiento multiempresa | **Cerrada (2026-11-02)** · [ADR 0002](adr/0002-A-02-aislamiento-multiempresa.md) |
| A-03 | Tipo de identificador | **Cerrada (2026-11-02)** · [ADR 0003](adr/0003-A-03-tipo-de-identificador.md) |
| A-04 | Estrategia de sesión y autenticación entre web y API | **Cerrada (2026-11-02)** · [ADR 0004](adr/0004-A-04-sesion-y-autenticacion-web-api.md) |
| A-05 | Motor de búsqueda | Fase 1 (revisable en fase 8) |
| A-06 | Particionado de tablas grandes | Fase 2 |
| A-07 | Réplica de lectura y almacén analítico | Fase 8 |
| A-08 | Región de alojamiento y transferencia internacional | **Cerrada (2026-11-02)** · [ADR 0005](adr/0005-A-08-region-alojamiento-transferencia-internacional.md) |
| A-09 | Proveedor de IA y condiciones de datos | Fase 8 |
| A-10 | Cifrado de campo para sueldos | Fase 1 |
| A-11 | Generación de PDF | Fase 1 |
| A-12 | App móvil: web instalable o nativa | Fase 2 |
| A-13 | Objetivos de recuperación y restauración por organización | Fase 0 (salida a producción) |
| A-14 | Lenguaje de fórmulas del motor de nómina | Fase 3 |
| D-01 | Composición de planes y módulos | **Cerrada (2026-11-02)** · [ADR 0006](adr/0006-D-01-composicion-de-planes-y-modulos.md) |
| D-02 | Nombre y marca definitivos | Fase 0 (salida a producción) |
| D-03 | Qué cuenta para el límite de colaboradores | **Cerrada (2026-11-02)** · [ADR 0007](adr/0007-D-03-limite-de-colaboradores.md) |
| D-04 | Cobro de la suscripción | **Cerrada (2026-11-02)** · [ADR 0008](adr/0008-D-04-cobro-de-suscripcion.md) |
| D-05 | Política «no contratar sin posición aprobada» | Fase 1 |
| D-06 | Regímenes laborales del primer lanzamiento | Fase 3 |
| D-07 | Reapertura de planilla cerrada y retroactivos | Fase 3 |
| D-08 | MFA obligatorio: para quién | **Cerrada (2026-11-02)** · [ADR 0009](adr/0009-D-08-mfa-obligatorio.md) |
| D-09 | Presentación ante SUNAT y AFPnet | Fase 3 |
| D-10 | Firma electrónica: validez por tipo de documento | Fase 1 |
| D-11 | Proveedor de WhatsApp y alcance del autoservicio | Fase 9 (notificaciones: fase 0) |
| D-12 | Bancos, billeteras y ERP del primer lanzamiento | Fase 3 |
| D-13 | Datos al desactivar un módulo o dar de baja una organización | **Cerrada (2026-11-02)** · [ADR 0010](adr/0010-D-13-datos-desactivar-modulo-o-baja.md) |
| D-14 | Privacidad: qué va en la base y qué en el módulo de cumplimiento | Fase 1 |
| D-15 | Validación legal de plazos, retenciones y rol de encargado | Fase 1 (y antes de cada fase con norma) |
| D-16 | Biometría y reconocimiento facial en marcación | Fase 2 |
| D-17 | Eventos públicos: `employee.created` frente a `employee.hired` | Fase 1 |
| D-18 | Política de versiones y retirada de la API | Fase 9 |
| D-19 | Límites de uso de API por plan | Fase 9 |
| D-20 | Método del riesgo de rotación | Fase 8 |
| D-21 | Herramientas del servidor MCP | Fase 9 |
| D-22 | Presupuestos de rendimiento | Fase 1 |
| D-23 | Acceso del colaborador tras el cese | Fase 1 |
| D-24 | Certificaciones de seguridad objetivo | Fase 0 (salida a producción) |
| D-25 | Adelanto de sueldo: quién financia y con qué tope | Fase 3 |
| D-26 | Anonimato: umbral mínimo y denuncias anónimas | Fase 6 (encuestas) y fase 7 (denuncias) |
| D-27 | Responsable y proceso de actualización de parámetros legales | Fase 3 |
| D-28 | Modo estudio contable: propiedad de los datos y permisos del socio | Fase 9 |
| D-29 | Idioma de los códigos de permiso | **Cerrada (2026-11-02)** · [ADR 0011](adr/0011-D-29-idioma-codigos-de-permiso.md) |
| D-30 | Ubicación de los códigos SUNAT derivables (perfil T-Registro) | Fase 3 |
| D-31 | Hora en los plazos y en el libro de una denuncia | Fase 7 |
| D-32 | Bitácora del caso de denuncia: `complaint_events` frente al libro encadenado | Fase 7 |
| D-33 | Comités por razón social o por centro de trabajo, y paridad | Fase 7 |
| D-34 | Sello de tiempo externo y forma canónica del libro de cumplimiento | Fase 7 |
| D-35 | Enumerados ampliados de documento, derechohabiente y novedad del registro laboral | Fase 3 |
| D-36 | Qué fuente secundaria basta para marcar un parámetro legal como verificado | Fase 3 |
| D-37 | Plantillas de obligaciones de cumplimiento del paquete Perú | Fase 7 |

---

## Arquitectura

### A-01 · Stack tecnológico
- **Estado**: **Cerrada (2026-11-02)** · Opción elegida: (a) · [ADR 0001](adr/0001-A-01-stack-tecnologico.md).
- **Opciones**: (a) Laravel 12 / PHP 8.4 + PostgreSQL 17 + Redis + Next.js 15, igual que los demás kits del área; (b) otro stack.
- **Recomendación**: (a). Reutiliza conocimiento y herramientas del área; PostgreSQL aporta RLS, exclusiones de rango y `numeric`, que este diseño usa como garantías.
- **Cerrar antes de**: fase 0. Afecta a todo el kit.

### A-02 · Modelo de aislamiento multiempresa
- **Estado**: **Cerrada (2026-11-02)** · Opción elegida: (a) · [ADR 0002](adr/0002-A-02-aislamiento-multiempresa.md).
- **Opciones**: (a) base y esquema compartidos con `tenant_id` + RLS + FK compuestas; (b) un esquema por organización; (c) una base por organización; (d) híbrido: (a) por defecto y base dedicada para clientes grandes.
- **Recomendación**: (a), dejando la resolución de conexión detrás de `TenantContext` para poder ofrecer (d) más adelante sin tocar los módulos.
- **Cerrar antes de**: fase 0.

### A-03 · Tipo de identificador
- **Estado**: **Cerrada (2026-11-02)** · Opción elegida: (a) · [ADR 0003](adr/0003-A-03-tipo-de-identificador.md).
- **Opciones**: (a) UUID v7 (ordenable por tiempo); (b) entero grande secuencial + identificador público opaco; (c) ULID.
- **Recomendación**: (a). No expone volumen, se genera en cliente (útil para marcación sin conexión e idempotencia) y mantiene localidad en índices. La API trata los identificadores como texto opaco en cualquier caso.
- **Cerrar antes de**: fase 0. Debe coincidir con `04-modelo-de-datos.md`.

### A-04 · Sesión y autenticación entre web y API
- **Estado**: **Cerrada (2026-11-02)** · Opción elegida: (a) · [ADR 0004](adr/0004-A-04-sesion-y-autenticacion-web-api.md).
- **Opciones**: (a) cookie de sesión del backend en el mismo dominio raíz (Next.js como interfaz, API detrás de un proxy); (b) tokens en el navegador; (c) Next.js como intermediario que guarda la sesión y llama a la API.
- **Recomendación**: (a). Cookie `HttpOnly` sin tokens accesibles a JavaScript; un solo origen simplifica CSRF y CSP.
- **Cerrar antes de**: fase 0.

### A-05 · Motor de búsqueda
- **Opciones**: (a) PostgreSQL (`pg_trgm` + texto completo) sobre tabla de proyección; (b) motor externo dedicado.
- **Recomendación**: (a) al inicio, detrás de `SearchProvider`; pasar a (b) solo si las pruebas de carga lo exigen.
- **Cerrar antes de**: fase 1; revisar en fase 8.

### A-06 · Particionado de tablas grandes
- **Opciones**: (a) particionado declarativo por rango de fecha desde el inicio en marcaciones, asistencia, resultados de planilla, auditoría, accesos, outbox, entregas y notificaciones; (b) sin particionar y migrar cuando haga falta.
- **Recomendación**: (a). Particionar después, con datos, es mucho más costoso; además facilita la retención (se descartan particiones).
- **Cerrar antes de**: fase 2 (auditoría y outbox, antes de la fase 0).

### A-07 · Réplica de lectura y almacén analítico
- **Opciones**: (a) tablas de agregados en la misma base; (b) réplica de lectura para reportes; (c) almacén analítico separado.
- **Recomendación**: (a) hasta la fase 8, con las consultas de analítica detrás de una interfaz; (b) cuando el constructor de reportes entre en uso; (c) solo con evidencia de carga.
- **Cerrar antes de**: fase 8.

### A-08 · Región de alojamiento y transferencia internacional
- **Estado**: **Cerrada (2026-11-02)** · Opción elegida: (a) · [ADR 0005](adr/0005-A-08-region-alojamiento-transferencia-internacional.md).
- **Opciones**: (a) región en el extranjero con garantías de transferencia documentadas; (b) alojamiento en Perú; (c) elección por organización.
- **Recomendación**: (a) con inventario de encargados y garantías registradas (lo exige el reglamento de datos personales, ver `12` §11), manteniendo el diseño apto para (c). Requiere validación legal (D-15).
- **Cerrar antes de**: fase 0.

### A-09 · Proveedor de IA y condiciones de datos
- **Opciones**: (a) un proveedor único de plataforma; (b) proveedor elegible por organización; (c) modelo alojado por la propia plataforma.
- **Recomendación**: (a) al inicio, tras el puerto `AiProvider`, con condiciones contractuales de no entrenamiento y retención mínima; habilitar (b) para planes altos.
- **Cerrar antes de**: fase 8.

### A-10 · Cifrado de campo para sueldos
- **Opciones**: (a) cifrar sueldos y montos de planilla a nivel de campo (impide agregaciones en SQL); (b) no cifrar a nivel de campo y proteger con RLS, permiso por campo, registro de accesos y cifrado en reposo; (c) cifrar la compensación pactada y dejar en claro los resultados de planilla.
- **Recomendación**: (b). La planilla, las provisiones y la analítica necesitan sumar en la base; el control efectivo es el permiso por campo y el registro de accesos. Documento de identidad, cuenta bancaria, salud y denuncias sí van cifrados a nivel de campo.
- **Cerrar antes de**: fase 1.

### A-11 · Generación de PDF
- **Opciones**: (a) HTML a PDF con navegador sin interfaz en un servicio aislado; (b) librería nativa de PHP; (c) servicio externo.
- **Recomendación**: (a) tras el puerto `PdfRenderer`: misma maquetación que la vista previa y buen soporte tipográfico. El servicio corre sin red y sin acceso a datos.
- **Cerrar antes de**: fase 1.

### A-12 · App móvil
- **Opciones**: (a) aplicación web instalable (PWA) con marcación sin conexión; (b) app nativa; (c) PWA primero y envoltorio nativo después para notificaciones push y cámara en dispositivos que lo requieran.
- **Recomendación**: (c). La API ya lo permite; la PWA cubre los flujos del colaborador.
- **Cerrar antes de**: fase 2.

### A-13 · Objetivos de recuperación y restauración por organización
- **Opciones**: definir punto y tiempo objetivo de recuperación; restauración (a) solo de plataforma completa, (b) por organización, (c) por recurso puntual.
- **Recomendación**: recuperación a un punto en el tiempo + (b) mediante exportación e importación por `tenant_id` desde un entorno aislado. (c) se cubre con papelera e historia con vigencia.
- **Cerrar antes de**: salida a producción de la fase 0.

### A-14 · Lenguaje de fórmulas del motor de nómina
- **Opciones**: (a) lenguaje de expresiones propio con evaluador y funciones del motor; (b) reglas en código por concepto; (c) motor de reglas de terceros.
- **Recomendación**: (a). Los conceptos son datos con vigencia que RRHH puede crear; (b) impide conceptos personalizados y obliga a desplegar por cada cambio normativo. Mantener la gramática pequeña y sin efectos secundarios.
- **Cerrar antes de**: fase 3.

---

## Producto, negocio y legal

### D-01 · Composición de planes y módulos
- **Estado**: **Cerrada (2026-11-02)** · Opción elegida: (c) · [ADR 0006](adr/0006-D-01-composicion-de-planes-y-modulos.md).
- **Opciones**: (a) planes cerrados por tamaño; (b) base + módulos sueltos; (c) planes con módulos incluidos y complementos.
- **Recomendación**: (c). La arquitectura ya trata planes, módulos, límites y complementos como datos (`01` §4). El negocio debe confirmar la lista de módulos comerciales y cuáles son siempre incluidos (hoy: Plataforma base y Core HR).
- **Cerrar antes de**: fase 0.

### D-02 · Nombre y marca definitivos
- **Opciones**: mantener «Ayni HR» o cambiarlo.
- **Recomendación**: decidir antes de publicar dominios, correos y tiendas. En código, el nombre vive solo en configuración y textos.
- **Cerrar antes de**: salida a producción de la fase 0.

### D-03 · Qué cuenta para el límite de colaboradores
- **Estado**: **Cerrada (2026-11-02)** · Opción elegida: (b) · [ADR 0007](adr/0007-D-03-limite-de-colaboradores.md).
- **Opciones**: (a) relaciones laborales activas; (b) personas únicas activas; (c) relaciones con boleta en el periodo; ¿cuentan practicantes, locadores, pre-ingresos y cesados con acceso?
- **Recomendación**: (b) personas únicas con al menos una relación activa; locadores y pre-ingresos no cuentan; cesados no cuentan.
- **Cerrar antes de**: fase 0.

### D-04 · Cobro de la suscripción
- **Estado**: **Cerrada (2026-11-02)** · Opción elegida: (c) · [ADR 0008](adr/0008-D-04-cobro-de-suscripcion.md).
- **Opciones**: (a) registro manual del estado de cuenta en la consola; (b) pasarela de pagos integrada; (c) (a) primero y (b) después.
- **Recomendación**: (c), con el cobro tras un puerto. La consola registra plan, ciclo, estado y suspensión desde la fase 0.
- **Cerrar antes de**: fase 0.

### D-05 · Política «no contratar sin posición aprobada»
- **Opciones**: (a) siempre obligatoria; (b) configurable por organización; (c) aviso sin bloqueo.
- **Recomendación**: (b), apagada por defecto para organizaciones pequeñas; cuando está apagada, el alta crea la posición de forma implícita para que el organigrama por posiciones siga funcionando.
- **Cerrar antes de**: fase 1.

### D-06 · Regímenes laborales del primer lanzamiento
- **Opciones**: general; microempresa; pequeña empresa; construcción civil; agrario; modalidades formativas.
- **Recomendación**: general + microempresa + pequeña empresa + modalidades formativas primero; construcción civil y agrario después. El motor admite todos; lo que se decide es el contenido del paquete y sus casos dorados.
- **Cerrar antes de**: fase 3.

### D-07 · Reapertura de planilla cerrada y retroactivos
- **Opciones**: (a) nunca reabrir: todo ajuste va al siguiente periodo; (b) reabrir con permiso especial mientras no se haya declarado ni pagado; (c) reabrir siempre con rectificatoria.
- **Recomendación**: (b). Tras declarar o pagar, solo ajustes en el siguiente periodo y archivo rectificatorio.
- **Cerrar antes de**: fase 3.

### D-08 · MFA obligatorio
- **Estado**: **Cerrada (2026-11-02)** · Opción elegida: (b) · [ADR 0009](adr/0009-D-08-mfa-obligatorio.md).
- **Opciones**: (a) opcional; (b) obligatorio para roles con datos sensibles; (c) obligatorio para todos.
- **Recomendación**: (b), con (c) como política activable por organización. Para personal sin correo, el segundo factor es OTP al teléfono verificado.
- **Cerrar antes de**: fase 0.

### D-09 · Presentación ante SUNAT y AFPnet
- **Opciones**: (a) generar y validar archivos; la empresa presenta; (b) presentación automatizada.
- **Recomendación**: (a). El sistema registra la constancia de presentación que carga el usuario.
- **Cerrar antes de**: fase 3.

### D-10 · Firma electrónica: validez por tipo de documento
- **Opciones**: (a) firma con evidencia propia para todo; (b) firma con certificado por proveedor para todo; (c) por tipo de documento.
- **Recomendación**: (c): evidencia propia por defecto (boletas, constancias, políticas, EPP) y proveedor con certificado donde legal lo indique. Qué documento exige qué nivel lo define el área legal (D-15).
- **Cerrar antes de**: fase 1.

### D-11 · WhatsApp
- **Opciones**: proveedor (API oficial directa o intermediario); alcance: (a) solo notificaciones; (b) autoservicio completo (boletas, vacaciones, constancias, marcación).
- **Recomendación**: notificaciones desde la fase 0 tras el puerto; autoservicio (b) en la fase 9. Elegir proveedor según requisitos de plantilla, alojamiento de datos y condiciones.
- **Cerrar antes de**: fase 0 para notificaciones; fase 9 para autoservicio.

### D-12 · Bancos, billeteras y ERP del primer lanzamiento
- **Opciones**: lista de bancos para archivo de pago de sueldos y CTS; billeteras; ERP o formato contable.
- **Recomendación**: empezar por archivo (no por conexión en línea) para los bancos con más clientes del mercado objetivo y un formato contable genérico (CSV configurable). El negocio entrega la lista priorizada.
- **Cerrar antes de**: fase 3.

### D-13 · Datos al desactivar un módulo o dar de baja una organización
- **Estado**: **Cerrada (2026-11-02)** · Opción elegida: solo lectura con exportación; baja con exportación, periodo de gracia y eliminación certificada · [ADR 0010](adr/0010-D-13-datos-desactivar-modulo-o-baja.md).
- **Opciones**: módulo: (a) solo lectura indefinida; (b) solo lectura durante un periodo y luego archivo. Organización: periodo de gracia y forma de entrega de los datos.
- **Recomendación**: módulo en solo lectura con exportación disponible; baja de organización con exportación completa, periodo de gracia y eliminación certificada. Los plazos los fija el negocio con legal.
- **Cerrar antes de**: fase 0.

### D-14 · Privacidad: base o módulo
- **Opciones**: (a) todo en la base; (b) todo en el módulo de cumplimiento; (c) mínimos legales en la base y gestión avanzada en el módulo.
- **Recomendación**: (c). Base: avisos, consentimientos, derechos del titular, registro de accesos, retención. Módulo: inventario de tratamientos, evaluaciones de impacto, registro de incidentes con seguimiento, informes.
- **Cerrar antes de**: fase 1.

### D-15 · Validación legal
- **Qué**: `research.md` marca como no verificados varios datos (plazos de hostigamiento, conservación de documentos de pago, umbrales de comités, lactario y cuota de discapacidad, porcentajes de EPS, tramos de renta de quinta, comisiones de AFP, estructura de archivos oficiales). También el rol de encargado de tratamiento y los textos de avisos y consentimientos.
- **Opciones**: (a) validación por asesor laboral y de datos personales antes de cada fase; (b) validación única al final.
- **Recomendación**: (a). Cada valor entra como parámetro con estado «por validar» y no puede usarse en producción hasta que lo valide el responsable (D-27).
- **Cerrar antes de**: fase 1 para privacidad y firma; fase 2 para asistencia; fase 3 para planilla; fase 7 para SST, hostigamiento y cumplimiento.

### D-16 · Biometría y reconocimiento facial
- **Opciones**: (a) sin reconocimiento facial: selfie como evidencia; (b) detección de vida por proveedor; (c) reconocimiento facial completo.
- **Recomendación**: (a) al inicio; (b) opcional por organización, con evaluación de impacto aprobada, consentimiento y método alternativo. Evitar (c) mientras no haya una necesidad clara.
- **Cerrar antes de**: fase 2.

### D-17 · Eventos públicos de alta
- **Opciones**: (a) solo `employee.hired`; (b) `employee.created` (al crear la ficha) y `employee.hired` (al activarse la relación) como eventos distintos; (c) `employee.created` como alias de `employee.hired`.
- **Recomendación**: (c), porque el pedido original nombra ambos y las integraciones suelen esperar `employee.created`. Si se necesita reaccionar al pre-ingreso, usar `offer.accepted`.
- **Cerrar antes de**: fase 1.

### D-18 · Política de versiones y retirada de la API
- **Opciones**: tiempo de convivencia entre versiones mayores; canal de aviso.
- **Recomendación**: publicar la política antes de abrir la API a terceros; avisar con cabeceras `Deprecation` y `Sunset` y por correo a los dueños de tokens.
- **Cerrar antes de**: fase 9.

### D-19 · Límites de uso de API por plan
- **Opciones**: límites fijos; por plan; por token.
- **Recomendación**: por plan, como parámetros de plataforma, con límites más estrictos en rutas públicas, autenticación e IA.
- **Cerrar antes de**: fase 9 (las rutas de autenticación y públicas llevan límite desde la fase 0).

### D-20 · Método del riesgo de rotación
- **Opciones**: (a) reglas e indicadores visibles con pesos configurables; (b) modelo estadístico entrenado con datos de la organización; (c) no ofrecerlo.
- **Recomendación**: (a). Es explicable, no requiere volumen de datos y encaja con la evaluación de impacto. Nunca dispara acciones automáticas.
- **Cerrar antes de**: fase 8.

### D-21 · Herramientas del servidor MCP
- **Opciones**: (a) solo lectura; (b) lectura + escrituras de bajo impacto del propio usuario; (c) todo lo que permite la API.
- **Recomendación**: (b), lista cerrada y versionada; nada de alto impacto.
- **Cerrar antes de**: fase 9.

### D-22 · Presupuestos de rendimiento
- **Qué**: latencia objetivo de listados, ficha y marcación; duración máxima del cálculo de planilla para el tamaño máximo; marcaciones por segundo en pico.
- **Recomendación**: fijarlos como umbrales de la prueba de carga (`11` §11) antes de construir la fase 1, para que el diseño de índices y proyecciones se mida contra ellos.
- **Cerrar antes de**: fase 1.

### D-23 · Acceso del colaborador tras el cese
- **Opciones**: (a) sin acceso; (b) acceso limitado por un periodo a boletas, certificados y liquidación; (c) acceso indefinido de solo lectura.
- **Recomendación**: (b), con duración configurable por organización; no cuenta para el límite del plan.
- **Cerrar antes de**: fase 1.

### D-24 · Certificaciones de seguridad objetivo
- **Opciones**: ninguna al inicio; ISO 27001; ISO 27701; otras. Varios competidores las exhiben (`research.md`).
- **Recomendación**: diseñar los controles desde la fase 0 conforme a `12` y decidir el calendario de certificación con el negocio.
- **Cerrar antes de**: salida a producción de la fase 0.

### D-25 · Adelanto de sueldo
- **Opciones**: (a) lo financia la empresa cliente; (b) un tercero financiero; tope sobre lo devengado; canal de pago (banco, billetera).
- **Recomendación**: (a) al inicio: adelanto sobre lo ya devengado, tope por política de la organización como parámetro, descuento automático en el periodo. (b) implica regulación financiera y debe evaluarse aparte.
- **Cerrar antes de**: fase 3.

### D-26 · Anonimato
- **Opciones**: umbral mínimo de respuestas por segmento (valor por defecto y mínimo permitido); si las denuncias anónimas se admiten para todos los tipos de caso.
- **Recomendación**: umbral como parámetro con un mínimo que la organización no puede bajar; denuncias anónimas admitidas, con aviso al denunciante de sus límites. Validar con legal (D-15).
- **Cerrar antes de**: fase 6 (encuestas) y fase 7 (denuncias).

### D-27 · Actualización de parámetros legales
- **Opciones**: (a) la plataforma mantiene los parámetros del paquete y las organizaciones los reciben; (b) cada organización los mantiene; (c) mixto.
- **Recomendación**: (c): parámetros legales nacionales mantenidos por la plataforma con un responsable nombrado, doble revisión, fuente normativa obligatoria y aviso a las organizaciones; parámetros de política propia mantenidos por la organización. Definir quién es el especialista que valida y mantiene los casos dorados.
- **Cerrar antes de**: fase 3.

### D-28 · Modo estudio contable
- **Opciones**: (a) cada cliente del estudio es una organización propia y el estudio accede como socio; (b) el estudio es una organización y sus clientes son razones sociales.
- **Recomendación**: (a). Mantiene el aislamiento y permite que el cliente conserve sus datos si cambia de estudio. Definir qué permisos tiene el socio por defecto y quién es titular de la suscripción.
- **Cerrar antes de**: fase 9; si el segmento es prioritario, antes de la fase 3.

### D-29 · Idioma de los códigos de permiso
- **Estado**: **Cerrada (2026-11-02)** · Opción elegida: (c) · [ADR 0011](adr/0011-D-29-idioma-codigos-de-permiso.md).
- **Qué**: `api/openapi.yaml` y `08-api.md` usan códigos en inglés alineados con el recurso de la API (`payroll.periods.close`, `people.employments.salary.view`). El prototipo y algunos ejemplos del backlog los escriben en español (`planilla.periodo.cerrar`, `colaborador.sueldo.ver`).
- **Opciones**: (a) inglés, igual que rutas, tablas y eventos; (b) español, igual que el prototipo; (c) código en inglés con etiqueta traducida en la pantalla de roles.
- **Recomendación**: (c). El código es un identificador técnico que viaja en la API y en tokens; lo que lee el administrador es la etiqueta i18n. Al cerrar, unificar `07-permisos-y-roles.md`, el backlog y el generador de OpenAPI (`x-permission` sale de `kit/tools/gen_openapi.py`).
- **Cerrar antes de**: fase 0 (la primera historia de SEG fija el catálogo).

### D-30 · Ubicación de los códigos SUNAT derivables (perfil T-Registro)
- **Qué**: `peruvian_employee_profiles` guarda como «lo declarado» códigos que también pueden derivarse del núcleo (tipo de trabajador, tipo de contrato, periodicidad, régimen laboral, indicador sindical, EPS). `employment_labor_regimes.has_risk_insurance` convive con `sctr_health` y `sctr_pension` del perfil.
- **Opciones**: (a) guardar lo declarado y conciliarlo con lo derivado (aplicado); (b) derivar siempre y no guardar; (c) llevar los códigos a los catálogos del núcleo (`contract_types`, `health_providers`).
- **Recomendación**: (a). Lo declarado es lo que dispara la novedad de modificación; `has_risk_insurance` queda como indicador derivado. Detalle en `18-localizacion-peru-detalle-tecnico.md` §0.2.
- **Cerrar antes de**: fase 3.

### D-31 · Hora en los plazos y en el libro de una denuncia
- **Qué**: la tabla `complaints` no guarda marcas de hora (C-06). El documento 18 proponía `received_at`; no se añadió. El disparador del libro trunca a día `occurred_at` y `recorded_at` de los asientos cuyo sujeto es una denuncia, y los plazos de hostigamiento se computan por día.
- **Opciones**: (a) solo fecha en todo lo que toca a una denuncia (aplicado); (b) guardar la hora solo en denuncias identificadas; (c) guardar siempre la hora.
- **Recomendación**: (a). Los plazos del reglamento están en días, no en horas. `compliance_deadlines` conserva sus marcas de tiempo técnicas: definir si para sujetos «denuncia» también deben truncarse.
- **Cerrar antes de**: fase 7.

### D-32 · Bitácora del caso de denuncia
- **Qué**: `complaint_events` (inalterable, sin encadenar) y `compliance_ledger_entries` (encadenado) registran actos del mismo expediente.
- **Opciones**: (a) mantener ambas: `complaint_events` para accesos y cambios de estado generales, el libro para el procedimiento de hostigamiento (aplicado); (b) migrar todo al libro y dejar `complaint_events` como vista; (c) encadenar `complaint_events`.
- **Recomendación**: (b) al implementar DEN, para no tener dos bitácoras. Hasta entonces rige (a).
- **Cerrar antes de**: fase 7.

### D-33 · Comités por razón social o por centro de trabajo, y paridad
- **Qué**: se añadió `site_id` opcional a `sst_committees` y `harassment_committees`, el tipo `subcommittee` y el estado `in_election` en SST, e `is_hr_representative` en los miembros del comité de intervención. La paridad de género no tiene restricción en base: se valida en la aplicación con `people.sex`.
- **Opciones**: (a) validación en aplicación (aplicado); (b) columna propia de paridad en el miembro; (c) restricción diferida en base.
- **Recomendación**: (a), con prueba automática. Confirmar con el especialista si el umbral de hostigamiento se evalúa por centro de trabajo o por empresa cuando hay varios centros pequeños.
- **Cerrar antes de**: fase 7.

### D-34 · Sello de tiempo externo y forma canónica del libro de cumplimiento
- **Qué**: el libro encadena huellas SHA-256 sobre un JSON canónico que envía la aplicación; `compliance_ledger_anchors` admite un sello de una autoridad de sellado de tiempo.
- **Opciones**: forma canónica RFC 8785 frente a la serialización de PostgreSQL; con o sin proveedor de sellado (RFC 3161); frecuencia del ancla.
- **Recomendación**: RFC 8785 en la aplicación, ancla diaria y al exportar, proveedor detrás de un adaptador con implementación falsa. Sin proveedor, el libro prueba orden e integridad, no fecha cierta ante terceros.
- **Cerrar antes de**: fase 7.

### D-35 · Enumerados ampliados del registro laboral
- **Qué**: se ampliaron `person_documents.doc_type` (carné de solicitante de refugio, carné de Relaciones Exteriores, documento de identidad extranjero), `dependents.relationship` (gestante, hijo mayor incapacitado) y `registry_events.kind` (modificación de derechohabiente, de establecimiento y de periodo). `sites.establishment_code` exige 4 dígitos cuando el país es Perú.
- **Opciones**: (a) enumerados en el núcleo (aplicado); (b) catálogo por país.
- **Recomendación**: (a) mientras Perú sea el único paquete; revisar al diseñar el segundo país.
- **Cerrar antes de**: fase 3.

### D-36 · Criterio de «verificado» en parámetros legales
- **Qué**: `parametros_legales_pe.json` marca `verificado: true` tanto lo leído en fuente oficial como lo leído solo en una fuente secundaria seria (la nota del parámetro lo dice). El documento 18 distingue V, V2 y PC.
- **Opciones**: (a) un solo indicador más la nota (aplicado); (b) tres niveles en el catálogo y en `legal_parameters`; (c) solo fuente oficial cuenta como verificado.
- **Recomendación**: (b) antes de producción: el cierre de planilla debería exigir verificación de especialista, no de fuente secundaria (RN-PAR-03).
- **Cerrar antes de**: fase 3.

### D-37 · Plantillas de obligaciones de cumplimiento del paquete Perú
- **Qué**: el documento 18 propone obligaciones nuevas (sesión mensual del comité, comunicaciones a la autoridad, revisión diaria de la casilla, capacitación y evaluación anual de hostigamiento). `compliance_obligation_templates` no tiene datos sembrados en `schema.sql`: los instala el paquete de país.
- **Opciones**: (a) añadirlas al paquete cuando se construya PAI/CUM; (b) sembrarlas en el esquema.
- **Recomendación**: (a). Queda pendiente: no hay hoy un catálogo de plantillas en el kit donde aplicarlo.
- **Cerrar antes de**: fase 7.
