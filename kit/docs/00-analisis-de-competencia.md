# GestiaHR — Análisis de competencia y decisiones de producto

Versión 1.0 · 02/10/2026. Sin precios, costos ni tiempos: este documento define **qué** construir y **por qué**.

## 1. Conclusiones

1. **En Perú solo un competidor (Buk) cubre casi toda la suite.** Los demás locales (Talana, Mandü/Rex+, Ofisis) dejan huecos en SST, compensaciones, activos, gastos y canal de denuncias. Los globales (Workday, Rippling, HiBob, Personio, Factorial, BambooHR, Deel) tienen todo menos planilla peruana.
2. **El pedido original cubre bien la amplitud** (124 puntos, equivalente a la suite del líder regional), pero se queda corto en lo que más pesa en Perú: profundidad de planilla (regímenes, provisiones, liquidaciones, declaraciones) y cumplimiento normativo (SUNAFIL, igualdad salarial, hostigamiento, datos personales).
3. **Las debilidades explotables del líder** son implementación larga, permisos gruesos (no se puede crear un administrador que no vea sueldos), biometría limitada y lentitud. De ahí salen tres apuestas del producto: puesta en marcha guiada con plantillas peruanas, permisos por campo y alcance desde el núcleo, y marcación abierta a cualquier reloj.
4. **Nadie vende «cumplimiento como producto».** Un tablero de obligaciones por empresa con semáforo y evidencias, y el expediente de inspección SUNAFIL, son diferenciadores sin competencia directa.
5. **Gastos y viáticos están vacíos en toda la oferta local**, y el autoservicio completo por WhatsApp no lo ofrece nadie.
6. **La tendencia del sector es IA con agentes y apertura (API, MCP).** El producto debe nacer con API pública, bitácora de IA y la regla de que la IA asiste pero no decide.
7. **Segmento desatendido:** MYPE y estudios contables que administran muchas planillas pequeñas; el modelo multiempresa lo permite con una consola de socio.

## 2. Correcciones al pedido original

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

## 3. Ampliaciones incorporadas

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

Todas están en el backlog (referencias `E-xx` y `C-xx`), en las especificaciones de módulo y en el prototipo.

## 4. Límites de este análisis

- Las fichas salen de sitios oficiales y reseñas públicas; conviene validar la matriz en demostraciones antes de usarla como argumento comercial.
- Varias normas peruanas se citan desde fuentes secundarias. Toda tasa, tope o plazo se trata en el producto como **parámetro con vigencia**, y `docs/14-localizacion-peru.md` lista 45 puntos a validar con un especialista laboral antes de producción.

## 5. Investigación


Fecha de corte: 2 de octubre de 2026. Las fichas salen de sitios oficiales y reseñas públicas. Lo que no pude abrir o confirmar va marcado como "no verificado".

**Límites de esta investigación:**
- G2 no se consultó directamente; las quejas de los competidores globales provienen de reseñas editoriales de terceros.
- Solo obtuve reseñas de usuarios de Capterra para Buk.
- Pandapé bloqueó la lectura automática; Nubox y Defontana no se revisaron a fondo.
- Varias normas peruanas se citan desde fuentes secundarias (blogs legales, estudios), no desde El Peruano.

---

### 1. Fichas de competidores

#### 1.1 Globales

**Workday**
- **Segmento:** gran empresa y corporativos multinacionales; HCM y finanzas en una sola plataforma.
- **Módulos:** Core HCM, nómina (pocos países nativos, el resto por socios), talento, reclutamiento, aprendizaje, compensaciones, beneficios, planificación de fuerza laboral, tiempo y ausencias, gastos, analítica.
- **Diferenciales:**
  1. Sana como interfaz conversacional por defecto para todos los clientes. Busca respuestas, ejecuta acciones y sirve de estudio para crear agentes; se conecta con Salesforce, Slack, Teams y SharePoint.
  2. Agent System of Record: registra identidad, habilidades, permisos y auditoría de cada agente de IA, propio o de terceros.
  3. Agent Gateway con estándares abiertos (MCP, A2A): los agentes externos delegan en Workday las transacciones reguladas.
  4. Capa semántica de skills, rutas de carrera y certificaciones sobre los datos transaccionales.
  5. Modelo comercial híbrido: suscripción más consumo por créditos; las API se miden por llamada.
- **Debilidades:** implementación pesada y dependiente de integradores; sobredimensionado para pymes. Mandü señala que las plataformas internacionales requieren configuración manual para la normativa peruana y actualizan tarde.
- **Perú:** sin nómina peruana nativa verificada; se usa con un motor de planilla local.

**BambooHR**
- **Segmento:** pyme (sobre todo EE. UU.), foco en simplicidad.
- **Módulos:** expediente, contratación, onboarding/offboarding, control de tiempo, desempeño, nómina, beneficios, reportes, comunidad de empleados.
- **Diferenciales:**
  1. Asistente de reportes: arma reportes a medida desde una instrucción en lenguaje natural.
  2. Comparación y revisión de nómina entre periodos para detectar variaciones.
  3. Asistente que propone borradores de horarios.
  4. Detección de datos sucios en el expediente.
  5. Conectores de IA hacia asistentes externos (Claude en beta; ChatGPT y Gemini planificados).
- **Debilidades:** nómina nativa solo en EE. UU.; la global depende de un EOR tercero. Nómina, beneficios y tiempo son complementos de pago. Personalización limitada y sin lectura automática de CV.
- **Perú:** no.

**Rippling**
- **Segmento:** empresas tecnológicas y distribuidas, de pyme a mediana-grande; plataforma de RR. HH., TI y finanzas sobre un único grafo de empleado.
- **Módulos:** HR, nómina (local, global y contratistas), TI (dispositivos, accesos, SSO), finanzas (gastos, tarjetas, pagos, viajes), ATS, LMS, desempeño.
- **Diferenciales:**
  1. Workflow Studio: cualquier cambio de dato dispara alertas, asignaciones de cursos, pagos o eventos de calendario entre áreas.
  2. Gestión de dispositivos y aplicaciones atada al ciclo de vida: alta y baja de cuentas al contratar o cesar.
  3. Gastos con políticas aplicadas por atributos del empleado.
  4. ATS con resúmenes de candidatos por IA.
  5. LMS con cursos propios y archivos SCORM.
- **Debilidades:** el costo escala por usuario y por módulo activado; reportes y flujos avanzados quedan en planes superiores; abruma a organizaciones simples; poco valor donde la regulación es muy específica.
- **Perú:** sin localización de planilla verificada.

**Deel**
- **Segmento:** contratación global: EOR, contratistas y nómina multi-país; desde startups hasta corporativos distribuidos.
- **Módulos:** EOR, pago y gestión de contratistas, nómina global, HRIS, reclutamiento, TI (ciclo de vida de equipos), visas, beneficios, agentes de IA.
- **Diferenciales:**
  1. Entidades propias en 130+ países: Deel actúa como empleador legal sin que el cliente abra sociedad local.
  2. Opción en que Deel asume la responsabilidad por la clasificación del contratista.
  3. Un solo contrato cubre empleo, nómina, HR, reclutamiento, laptops y visas.
  4. Agentes de IA para onboarding, anomalías de nómina y solicitudes de ausencia.
- **Debilidades:** costos fuera de la tarifa visible (fondeo, retiros, beneficios como complemento); poca visibilidad del soporte durante incidentes; funciones clave reservadas a planes altos; las pymes lo califican peor en relación valor-precio. Otra reseña lo considera caro como HRIS puro y flojo en cultura.
- **Perú:** no verifiqué su cobertura específica para Perú. No sustituye una planilla local con PLAME y AFPnet para una empresa peruana con RUC.

**HiBob (Bob)**
- **Segmento:** mediana empresa multinacional; foco en cultura y experiencia.
- **Módulos:** Core HR, flujos, contratación, desempeño, aprendizaje, skills, nómina (EE. UU. y Reino Unido), beneficios, tiempo, planificación de fuerza laboral, compensaciones, módulo para finanzas.
- **Diferenciales:**
  1. Bob AI genera evaluaciones, descripciones de puesto y comunicaciones.
  2. Servidor MCP que expone el contexto de Bob a Claude, ChatGPT y Copilot.
  3. Planificación de dotación con escenarios y 150+ métricas para finanzas.
  4. Ciclos de compensación integrados.
- **Debilidades:** la nómina es un complemento con cobertura reducida.
- **Perú:** no.

**Personio**
- **Segmento:** pyme europea (base en Alemania).
- **Módulos:** perfiles, onboarding, asistencia, ausencias, documentos y firma, flujos, nómina preliminar, analítica, reclutamiento, desempeño, compensaciones, encuestas, canal de denuncias, EOR.
- **Diferenciales:**
  1. Personio Assistant: responde con datos de RR. HH., genera reportes con gráficos compartibles y resume evaluaciones.
  2. Canal de denuncias como aplicación nativa.
  3. Nómina configurable por entidad legal.
  4. 200+ integraciones.
- **Debilidades:** costo por módulo que se acumula; nómina nativa limitada a la región germanoparlante; ATS superficial para alto volumen; reportes mayormente predefinidos.
- **Perú:** no.

**Factorial**
- **Segmento:** pyme; se posiciona como plataforma de operaciones (RR. HH., finanzas, TI) con IA.
- **Módulos:** HR y nómina, firma, ausencias, asistencia, turnos, proyectos, desempeño, ATS, onboarding/offboarding, formación, OKR, gastos, facturación de proyectos, tarjetas corporativas, dispositivos, inventario TI, gestión de SaaS, documentos, organigrama, comunicación, automatizaciones.
- **Diferenciales:**
  1. Agente "One": analiza datos, crea reportes y gestiona aprobaciones con el contexto de la empresa.
  2. Proyectos y hojas de tiempo ligados a facturación.
  3. Gastos y tarjetas en la misma plataforma.
  4. Inventario TI y gestión de licencias SaaS.
- **Debilidades:** nómina localizada centrada en Europa. No recogí quejas de usuarios.
- **Perú:** no verifiqué planilla peruana nativa.

**Sesame HR**
- **Segmento:** pyme española con presencia comercial en Latam; el sitio lista Perú, México, Colombia, Chile, Costa Rica y Brasil.
- **Módulos:** fichaje, turnos, vacaciones, bolsa de horas extra, reclutamiento, onboarding, comunicación, desempeño, encuestas, base de empleados, reportes, nómina, documentos, reserva de espacios, gastos, formación, OKR, firma.
- **Diferenciales:**
  1. Compensación de horas extra con saldo (bolsa de horas).
  2. Reserva de espacios de trabajo.
  3. Control de gastos.
  4. Diseño móvil primero (iOS, Android, tablet como reloj).
- **Debilidades:** no verifiqué la profundidad de su nómina ni cálculo legal peruano. No recogí quejas.
- **Perú:** presencia comercial; localización de planilla no verificada.

#### 1.2 Latam y Perú

**Buk** (líder regional; rival principal)
- **Segmento:** mediana y gran empresa; suite completa. Declara 1.000+ empresas en Perú y operación en Perú, Chile, México, Colombia y Brasil.
- **Módulos:**
  - Plataforma: estructura organizacional, portal, perfiles, flujos, analítica.
  - Administración: planilla, asistencia, activos, firma y documentos, onboarding, canal de denuncias, atención al colaborador, planificación de dotación, compensaciones, SST.
  - Desarrollo: desempeño, comunicación y reconocimiento, selección, capacitación, encuestas.
  - Beneficios: gestión de beneficios, adelanto de sueldo, descuentos.
  - Buk AI.
- **Diferenciales:**
  1. Base de datos única para todos los módulos.
  2. Adelanto de sueldo inmediato dentro del módulo de beneficios.
  3. Buk AI en tres usos: crear procesos, analizar datos y atender al colaborador 24/7.
  4. Planificación de dotación y compensaciones, poco comunes en suites locales.
  5. Canal de denuncias y SST nativos.
- **Debilidades (Capterra, 4,8/5 con 354 reseñas):** implementación y configuración largas; permisos poco granulares (no se puede crear un administrador que no vea sueldos); compatibilidad limitada con relojes biométricos; lentitud ocasional. Modelo comercial por módulo.
- **Perú:** sí, completa.

**Talana**
- **Segmento:** mediana empresa operativa (hotelería, manufactura, tercerización, agro). Origen chileno; declara 10+ años en Perú y 500+ empresas peruanas.
- **Módulos (15):** planillas, asistencia y turnos, firma electrónica, gestión de personas, centro de requerimientos, clima, mediciones organizacionales, comunicaciones, OKR, NPS, reclutamiento, capacitaciones, reconocimiento, onboarding, desempeño.
- **Diferenciales:**
  1. App Talana Next: marcación, documentos, firma, vacaciones y beneficios en una sola app.
  2. Firma electrónica bajo Ley 27269.
  3. Centro de requerimientos (mesa de solicitudes a RR. HH.).
  4. Certificaciones ISO 27001 y 27701.
- **Debilidades:** Buk lo describe como modular y operativo, con poca integración estratégica (fuente interesada). No vi compensaciones, SST, activos ni gastos.
- **Perú:** sí.
- No verifiqué un producto de adelanto de sueldo ("Talana Pay") en las páginas consultadas.

**Rex+ / Mandü**
- **Segmento:** mediana y gran empresa peruana. El sitio de Rex+ indica que desde 2025 forma parte del ecosistema Mandü; la página de Mandü no lo menciona.
- **Módulos:**
  - Rex+: planilla (multi-RUC, declaraciones SUNAT, CTS, gratificaciones), clima y encuestas, reclutamiento.
  - Mandü: administración (onboarding, documentos, firma, vacaciones, portal), nómina (PLAME, AFPnet, asistencia), cultura, talento (desempeño, mapeo de talento, capacitación).
- **Diferenciales:**
  1. Gestión multi-RUC en planilla.
  2. Encuestas con eNPS, mapas de calor y reportes en tiempo real.
  3. Mandü IA: filtra candidatos, responde consultas 24/7 y genera objetivos y planes de desarrollo.
  4. Soporte local en Lima.
- **Debilidades:** Mandü admite que las soluciones locales suelen tener menos integraciones. Dos marcas en transición. No vi SST, activos, beneficios ni gastos.
- **Perú:** sí.

**GeoVictoria**
- **Segmento:** especialista en asistencia y gestión de turnos para personal operativo (construcción, seguridad, retail, tercerización); 15.000+ empresas en Latam.
- **Módulos:** asistencia, control de acceso, control de comedor, tablero BI, permisos y vacaciones, planificador de turnos, alertas.
- **Diferenciales:**
  1. Muchos métodos de marcación: reloj biométrico (huella o rostro), web, app con selfie y GPS, USB, llamada con reconocimiento de voz, app de cuadrilla (un supervisor marca al equipo).
  2. Funcionamiento sin conexión: el dispositivo guarda y sincroniza después.
  3. Alertas por WhatsApp y correo cuando alguien programado no llega.
  4. Planificador de turnos mensual con IA.
  5. Control de comedor y de acceso sobre la misma identidad.
- **Debilidades:** no es HRIS ni planilla; exige integración con un motor de nómina.
- **Perú:** sí en asistencia; no calcula planilla.

**Ofisis Personas (Softland) y planilla tradicional**
- **Segmento:** mediana y gran empresa peruana con ERP; 20.000+ trabajadores gestionados.
- **Módulos:** planilla, PLAME y T-Registro con validación previa, AFP/ONP, quinta categoría con actualización de UIT, provisiones (CTS, gratificaciones, vacaciones), liquidaciones, archivos bancarios, asientos contables, autoservicio con boletas electrónicas.
- **Diferenciales:**
  1. Varios regímenes simultáneos: general, MYPE, construcción civil, agrario.
  2. Multiempresa y centros de costo.
  3. Asientos contables e integración con ERP.
  4. Pago por billeteras digitales (declarado para 2026).
  5. Reportes configurables sin apoyo técnico.
- **Debilidades:** centrado en planilla; sin talento, clima ni comunicación verificados.
- **Otros en la categoría (no revisados a fondo):** Spring, SICO, SBA, Visualcont, Hapiqa, Defontana, Nubox. Compiten por profundidad de cálculo e integración contable, no por experiencia del colaborador.

**Worky (México)**
- **Segmento:** pyme y mediana mexicana operativa.
- **Módulos:** reloj checador y turnos, nómina con seguridad social, documentos y firma, incidencias, reclutamiento, onboarding, desempeño, capacitación, KPI y objetivos, cultura, bienestar financiero (operado por Finsus).
- **Diferenciales:**
  1. Flujo continuo "del reloj checador a la póliza contable".
  2. SophIA: agente que detecta anomalías en nómina.
  3. Garantía "Cero Multas" de cumplimiento.
  4. Garantía de implementación con apoyo de migración.
- **Perú:** no.

**Runa HR (México)**
- **Segmento:** pyme a corporativo; 3.500+ empresas.
- **Módulos:** nómina, IMSS e impuesto estatal, expediente, ausencias, asistencia y turnos, documentos, activos, comunicación, onboarding/offboarding, encuestas. En beta: desempeño, capacitación, canal de denuncias, reclutamiento.
- **Diferenciales:**
  1. Agentes de IA especializados: onboarding, capacitación, desempeño, mesa de ayuda 24/7, trámites de seguridad social.
  2. Nivel gratuito para empresas muy pequeñas.
  3. Servicio de maquila de nómina además del software.
- **Debilidades:** varios módulos de talento siguen en beta.
- **Perú:** el sitio lo lista como país disponible; no verifiqué la profundidad de la planilla peruana.

**Humand**
- **Segmento:** empresas con mucho personal operativo sin correo corporativo; 2.000+ empresas en 50+ países; Serie A de US$ 66 millones.
- **Módulos:** chat, muro social, transmisiones en vivo, noticias, organigrama, biblioteca, beneficios, documentos, onboarding, vacaciones, legajo digital, reserva de espacios, encuestas, desempeño, OKR, LMS, plan de carrera, búsquedas internas, flujos, asistencia, turnos, mesa de servicios, formularios, referidos, cumpleaños, marketplace, eventos, reconocimientos.
- **Diferenciales:**
  1. Acceso con documento de identidad, sin correo.
  2. Adopción declarada del 92%.
  3. Movilidad interna y referidos nativos.
  4. Marketplace entre colaboradores y reserva de espacios.
- **Debilidades:** no calcula nómina; convive con un sistema de planilla.
- **Perú:** se vende en la región; sin planilla.

**Rankmi**
- **Segmento:** mediana y gran empresa; 1.500+ empresas en 14+ países.
- **Módulos:** base con IA, administración y nómina con firma, talento (desempeño, carrera, sucesión), cultura (clima, reconocimiento, beneficios personalizados).
- **Diferenciales:**
  1. Planes de sucesión y carrera.
  2. Alertas predictivas de rotación y brechas de talento.
  3. Chatbot 24/7 para colaboradores.
  4. Certificaciones ISO 27001, 27701 y 37001.
- **Perú:** opera en la región; no verifiqué planilla peruana.

**Krowdy / Laborum (Perú)**
- **Segmento:** reclutamiento y evaluación. El dominio de Krowdy redirige hoy a Laborum para empresas.
- **Diferenciales:** publicación en la bolsa de empleo con ATS, pruebas psicométricas, videocuestionarios, exámenes técnicos, verificación de antecedentes y referencias. Modelo de pago por unidad consumida.
- **Perú:** sí. No es HRIS.

**Pandapé, Lapzo y otros**
- **Pandapé:** ATS y suite de RR. HH. ligada a Computrabajo; no verificado (sitio bloqueado).
- **Lapzo (México):** mapa de competencias por rol, planes de desarrollo, LMS con 1.000+ recursos, matriz 9-box y diseño de cursos con IA.
- **Defontana y Nubox:** suites contables con RR. HH.; no revisadas.

---

### 2. Matriz comparativa

Leyenda: ● nativo · ◐ parcial, complemento, beta o vía socio · ○ no ofrecido o no verificado. Las celdas combinan lo publicado en los sitios con mi valoración; conviene validarlas en demo antes de usarlas como argumento comercial.

#### 2.1 Globales

| Módulo | Workday | BambooHR | Rippling | Deel | HiBob | Personio | Factorial | Sesame |
|---|---|---|---|---|---|---|---|---|
| Core HR | ● | ● | ● | ● | ● | ● | ● | ● |
| Organigrama | ● | ● | ● | ● | ● | ● | ● | ● |
| Portal empleado/manager | ● | ● | ● | ● | ● | ● | ● | ● |
| Asistencia/turnos | ● | ◐ | ● | ◐ | ◐ | ◐ | ● | ● |
| Vacaciones | ● | ● | ● | ● | ● | ● | ● | ● |
| Nómina | ◐ | ◐ | ● | ● | ◐ | ◐ | ◐ | ◐ |
| Nómina Perú | ○ | ○ | ○ | ◐ | ○ | ○ | ○ | ○ |
| ATS | ● | ● | ● | ◐ | ● | ● | ● | ● |
| Onboarding/offboarding | ● | ● | ● | ● | ● | ● | ● | ● |
| Desempeño/OKR | ● | ● | ● | ◐ | ● | ● | ● | ● |
| LMS | ● | ◐ | ● | ◐ | ● | ◐ | ● | ● |
| Encuestas/eNPS | ● | ● | ● | ◐ | ● | ● | ● | ● |
| Compensaciones | ● | ◐ | ● | ◐ | ● | ● | ◐ | ○ |
| Beneficios | ● | ● | ● | ◐ | ◐ | ○ | ◐ | ○ |
| Gastos | ● | ○ | ● | ◐ | ○ | ○ | ● | ● |
| Activos/IT | ○ | ◐ | ● | ● | ○ | ○ | ● | ◐ |
| Firma electrónica | ◐ | ● | ● | ● | ● | ● | ● | ● |
| SST | ◐ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| Canal de denuncias | ○ | ○ | ○ | ○ | ○ | ● | ◐ | ◐ |
| Analytics | ● | ● | ● | ● | ● | ● | ● | ● |
| Workflows no-code | ● | ◐ | ● | ● | ● | ● | ● | ◐ |
| IA | ● | ● | ● | ● | ● | ● | ● | ● |
| API/marketplace | ● | ● | ● | ● | ● | ● | ● | ◐ |
| App móvil | ● | ● | ● | ● | ● | ● | ● | ● |
| Comunicación interna | ◐ | ◐ | ◐ | ◐ | ● | ◐ | ● | ● |

#### 2.2 Latam y Perú

| Módulo | Buk | Talana | Mandü/Rex+ | GeoVictoria | Ofisis | Worky | Runa | Humand | Rankmi |
|---|---|---|---|---|---|---|---|---|---|
| Core HR | ● | ● | ● | ◐ | ● | ● | ● | ◐ | ● |
| Organigrama | ● | ◐ | ◐ | ○ | ◐ | ● | ● | ● | ● |
| Portal empleado/manager | ● | ● | ● | ◐ | ● | ● | ● | ● | ● |
| Asistencia/turnos | ● | ● | ◐ | ● | ◐ | ● | ● | ● | ◐ |
| Vacaciones | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| Nómina | ● | ● | ● | ○ | ● | ● | ● | ○ | ● |
| Nómina Perú | ● | ● | ● | ○ | ● | ○ | ◐ | ○ | ◐ |
| ATS | ● | ● | ● | ○ | ○ | ● | ◐ | ◐ | ◐ |
| Onboarding/offboarding | ● | ● | ● | ○ | ○ | ● | ● | ● | ◐ |
| Desempeño/OKR | ● | ● | ● | ○ | ○ | ● | ◐ | ● | ● |
| LMS | ● | ● | ◐ | ○ | ○ | ● | ◐ | ● | ◐ |
| Encuestas/eNPS | ● | ● | ● | ○ | ○ | ● | ● | ● | ● |
| Compensaciones | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ◐ |
| Beneficios | ● | ◐ | ○ | ○ | ○ | ◐ | ● | ● | ● |
| Gastos | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| Activos/IT | ● | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ |
| Firma electrónica | ● | ● | ● | ○ | ◐ | ● | ● | ● | ● |
| SST | ● | ○ | ○ | ○ | ○ | ◐ | ○ | ○ | ○ |
| Canal de denuncias | ● | ○ | ○ | ○ | ○ | ○ | ◐ | ○ | ○ |
| Analytics | ● | ◐ | ◐ | ● | ◐ | ● | ● | ● | ● |
| Workflows no-code | ● | ◐ | ◐ | ○ | ○ | ◐ | ◐ | ● | ◐ |
| IA | ● | ◐ | ● | ● | ○ | ● | ● | ◐ | ● |
| API/marketplace | ● | ◐ | ◐ | ● | ◐ | ◐ | ◐ | ● | ◐ |
| App móvil | ● | ● | ◐ | ● | ○ | ● | ● | ● | ● |
| Comunicación interna | ● | ● | ○ | ○ | ○ | ◐ | ● | ● | ◐ |

**Lectura de la matriz:** en Perú solo Buk cubre casi todas las filas. Gastos y viáticos están vacíos en toda la columna local. SST, canal de denuncias, compensaciones y activos solo los tiene Buk. Los globales tienen todo menos planilla peruana.

---

### 3. Tendencias 2025-2026

- **Agentes de IA, no solo asistentes.** Ejecutan procesos completos (onboarding, anomalías de nómina, mesa de ayuda). Workday, Deel, Runa, Worky, Factorial y Buk ya los anuncian. Una fuente reporta 30-40% menos tickets a RR. HH. en algunos despliegues.
- **Gobierno de agentes y apertura por MCP.** Registro de identidad, permisos y auditoría de agentes (Workday); HiBob y BambooHR exponen su contexto a asistentes externos. Un producto nuevo debería nacer con API y servidor MCP.
- **Gobernanza de IA y auditorías de sesgo** en selección (Nueva York, Illinois, Colorado, UE). En Perú se cruza con la evaluación de impacto del reglamento de datos personales.
- **Organización basada en skills.** Catálogo de habilidades, brechas y aprendizaje recomendado; la adopción declarada supera la práctica real.
- **Desempeño continuo.** La evaluación anual única retrocede (54% de empresas según la fuente).
- **Transparencia salarial.** La directiva europea exige rangos salariales en ofertas desde junio de 2026. En Perú el equivalente es la Ley 30709.
- **EOR, contratistas y nómina global consolidada.** 41% de equipos usa EOR según la fuente.
- **Suites unificadas con arquitectura componible:** núcleo propio más especialistas por API.
- **Analítica predictiva** de rotación y escasez de skills; planificación de dotación por escenarios.
- **Personal sin escritorio.** App móvil primero, acceso sin correo, turnos, marcación con selfie, GPS y geocerca, alertas por WhatsApp, modo sin conexión.
- **Bienestar financiero.** Adelanto de sueldo (Buk), bienestar financiero (Worky), pago por billeteras digitales (Ofisis).
- **Identidad y biometría.** Verificación biométrica contra candidatos sintéticos; reconocimiento facial en marcación.
- **Convergencia RR. HH., TI y finanzas** (Rippling, Factorial, Deel): dispositivos, licencias, gastos y tarjetas.
- **Modelos comerciales:** por colaborador y por módulo sigue siendo el estándar; aparece el consumo por créditos para IA (Workday) y el pago por unidad en evaluaciones (Krowdy).

---

### 4. Requisitos normativos peruanos

Valores de referencia 2026: UIT S/ 5.500 (El Comercio, Tawa) y RMV S/ 1.130 (Aricruz). El sistema debe tratarlos como parámetros con vigencia por fecha, nunca como constantes.

| Tema | Norma y estado | Qué exige al sistema |
|---|---|---|
| T-Registro | DS 018-2007-TR y normas SUNAT (no reabiertas aquí) | Altas, bajas y modificaciones de trabajadores, derechohabientes y practicantes; datos de régimen pensionario, de salud, tipo de contrato y establecimiento; control de plazos de alta y baja (validar plazos vigentes). |
| PLAME (PDT 601) | Vigente; Ofisis genera el archivo con validación previa | Archivos de importación (jornada, ingresos, descuentos, aportes) con tabla de conceptos mapeada a códigos SUNAT; validación antes de enviar; rectificatorias. |
| AFPnet | Vigente | Planilla de aportes por AFP; aporte obligatorio del 10% al fondo más comisión y prima de seguro, que varían por AFP y esquema (Modelo.pe). No verifiqué las comisiones, la prima ni la remuneración máxima asegurable de 2026: deben cargarse desde la SBS cada periodo. ONP: 13% (Aricruz, Modelo.pe). |
| Reforma previsional | Ley 32123 (citada de memoria, no verificada) | Parametrizar el régimen pensionario para absorber cambios. |
| CTS | DS 001-97-TR | Remuneración computable (sueldo más un sexto de la última gratificación); depósitos en la primera quincena de mayo y noviembre; constancia y liquidación de depósito; entidad depositaria por trabajador; carta de liberación al cese. |
| Gratificaciones | Ley 27735 y Ley 30334 | Julio y diciembre; cálculo proporcional por meses completos; truncas; bonificación extraordinaria del 9% (Aricruz). No verifiqué el porcentaje aplicable a afiliados a EPS. |
| Asignación familiar | Ley 25129 | 10% de la RMV; con RMV de S/ 1.130 resulta S/ 113 (Aricruz). Otra fuente indica S/ 123, lo que no cuadra; tomar el 10% de la RMV vigente. Requiere registro de hijos y sustento. |
| Renta de quinta | Ley del Impuesto a la Renta y su reglamento | Proyección anual, deducción de 7 UIT (S/ 38.500 en 2026), escala progresiva del 8% al 30% (Tawa; no verifiqué los tramos intermedios), recálculo mensual, ingresos de otros empleadores, certificado de retenciones y devolución de excesos. |
| Utilidades | DL 892 | Empresas con más de 20 trabajadores. Porcentajes citados por Aricruz: industria 10%, comercio y restaurantes 8%, otras actividades 5% (la norma tiene más sectores; verificar). Distribución por días laborados y por remuneraciones; liquidación individual. |
| EsSalud / EPS | Ley 26790 | Aporte del empleador del 9% (Aricruz); plan EPS por trabajador y crédito contra el aporte (no verifiqué el porcentaje); subsidios e incapacidades con control de días. |
| SCTR | Ley 26790 y DS 003-98-SA | Marcar puestos y sedes de riesgo; pólizas de salud y pensión; trabajadores cubiertos por periodo; alertas de vencimiento. |
| Vida Ley | DL 688, DU 044-2019, DS 009-2020-TR | Obligatorio desde el inicio de la relación laboral (desde el 11-02-2020); registro de la póliza ante el MTPE dentro de 30 días; declaración de beneficiarios; reporte en planilla (Noticiero Contable). |
| Boletas y firma | DL 1310 art. 3; Informe 0135-2021-MTPE | El MTPE admite firma electrónica simple o avanzada en documentos laborales. Entrega digital con constancia de emisión y acceso del trabajador, y conservación de documentos de pago: los cito de memoria (cinco años); validar con el texto vigente. |
| Control de asistencia | DS 004-2006-TR, modificado por DS 011-2006-TR | Registro con razón social y RUC, nombre y documento del trabajador, fecha, hora y minutos de ingreso y salida, y sobretiempo; medio físico o digital que impida alteración; conservación de cinco años; disponible para inspección (Iteria). Requiere bitácora inmutable de correcciones. |
| Teletrabajo | Ley 31572 y DS 002-2023-TR; modificada por Ley 32102 | Acuerdo con contenido mínimo (modalidad total o parcial, jornada, desconexión, lugar, supervisión, equipos, compensación); solicitudes con respuesta en 10 días hábiles; desconexión mínima de 12 horas continuas; compensación de energía e internet como concepto no remunerativo; MYPE exceptuadas salvo pacto (Buk). Autoevaluación de SST: no verificada. |
| SST | Ley 29783 y DS 005-2012-TR art. 33 | Ocho registros: accidentes, enfermedades e incidentes; exámenes médicos ocupacionales; monitoreo de agentes; inspecciones; estadísticas; equipos de seguridad y emergencia; capacitaciones y simulacros; auditorías. Conservación: 10 años para accidentes e incidentes peligrosos, 20 para enfermedades ocupacionales, 5 para el resto. Formatos referenciales en RM 050-2013-TR. Registros simplificados para MYPE: la fuente cita RM 082-2013-TR; yo recuerdo RM 085-2013-TR, verificar. Además: IPERC, entrega de EPP, y comité o supervisor con actas (umbral de 20 trabajadores, no verificado aquí). |
| Hostigamiento sexual | Ley 27942, DL 1410, DS 014-2019-MIMP y DS 021-2021-MIMP | Comité de intervención con 20 o más trabajadores (dos representantes del empleador y dos de los trabajadores) o delegado si son menos; canal confidencial; medidas de protección al día hábil siguiente; expediente con plazos; capacitación al ingreso y anual; evaluación anual (Buk). Buk indica 28 días calendario de procedimiento; yo recordaba 30, validar con el reglamento. Comunicación al MTPE: no verificada. |
| Igualdad salarial | Ley 30709 y DS 002-2018-TR | Cuadro de categorías y funciones (categoría, puestos, funciones, responsabilidad, requisitos); política salarial; evidencia de comunicación al ingreso, cambio de puesto o de remuneración; reporte de brecha por categoría y sexo con criterios objetivos (experiencia, formación, antigüedad, desempeño). SUNAFIL notificó a 18.226 empresas (Buk). |
| Datos personales | Ley 29733 y DS 016-2024-JUS, vigente desde el 30-03-2025 | Consentimiento y finalidad por tratamiento; derechos de acceso, rectificación, cancelación, oposición y portabilidad; inscripción de bancos de datos; notificación de incidentes en 48 horas; oficial de datos personales de implementación progresiva; evaluación de impacto para biometría y perfilado con IA; garantías para transferencia internacional; cifrado y control de acceso (Kom.pe). Afecta a marcación facial, selección con IA y alojamiento fuera del país. |
| Cuota de discapacidad | Ley 29973, DS 002-2014-MIMP, RM 107-2015-TR | Cálculo sobre el promedio anual de trabajadores; fiscalización anual de SUNAFIL (LP Derecho). El 3% para privados con más de 50 trabajadores no lo pude confirmar en el texto. Requiere marca de condición con consentimiento (dato sensible), cálculo de cuota y registro de convocatorias y ajustes razonables. |
| Lactario | Ley 29896 y DS 023-2021-MIMP | El umbral de 20 o más mujeres en edad fértil por centro de trabajo no fue verificado en el texto. El sistema debe contar ese indicador por sede y registrar implementación y uso. |
| Modalidades formativas | Ley 28518 | Convenios por tipo (prácticas preprofesionales y profesionales, aprendizaje, etc.), subvención, descanso, seguro, plazos máximos y límites sobre la planilla. No verificado en detalle: tratarlos como planilla separada sin beneficios laborales, con reglas parametrizables. |
| Régimen MYPE | TUO aprobado por DS 013-2013-PRODUCE; REMYPE | Microempresa (hasta 150 UIT de ventas): 15 días de vacaciones, sin CTS ni gratificaciones, SIS, indemnización reducida. Pequeña empresa (hasta 1.700 UIT): 15 días de vacaciones, media gratificación y CTS de 15 días por año, EsSalud (SueldoJusto, Aricruz). Régimen por empresa y por trabajador según fecha de ingreso. |
| Liquidación de beneficios | DS 001-97-TR, DL 713, Ley 27735 | CTS, vacaciones y gratificación truncas, vacaciones no gozadas, indemnización según régimen; certificado de trabajo, carta de CTS y constancia de cese. No verifiqué el plazo de 48 horas para el pago. |
| Contratos a modalidad | TUO del DL 728 (DS 003-97-TR) | Nueve modalidades con plazo propio (inicio de actividad 3 años, necesidad de mercado hasta 5, reconversión 2, ocasional 6 meses por año); forma escrita con causa objetiva; alertas de vencimiento y de tope acumulado para evitar la desnaturalización; el registro ante el MTPE dejó de exigirse en noviembre de 2016 (La Cámara). Período de prueba por tipo de puesto: no verificado. |
| SUNAFIL | DS 003-2020-TR; Ley 31736 (no verificada) | Casilla electrónica obligatoria: la notificación surte efecto aunque no se revise. Conviene una bandeja de requerimientos con responsables y plazos, y un "expediente de inspección" exportable por trabajador y periodo (boletas, asistencia, contratos, SST, constancias). |

---

### 5. Brechas y oportunidades

#### 5.1 Funcionalidades que suelen faltar en una especificación típica

**Prioridad alta (ningún rival local las cubre bien o son exigencia legal)**
- **Gastos, viáticos y rendiciones** con políticas por puesto: vacío en toda la oferta local; lo tienen Rippling, Factorial y Sesame.
- **Provisiones y centralización contable:** provisión mensual de CTS, gratificaciones y vacaciones, asiento por centro de costo y exportación al ERP. Lo tienen Ofisis y Worky; las suites de talento no.
- **Cierre de vacaciones** (truncas, vencidas, indemnización vacacional) y simulador de liquidación.
- **Multi-RUC y multi-régimen real** (general, MYPE, construcción, agrario, formativos) con consolidación de grupo.
- **Expediente de inspección SUNAFIL** y bandeja de casilla electrónica: nadie lo ofrece como producto.
- **Igualdad salarial operativa:** cuadro de categorías, bandas, reporte de brecha y evidencia de comunicación.
- **Permisos granulares** por campo y por rol: queja explícita contra Buk.
- **Marcación universal:** selfie con detección de vida, geocerca, modo sin conexión, cuadrilla y conectores abiertos a relojes biométricos (otra queja contra Buk).
- **Bolsa de horas** y compensación de sobretiempo con saldos.
- **Contratos con alertas** de vencimiento, tope acumulado y renovación masiva con firma.

**Prioridad media (diferencian frente a locales)**
- **Adelanto de sueldo** y pago por billeteras: Buk ya lo tiene.
- **Organigrama por posiciones**, con vacantes y presupuesto de dotación.
- **Portal por WhatsApp:** boletas, vacaciones, constancias y marcación. Hoy solo vi alertas (GeoVictoria); nadie ofrece autoservicio completo.
- **Acceso sin correo** con documento de identidad (Humand).
- **Clima con mapas de calor** y planes de acción (Rex+).
- **EPP y uniformes** con tallas, entregas firmadas y vencimientos, ligado al registro de SST.
- **Comedor y control de acceso** (GeoVictoria).
- **Hojas de tiempo por proyecto** con costeo (Factorial).
- **Movilidad interna, referidos y matriz de skills** (Humand, Lapzo).
- **Verificación de antecedentes y psicometría** integradas al ATS, propias o vía socio (Krowdy).
- **Canal de denuncias** con el flujo de la Ley 27942: comité, plazos, medidas de protección.
- **Módulo de privacidad:** consentimientos, solicitudes de derechos, registro de incidentes.
- **Sindicatos y convenios:** afiliación, cuotas, licencias sindicales y cláusulas como conceptos de planilla. No lo vi en ningún competidor.

**Prioridad baja o de segunda fase**
- Contratistas y recibos por honorarios (cuarta categoría).
- Nómina multi-país con motor de reglas por país, pensada para la expansión.
- Reserva de espacios, marketplace de beneficios, tarjetas corporativas.
- Analítica predictiva de rotación; gestión de dispositivos y licencias.
- Servidor MCP y API pública desde el inicio.

#### 5.2 Debilidades explotables en Perú

1. **Buk:** implementación larga, permisos gruesos, biometría limitada, lentitud y costo que crece por módulo. Oportunidad: puesta en marcha rápida con plantillas peruanas, permisos finos e integración abierta de relojes.
2. **Talana y Mandü/Rex+:** huecos en SST, compensaciones, activos, gastos y canal de denuncias; Mandü y Rex+ en transición de marca. Oportunidad: suite realmente completa.
3. **Ofisis y planilla tradicional:** cálculo sólido, experiencia del colaborador pobre. Oportunidad: igual profundidad contable con app y autoservicio modernos.
4. **Globales:** sin planilla peruana y con actualización normativa lenta. Oportunidad: cumplimiento local con garantía (al estilo "Cero Multas" de Worky) y actualización de parámetros el mismo día.
5. **Humand y GeoVictoria:** excelentes en personal operativo pero obligan a integrar una planilla. Oportunidad: unir comunicación, marcación y planilla en un solo producto.
6. **Mercado MYPE y estudios contables:** un modelo multiempresa permite que un estudio administre muchas planillas pequeñas, segmento que Buk y Talana no priorizan.
7. **Cumplimiento como producto:** un tablero de obligaciones por empresa (comité de SST, comité de hostigamiento, cuota, lactario, política salarial, Vida Ley, casilla) con semáforos y evidencias. Ningún competidor lo presenta así.

---

### Fuentes

**Competidores**
- [Buk Perú: sitio oficial](https://www.buk.pe/)
- [Buk: comparativa de software de RR. HH. en Perú 2026](https://www.buk.pe/blog/software-de-recursos-humanos-en-peru-comparativa-2026)
- [Capterra: Buk](https://www.capterra.com/p/208706/Buk/)
- [Talana Perú: sitio oficial](https://web.talana.com/es-pe/)
- [Talana: módulos Perú](https://conoce.talana.com/modulos-peru)
- [Rex+ Perú](https://rexmas.com.pe/)
- [Mandü](https://mandu.pe/)
- [Mandü: comparativa para medianas empresas 2026](https://mandu.pe/blog/mejor-software-de-rrhh-en-peru-para-medianas-empresas-comparativa-2026/)
- [GeoVictoria: futuro del control de asistencia en Perú](https://www.geovictoria.com/es-pe/blog/el-futuro-del-control-de-asistencia-peru)
- [GeoVictoria: Time & Attendance](https://www.geovictoria.com/en-us/services/time-attendance/)
- [Softland: Ofisis Personas](https://softland.com/pe/ofisis-personas-software-de-planillas-en-peru/)
- [Worky](https://www.worky.mx/)
- [Runa HR](https://runahr.com/mx/)
- [Humand](https://humand.co/es/)
- [Humand: guía de software de RR. HH. en México 2026](https://humand.co/es/compara/software-rrhh-mexico/)
- [Rankmi](https://www.rankmi.com/)
- [Lapzo](https://www.lapzo.com/)
- [Laborum para empresas (Krowdy)](https://empresas.laborum.pe/)
- [Josh Bersin: The Reinvention of Workday](https://joshbersin.com/2026/04/the-reinvention-of-workday-from-system-of-record-to-platform-of-agents/)
- [People Managing People: BambooHR review](https://peoplemanagingpeople.com/tools/bamboohr-review/)
- [People Managing People: Rippling review](https://peoplemanagingpeople.com/tools/rippling-review/)
- [Siit: Deel review](https://www.siit.io/tools/trending/deel-review)
- [HiBob](https://www.hibob.com/)
- [Personio](https://www.personio.com/)
- [Rework: Best Personio Alternatives 2026](https://resources.rework.com/tools/hr-people/best-personio-alternatives)
- [Factorial](https://factorialhr.com/)
- [Sesame HR](https://www.sesamehr.com/)

**Tendencias**
- [HRStacks: 17 Top HR Technology Trends in 2026](https://www.hrstacks.com/top-hr-technology-trends/)

**Normativa peruana**
- [El Comercio: UIT 2026 sube a S/ 5.500](https://elcomercio.pe/economia/peru/uit-2026-sube-a-s-5500-como-impactara-el-nuevo-valor-en-impuestos-multas-y-retiros-de-las-afp-mef-noticia/)
- [Tawa: impuesto a la renta de quinta categoría 2026](https://www.tawa.com.pe/blog/impuesto-a-la-renta-de-quinta-categoria)
- [Consultoría Aricruz: planillas y beneficios laborales 2026](https://consultoriaaricruz.pe/blog/planillas-beneficios-laborales-peru)
- [Modelo.pe: descuentos en boleta de pago 2026](https://modelo.pe/blog/descuentos-boleta-de-pago-peru-2026/)
- [SueldoJusto: régimen MYPE vs general 2026](https://sueldojusto.pe/blog/regimen-mype-peru-beneficios/)
- [Noticiero Contable: Seguro de Vida Ley 2026](https://noticierocontable.com/seguro-de-vida-ley/)
- [LP Derecho: firma electrónica en documentos laborales (Informe 0135-2021-MTPE)](https://lpderecho.pe/usar-firma-electronica-documento-laboral-informe-0135-2021-mtpe-2-14-1/)
- [Iteria: qué exige el DS 004-2006-TR](https://iteriastudio.com/guias/ds-004-2006-tr-registro-asistencia/)
- [Buk: teletrabajo, Ley 31572](https://www.buk.pe/blog/teletrabajo-peru-ley-31572)
- [Iván Centeno: registros obligatorios de la Ley 29783](https://www.ivancenteno.com/registros-obligatorios-ley-29783/)
- [Buk: Ley 27942 de hostigamiento sexual](https://www.buk.pe/blog/ley-27942-prevencion-sancion-hostigamiento-sexual)
- [Buk: política salarial y SUNAFIL](https://www.buk.pe/blog/politica-salarial-sunafil)
- [Kom.pe: nuevo reglamento de protección de datos (DS 016-2024-JUS)](https://kom.pe/reglamento-ds-016-2024-jus-datos-personales/)
- [LP Derecho: cuota de empleo de personas con discapacidad](https://lpderecho.pe/cuales-son-las-reglas-aplicables-a-los-empleadores-sobre-la-cuota-de-empleo-de-personal-con-discapacidad/)
- [La Cámara: contratos de trabajo a plazo fijo](https://lacamara.pe/contratos-de-trabajo-a-plazo-fijo-reglas-y-recomendaciones/)
- [Iván Centeno: casilla electrónica SUNAFIL](https://www.ivancenteno.com/uso-obligatorio-casilla-electronica-sunafil/)

