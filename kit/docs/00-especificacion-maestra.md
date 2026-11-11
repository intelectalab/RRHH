# GestiaHR — Especificación maestra (fuente única para documentación y prototipo)

Producto: **GestiaHR** (dominio `gestiahr.com`), SaaS integral de Recursos Humanos (HRIS/HCM) multiempresa. Nace para Perú y se extiende luego a Latinoamérica. Proyecto propio de la empresa: **la documentación no lleva precios, costos, horas, tiempos ni tamaño de equipo**; eso lo define el área de desarrollo.

Insumos: `docs/00-pedido-original.md` (124 puntos del pedido), `docs/00-analisis-de-competencia.md` (análisis de competencia y normativa peruana), `prototipo/fuentes/core.html` (prototipo: núcleo), `prototipo/fuentes/MODULE_API.md`.

## Stack (igual que los demás kits del área; recomendación a confirmar, decisión A-01)

Laravel 12 / PHP 8.4 · PostgreSQL 17 · Redis · Next.js 15 (React, TypeScript, Tailwind) · colas con Horizon · almacenamiento compatible con S3 · monorepo `apps/api`, `apps/web`, `packages/contracts` (tipos desde OpenAPI). Multiempresa en base y esquema compartidos con `tenant_id` + RLS + claves foráneas compuestas. API primero (REST versionada `/api/v1`), eventos de dominio con outbox, adaptadores con implementación falsa para toda integración.

## Correcciones al pedido original

- **C-01 Persona ≠ usuario ≠ colaborador ≠ relación laboral.** `people` (persona), `users` (acceso), `employments` (relación laboral con una razón social). Una persona puede tener varias relaciones en el tiempo (reingreso) o simultáneas en empresas del grupo. Todo lo laboral cuelga de la relación.
- **C-02 Puesto ≠ posición.** `job_profiles` (puesto de catálogo: Analista Contable) y `positions` (plaza presupuestada: una silla). Headcount, vacantes, organigrama por posiciones y sucesión trabajan sobre posiciones.
- **C-03 Historia con vigencia (effective dating).** Asignación, compensación, contrato y jefe se guardan como registros con `valid_from/valid_to`; nunca se sobrescriben. Permite cambios con fecha futura y cálculos retroactivos de planilla.
- **C-04 La plataforma SaaS va en los cimientos, no al final.** Planes, módulos por organización, límites y feature flags condicionan todo; pasan de la «Fase 9» del pedido a la Fase 0. Lo mismo el motor de aprobaciones/workflows, notificaciones y campos personalizados: son transversales y los módulos de la Fase 2 en adelante dependen de ellos.
- **C-05 Planilla = motor + reglas + paquete de país + régimen laboral.** Además de país, la regla depende del régimen (general, microempresa, pequeña empresa, construcción civil, agrario, modalidades formativas) por empresa y por trabajador. Conceptos, tasas y topes con vigencia por fecha; nunca constantes en código.
- **C-06 Anonimato real.** Encuestas anónimas con umbral mínimo de respuestas por segmento (parámetro) y sin guardar el vínculo respuesta–persona. Denuncias anónimas sin registrar IP ni usuario.
- **C-07 Auditoría, actividad y logs técnicos separados**, y registro de asistencia inalterable (las correcciones son registros nuevos con motivo), como exige el DS 004-2006-TR.
- **C-08 IA con gobierno.** Registro de cada uso (quién, qué datos, qué respondió), respeta permisos del usuario, sin decisiones automáticas de alto impacto, explicación obligatoria en alertas y puntajes. Evaluación de impacto para biometría y perfilado (DS 016-2024-JUS).
- **C-09 Acceso sin correo corporativo.** El personal operativo entra con documento de identidad + clave/OTP; el correo no puede ser obligatorio.
- **C-10 Permisos por campo y alcance** como capacidad de base (queja recurrente contra el líder regional: no poder crear un administrador que no vea sueldos).
- **C-11 Una sola fuente de verdad** para sueldo, jefe, puesto y saldos: los módulos consumen, no duplican.
- **C-12 Firma electrónica** con evidencia propia (hash, sello de tiempo, IP, OTP) y capa de proveedor para firma digital con certificado; ambas admitidas para documentos laborales.

## Ampliaciones (lo que el pedido no pide y el mercado o la norma peruana exigen)

| Código | Ampliación | Origen |
|---|---|---|
| E-01 | **Tablero de cumplimiento Perú**: obligaciones por empresa (comité/supervisor SST, comité de hostigamiento, política salarial, Vida Ley, SCTR, cuota de discapacidad, lactario, T-Registro, registros SST) con semáforo, responsable y evidencia | Nadie lo ofrece como producto |
| E-02 | **Expediente de inspección SUNAFIL** (boletas, asistencia, contratos, SST y constancias por trabajador y periodo) y bandeja de **casilla electrónica** con responsables y plazos | Brecha de mercado |
| E-03 | **Provisiones y centralización contable** (CTS, gratificaciones, vacaciones; asiento por centro de costo; exportación a ERP) | Ofisis, Worky |
| E-04 | **Liquidación de beneficios sociales** con simulador (truncos, vacaciones no gozadas, indemnización) y documentos de cese (certificado, carta CTS, constancia) | Norma |
| E-05 | **Igualdad salarial (Ley 30709)**: cuadro de categorías y funciones, política salarial, brecha por categoría y sexo, evidencia de comunicación | Norma |
| E-06 | **Bolsa de horas** y compensación de sobretiempo con saldos | Sesame |
| E-07 | **Marcación universal**: web, app con selfie y geocerca, kiosko/QR/PIN, cuadrilla (el supervisor marca al equipo), modo sin conexión, conectores abiertos a relojes biométricos | GeoVictoria; queja contra Buk |
| E-08 | **Contratos a modalidad**: alerta de vencimiento y de tope acumulado (desnaturalización), renovación masiva con firma | Norma |
| E-09 | **Adelanto de sueldo** (sobre lo ya devengado, con tope por política y descuento automático en planilla) y pago por billeteras | Buk, Ofisis |
| E-10 | **Organigrama por posiciones** con vacantes y presupuesto | Rippling, HiBob |
| E-11 | **Autoservicio por WhatsApp** (boletas, vacaciones, constancias, marcación) como canal, tras la capa de mensajería | Nadie lo ofrece completo |
| E-12 | **EPP y uniformes** con tallas, entregas firmadas y vencimientos, ligado a SST | Norma SST |
| E-13 | **Hojas de tiempo por proyecto** con costeo | Factorial |
| E-14 | **Movilidad interna, referidos y matriz de habilidades (skills)** | Humand, Lapzo |
| E-15 | **Hostigamiento sexual (Ley 27942)**: comité de intervención o delegado, medidas de protección, plazos del procedimiento, capacitación obligatoria | Norma |
| E-16 | **Privacidad (Ley 29733, DS 016-2024-JUS)**: consentimientos, derechos del titular, banco de datos, registro de incidentes con plazo de 48 h, evaluación de impacto | Norma |
| E-17 | **Sindicatos y convenios**: afiliación, cuota sindical, licencias sindicales, cláusulas del convenio como conceptos | Ningún competidor |
| E-18 | **Locadores / cuarta categoría**: prestadores con recibos por honorarios, separados de la planilla | Deel (contratistas) |
| E-19 | **Derechohabientes, seguros y regímenes**: T-Registro (altas, bajas, derechohabientes), Vida Ley, SCTR, EPS, régimen pensionario | Norma |
| E-20 | **Teletrabajo (Ley 31572)**: acuerdo con contenido mínimo, desconexión digital, compensación de gastos como concepto no remunerativo | Norma |
| E-21 | **Utilidades** (DL 892): distribución por días y remuneraciones, liquidación individual | Norma |
| E-22 | **API pública y servidor MCP** para asistentes de IA externos, desde el inicio | Workday, HiBob, BambooHR |
| E-23 | **Viáticos y rendiciones** con políticas por puesto (topes, categorías) | Vacío en la oferta local |
| E-24 | **Modo estudio contable**: un estudio administra las planillas de muchas empresas pequeñas (consola de socio) | Segmento desatendido |
| E-25 | **Clima con mapa de calor** por área y planes de acción con seguimiento | Rex+ |
| E-26 | **Riesgo de rotación explicable** (factores visibles, sin decisiones automáticas) | Rankmi |
| E-27 | **Puesta en marcha guiada**: asistente de primeros pasos, plantillas peruanas listas (conceptos, contratos, políticas), importador con validación | Queja: implementaciones largas |

## Catálogo de módulos y fases (sin fechas)

Módulo comercial = lo que una organización contrata. Código = prefijo de épica en el backlog.

| Fase | Códigos de épica |
|---|---|
| **0 · Cimientos** | PLT plataforma técnica · TEN multiempresa y aislamiento · SEG identidad, roles, permisos por campo, alcance · SAS consola SaaS (organizaciones, planes, módulos, límites, feature flags, soporte, salud) · AUD auditoría · UI sistema de diseño y armazón · WFL motor de aprobaciones y workflows · NOT notificaciones y mensajería · CFG configuración, parámetros, campos personalizados, i18n, multimoneda · WEB sitio comercial gestiahr.com (productos, precios, registro y suscripción, blog, SEO, chatbot) |
| **1 · Core HR** | ORG estructura organizacional · POS posiciones y headcount · COL colaboradores y ficha 360 · HIS historia con vigencia · CON contratos y adendas · DOC gestión documental, plantillas y firma · ESS portal del colaborador · MSS portal del manager · IMP importador y puesta en marcha · AYU centro de ayuda |
| **2 · Tiempo** | ASI asistencia y marcación · TUR horarios, jornadas y turnos · VAC vacaciones · AUS ausencias y permisos · BHO horas extra y bolsa de horas · TSH hojas de tiempo por proyecto |
| **3 · Nómina Perú** | NOM motor de nómina · CPT conceptos y fórmulas · PAR parámetros legales con vigencia · PER periodos, incidencias, cálculo, simulación y cierre · BOL boletas · BSO beneficios sociales (CTS, gratificaciones, utilidades) · PRE préstamos, adelantos y adelanto de sueldo · DEC declaraciones y archivos (PLAME, T-Registro, AFPnet, bancos) · PRV provisiones y asientos · LIQ liquidación de beneficios · LOC locadores |
| **4 · Talento** | ATS reclutamiento, portal de empleos, base de talento · ENT entrevistas y ofertas · ONB pre-onboarding y onboarding · OFF offboarding · DES evaluación de desempeño · OKR objetivos · CMP competencias y habilidades · FBK reuniones 1:1 y feedback |
| **5 · Desarrollo y compensación** | LMS aprendizaje y plan anual · CAR desarrollo, carrera, sucesión y 9-box · COM compensaciones, bandas, revisión salarial e igualdad salarial · BNF beneficios |
| **6 · Experiencia** | ENC encuestas, clima y eNPS · REC reconocimientos · CIN comunicación interna · HSD mesa de ayuda y base de conocimiento |
| **7 · Operación y cumplimiento** | SST seguridad y salud · DEN canal de denuncias y hostigamiento · DIS casos disciplinarios · ACT activos, EPP y uniformes · GAS gastos, viáticos y reembolsos · CUM cumplimiento Perú y SUNAFIL · PRI privacidad y retención · SIN sindicatos y convenios |
| **8 · Inteligencia** | ANA people analytics y tableros · RPT reportes y constructor · ALE alertas explicadas · IAR asistente de IA y gobierno |
| **9 · Ecosistema y expansión** | API API pública, webhooks y MCP · INT integraciones (ERP, bancos, biométricos, SSO, calendario) · WHA autoservicio por WhatsApp · PAI paquetes de país · WLB marca blanca · EST modo estudio contable |
| **Transversal** | QA calidad, seguridad y salida a producción |

Orden de fases: 0 y 1 son requisito de todo. 2 antes de 3 (la planilla consume asistencia y ausencias). 4 a 7 son independientes entre sí. 8 necesita datos de las anteriores. Una organización puede usar solo Core HR + Vacaciones y activar el resto sin migrar.

## Roles de sistema

Superadmin SaaS (consola aparte) · Administrador empresa · RRHH · Payroll · Recruiter · Manager · Empleado · Auditor (solo lectura) · SST · Investigador de denuncias (acceso restringido) · Contador (solo lectura de planilla y asientos). Roles personalizados con permisos `modulo.recurso.accion`, permisos por campo sensible y alcance (toda la organización, empresa, sede, unidad, equipo, propio).

## Reglas transversales no negociables

1. Ninguna consulta cruza organizaciones (RLS + filtro en aplicación + prueba automática sobre todos los endpoints).
2. Nada histórico se sobrescribe ni se borra: vigencias, soft delete, papelera, retención por política.
3. Los estados cambian solo por acciones explícitas (`POST /recurso/{id}/accion`) que validan la máquina de estados.
4. Toda regla legal (tasa, tope, plazo) es un parámetro con vigencia por fecha y por país/régimen.
5. Toda acción sensible queda auditada con antes y después; la auditoría es de solo inserción.
6. Los datos sensibles (sueldo, salud, denuncias, sanciones, biometría) exigen permiso por campo, se cifran y su acceso queda registrado.
7. Las integraciones pasan por adaptadores con implementación falsa; los efectos externos salen por outbox e idempotencia.
8. Lo que la interfaz oculta, el backend lo valida.
9. La IA asiste; contratar, cesar, sancionar, promover o cambiar sueldo lo decide una persona.
10. Textos en archivos de traducción (es, en, pt); montos con moneda; fechas con zona horaria de la empresa.
