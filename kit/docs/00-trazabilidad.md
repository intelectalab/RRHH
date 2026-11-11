# 00 · Trazabilidad del pedido original

GestiaHR · kit de desarrollo. Demuestra que cada uno de los 124 puntos de `docs/00-pedido-original.md` queda cubierto por al menos una épica del catálogo de `docs/00-especificacion-maestra.md`, y dónde se concretan las correcciones (C-01 a C-12) y ampliaciones (E-01 a E-27).

Cómo usarla: la columna «Documento(s)» lleva a la especificación del módulo (`kit/docs/modulos/`). Dentro de cada módulo, la sección 4 repite esta trazabilidad en sentido inverso. Documentos de apoyo: `05-maquinas-de-estado.md`, `06-reglas-de-negocio.md`, `07-permisos-y-roles.md`, `14-localizacion-peru.md`.

Notas:
- Los puntos de precio de planes (§3) se cubren como **campos configurables del plan** en la consola SaaS; este kit no fija ningún precio.
- Los puntos de proceso (§120 a §123) se cubren en `M-QA.md` como reglas de trabajo, sin plazos ni estimaciones.
- El pedido ubicaba la plataforma SaaS en su «Fase 9»; por la corrección C-04 pasa a la Fase 0.

## 1. Los 124 puntos del pedido original

| § | Punto del pedido | Épica(s) | Documento(s) | Cómo queda cubierto |
|---|---|---|---|---|
| 1 | Arquitectura SaaS multitenant | TEN, PLT, ORG | `modulos/M-TEN.md`, `modulos/M-PLT.md`, `modulos/M-ORG.md` | Jerarquía plataforma → organización → empresas → sedes → unidades → posiciones → relaciones; aislamiento por organización con prueba automática |
| 2 | Superadministrador del SaaS | SAS | `modulos/M-SAS.md` | Consola separada: organizaciones, planes, suscripciones, módulos, límites, consumo, estado de cuenta, bloqueos, soporte, logs, auditoría, copias, configuración global y tablero SaaS |
| 3 | Planes y suscripciones | SAS, SEG | `modulos/M-SAS.md`, `modulos/M-SEG.md` | Planes de nombre libre, moneda, ciclo, límites, módulos, funcionalidades y complementos; permisos condicionados por la suscripción (ver 07, sección 4) |
| 4 | Estructura organizacional | ORG, TEN | `modulos/M-ORG.md`, `modulos/M-TEN.md` | Razones sociales, sedes, unidades de profundidad libre, centros de costo, puestos, niveles, categorías y organigrama dinámico |
| 5 | Headcount y planificación de plantilla | POS | `modulos/M-POS.md` | Posiciones con presupuesto, estados, flujo solicitud → ocupada, política de contratación contra posición y escenarios |
| 6 | Ficha 360° del colaborador | COL | `modulos/M-COL.md` | Persona + relación laboral con todas las pestañas; fuente única |
| 7 | Historial laboral | HIS | `modulos/M-HIS.md` | Registros con vigencia y línea de tiempo; nada se sobrescribe |
| 8 | Portal del colaborador | ESS | `modulos/M-ESS.md` | Autoservicio completo e inicio personal |
| 9 | Portal del manager | MSS | `modulos/M-MSS.md` | Vista de equipo y aprobaciones |
| 10 | Reclutamiento — ATS | ATS | `modulos/M-ATS.md` | Vacantes, tablero por etapas configurable, ficha del candidato |
| 11 | Portal de empleos | ATS | `modulos/M-ATS.md` | Portal público configurable con formulario y SEO |
| 12 | Talent pool | ATS | `modulos/M-ATS.md` | Base de talento con etiquetas, búsqueda y filtros |
| 13 | Entrevistas | ENT | `modulos/M-ENT.md` | Agenda, guía y evaluación estructurada |
| 14 | Ofertas laborales | ENT | `modulos/M-ENT.md` | Plantillas con variables y flujo borrador → aceptada o rechazada |
| 15 | Pre-onboarding | ONB | `modulos/M-ONB.md` | Portal del futuro colaborador y tareas previas |
| 16 | Onboarding | ONB | `modulos/M-ONB.md` | Diseñador de plantillas por hitos con avance |
| 17 | Offboarding | OFF, LIQ | `modulos/M-OFF.md`, `modulos/M-LIQ.md` | Tipos de salida, lista de comprobación, motivo; liquidación en LIQ |
| 18 | Contratos | CON | `modulos/M-CON.md` | Tipos, renovaciones, adendas, alertas de vencimiento y topes |
| 19 | Gestión documental | DOC | `modulos/M-DOC.md` | Repositorio por carpetas, permisos por documento, versiones, solicitud y aprobación |
| 20 | Generador de documentos | DOC | `modulos/M-DOC.md` | Plantillas con variables y PDF |
| 21 | Firma electrónica | DOC | `modulos/M-DOC.md` | Flujo de firma con evidencia propia y capa de proveedor |
| 22 | Asistencia | ASI | `modulos/M-ASI.md` | Marcación universal, cálculo diario, registro inalterable |
| 23 | Turnos | TUR | `modulos/M-TUR.md` | Planificador visual con arrastrar y soltar y asignación masiva |
| 24 | Horarios y jornadas | TUR, CFG | `modulos/M-TUR.md`, `modulos/M-CFG.md` | Jornadas, descansos, tolerancias, feriados y políticas por nivel |
| 25 | Vacaciones | VAC | `modulos/M-VAC.md` | Saldo por libro de movimientos, flujo de aprobación, calendario del equipo |
| 26 | Ausencias y permisos | AUS | `modulos/M-AUS.md` | Tipos configurables con reglas propias |
| 27 | Planillas / payroll | NOM, PER | `modulos/M-NOM.md`, `modulos/M-PER.md` | Motor + reglas + país + régimen; proceso periodo → contabilización |
| 28 | Localización Perú | PAI, BSO, DEC, PAR, LIQ | `modulos/M-PAI.md`, `modulos/M-BSO.md`, `modulos/M-DEC.md`, `modulos/M-PAR.md`, `modulos/M-LIQ.md` | Paquete Perú: conceptos, beneficios, archivos y parámetros con vigencia (documentos 14 y 18) |
| 29 | Motor de conceptos de nómina | CPT | `modulos/M-CPT.md` | Conceptos personalizados con fórmula, condiciones, vigencia, afectaciones y contabilización |
| 30 | Simulador de planilla | PER | `modulos/M-PER.md` | Comparativo y anomalías explicadas antes de cerrar |
| 31 | Boletas de pago | BOL | `modulos/M-BOL.md` | PDF, publicación automática, historial, descarga individual y masiva |
| 32 | Préstamos y adelantos | PRE | `modulos/M-PRE.md` | Cuotas, saldo, descuento automático, cronograma |
| 33 | Compensaciones | COM | `modulos/M-COM.md` | Componentes, bandas y posición en banda |
| 34 | Ciclos de revisión salarial | COM | `modulos/M-COM.md` | Campañas con cuatro niveles de aprobación y presupuesto |
| 35 | Beneficios | BNF | `modulos/M-BNF.md` | Catálogo, elegibilidad y consulta en el portal |
| 36 | Gastos y reembolsos | GAS | `modulos/M-GAS.md` | Registro con comprobante y flujo hasta el pago |
| 37 | Activos | ACT | `modulos/M-ACT.md` | Inventario, cinco estados e historial |
| 38 | Evaluación de desempeño | DES | `modulos/M-DES.md` | Campañas 90° a 360° con competencias, preguntas y escalas |
| 39 | Competencias | CMP | `modulos/M-CMP.md` | Catálogo con niveles y relación con puestos |
| 40 | Objetivos / OKR | OKR | `modulos/M-OKR.md` | Tres niveles, resultados clave, avance y jerarquía |
| 41 | 1:1 | FBK | `modulos/M-FBK.md` | Agenda, temas, notas, compromisos, notas privadas |
| 42 | Feedback continuo | FBK, REC | `modulos/M-FBK.md`, `modulos/M-REC.md` | Solicitar y dar feedback, seguimiento, integración con desempeño; reconocer en REC |
| 43 | Planes de desarrollo | CAR | `modulos/M-CAR.md` | Plan con competencia, niveles y acciones |
| 44 | Plan de carrera | CAR, CMP | `modulos/M-CAR.md`, `modulos/M-CMP.md` | Rutas con competencias requeridas y brechas |
| 45 | Sucesión | CAR | `modulos/M-CAR.md` | Posiciones críticas, sucesores, preparación y matriz |
| 46 | Matriz 9-box | CAR | `modulos/M-CAR.md` | Matriz visual sin decisiones automáticas |
| 47 | LMS / capacitación | LMS | `modulos/M-LMS.md` | Cursos, lecciones, evaluaciones, certificados, asignaciones y seguimiento |
| 48 | Plan anual de capacitación | LMS | `modulos/M-LMS.md` | Planificado frente a ejecutado |
| 49 | Encuestas | ENC | `modulos/M-ENC.md` | Constructor, tipos y anonimato configurable |
| 50 | eNPS | ENC | `modulos/M-ENC.md` | Cálculo, tendencia y protección de identidad |
| 51 | Reconocimientos | REC | `modulos/M-REC.md` | Valor, mensaje, insignia y muro opcional |
| 52 | Comunicación interna | CIN | `modulos/M-CIN.md` | Publicaciones segmentadas con registro de lectura |
| 53 | Mesa de ayuda RRHH | HSD | `modulos/M-HSD.md` | Solicitudes con cinco estados y SLA |
| 54 | Base de conocimiento RRHH | HSD, AYU | `modulos/M-HSD.md`, `modulos/M-AYU.md` | Artículos, categorías, buscador y relación con solicitudes |
| 55 | Workflows | WFL | `modulos/M-WFL.md` | Diseñador CUANDO → SI → ENTONCES |
| 56 | Aprobaciones | WFL | `modulos/M-WFL.md` | Motor transversal de N niveles, secuencial o paralelo |
| 57 | Seguridad y salud en el trabajo | SST | `modulos/M-SST.md` | Todos los registros del pedido, tablero y configuración por país |
| 58 | Canal de denuncias | DEN | `modulos/M-DEN.md` | Canal independiente con anonimato real y acceso restringido |
| 59 | Casos disciplinarios | DIS | `modulos/M-DIS.md` | Expediente con permisos especiales |
| 60 | Seguridad de información RRHH | SEG, PLT | `modulos/M-SEG.md`, `modulos/M-PLT.md` | RBAC, permisos granulares, MFA, sesiones, contraseñas, bloqueo, límite de intentos, cifrado, protección de archivos y control de descargas |
| 61 | Roles | SEG | `modulos/M-SEG.md` | Once roles de sistema y roles personalizados (documento 07) |
| 62 | Permisos granulares | SEG | `modulos/M-SEG.md` | Permisos `modulo.recurso.accion`, por campo y con alcance (documento 07) |
| 63 | Auditoría | AUD | `modulos/M-AUD.md` | Quién, qué, cuándo, desde dónde, antes y después; solo inserción |
| 64 | People analytics | ANA | `modulos/M-ANA.md` | Todos los indicadores y filtros del pedido |
| 65 | Dashboards personalizables | ANA, UI | `modulos/M-ANA.md`, `modulos/M-UI.md` | Widgets reordenables y tablero por rol |
| 66 | Reportes | RPT | `modulos/M-RPT.md` | Centro de reportes, exportación y programación |
| 67 | Constructor de reportes | RPT | `modulos/M-RPT.md` | Fuente → campos → filtros → agrupaciones → métricas → visualización, con control de datos sensibles |
| 68 | Alertas inteligentes | ALE | `modulos/M-ALE.md` | Alertas siempre con explicación |
| 69 | IA para RRHH | IAR, AYU | `modulos/M-IAR.md`, `modulos/M-AYU.md` | Nueve casos de uso con gobierno; sin decisiones de alto impacto |
| 70 | Búsqueda global | PLT, UI | `modulos/M-PLT.md`, `modulos/M-UI.md` | Búsqueda por tipo que respeta permisos |
| 71 | Importador masivo | IMP | `modulos/M-IMP.md` | Subir → mapear → validar → previsualizar → importar |
| 72 | Exportación | PLT, RPT | `modulos/M-PLT.md`, `modulos/M-RPT.md` | CSV, Excel y PDF respetando permisos |
| 73 | API | API | `modulos/M-API.md` | REST versionada con tokens y alcances |
| 74 | Webhooks | API | `modulos/M-API.md` | Eventos firmados con reintentos |
| 75 | Integraciones | INT | `modulos/M-INT.md` | Adaptadores por tipo de servicio |
| 76 | Centro de automatizaciones | WFL | `modulos/M-WFL.md` | Reglas activas, ejecuciones, errores, última y próxima ejecución |
| 77 | Tareas RRHH | WFL, ONB, OFF | `modulos/M-WFL.md`, `modulos/M-ONB.md`, `modulos/M-OFF.md` | Tareas internas con responsable, fecha, estado y prioridad, ligadas a procesos |
| 78 | Calendario RRHH | NOT | `modulos/M-NOT.md` | Calendario central con filtros |
| 79 | Configuración por empresa | CFG, TEN | `modulos/M-CFG.md`, `modulos/M-TEN.md` | Datos fiscales, logo, moneda, zona horaria, idioma, calendario, feriados y políticas |
| 80 | Multiidioma | CFG, UI | `modulos/M-CFG.md`, `modulos/M-UI.md` | es, en, pt con textos en archivos de traducción |
| 81 | Multimoneda | CFG | `modulos/M-CFG.md` | Moneda por empresa y compensación, tipos de cambio con fecha |
| 82 | Localización por país | PAI | `modulos/M-PAI.md` | Core + paquetes de país (documento 14, sección final) |
| 83 | Personalización / white label | WLB | `modulos/M-WLB.md` | Logo, nombre, color, favicon, dominio y correos según plan |
| 84 | Modo claro / oscuro | UI | `modulos/M-UI.md` | Dos temas diseñados por separado |
| 85 | Experiencia móvil | UI, ESS, ASI, AYU | `modulos/M-UI.md`, `modulos/M-ESS.md`, `modulos/M-ASI.md`, `modulos/M-AYU.md` | Responsive real y recorrido móvil del colaborador |
| 86 | Notificaciones | NOT | `modulos/M-NOT.md` | Centro, canales y preferencias |
| 87 | Bandeja «Pendientes» | WFL, UI | `modulos/M-WFL.md`, `modulos/M-UI.md` | Bandeja única de pendientes |
| 88 | Comando / creación rápida | UI | `modulos/M-UI.md` | Botón «+ Crear» según permiso |
| 89 | Copias de seguridad | SAS | `modulos/M-SAS.md` | Automáticas, manuales, historial, retención y restauración segura |
| 90 | Papelera | PLT | `modulos/M-PLT.md` | Eliminación lógica con restauración |
| 91 | Retención de datos | PRI | `modulos/M-PRI.md` | Políticas de retención, anonimización, eliminación y exportación |
| 92 | Privacidad | PRI, SEG | `modulos/M-PRI.md`, `modulos/M-SEG.md` | Consentimientos, accesos, descargas, solicitudes y anonimización con registro |
| 93 | Centro de configuración | CFG | `modulos/M-CFG.md` | Índice único por secciones |
| 94 | Campos personalizados | CFG | `modulos/M-CFG.md` | Once tipos de campo asignables a módulos |
| 95 | Workflow builder | WFL | `modulos/M-WFL.md` | Constructor visual con siete tipos de nodo |
| 96 | Diseño del sistema | UI | `modulos/M-UI.md` | Sistema de diseño con todos los componentes pedidos |
| 97 | No sobrecargar la interfaz | UI | `modulos/M-UI.md` | Divulgación progresiva |
| 98 | Tablas profesionales | UI | `modulos/M-UI.md` | Diez capacidades comunes a todas las tablas |
| 99 | Estados vacíos | UI | `modulos/M-UI.md` | Estados vacíos con acción |
| 100 | Validaciones | UI, PLT | `modulos/M-UI.md`, `modulos/M-PLT.md` | Validación en cliente y en servidor con mensajes claros |
| 101 | Performance | PLT, QA | `modulos/M-PLT.md`, `modulos/M-QA.md` | Paginación en servidor, índices, caché, colas y pruebas de carga |
| 102 | Arquitectura de datos | PLT, COL, QA | `modulos/M-PLT.md`, `modulos/M-COL.md`, `modulos/M-QA.md` | Modelo normalizado (documento de datos del kit y `schema.sql`) |
| 103 | Event-driven | PLT, WFL | `modulos/M-PLT.md`, `modulos/M-WFL.md` | Eventos de dominio con bandeja de salida que disparan automatizaciones |
| 104 | Integridad referencial | COL, HIS, PLT | `modulos/M-COL.md`, `modulos/M-HIS.md`, `modulos/M-PLT.md` | Persona, usuario, colaborador y relación laboral separados; nunca se destruye historial |
| 105 | UX de información sensible | SEG, UI | `modulos/M-SEG.md`, `modulos/M-UI.md` | Permisos por campo y módulo para sueldos, salud, denuncias y sanciones |
| 106 | Home RRHH | UI, ANA | `modulos/M-UI.md`, `modulos/M-ANA.md` | Inicio de RRHH con sus indicadores |
| 107 | Home manager | UI, MSS | `modulos/M-UI.md`, `modulos/M-MSS.md` | Inicio del manager |
| 108 | Home empleado | UI, ESS | `modulos/M-UI.md`, `modulos/M-ESS.md` | Inicio del colaborador |
| 109 | Centro de aprobaciones | WFL | `modulos/M-WFL.md` | Bandeja única con acciones masivas seguras |
| 110 | Logs técnicos | AUD | `modulos/M-AUD.md` | Actividad, auditoría y logs técnicos separados |
| 111 | Monitoreo SaaS | SAS | `modulos/M-SAS.md` | Errores, trabajos, uso de API, webhooks, almacenamiento, colas, integraciones |
| 112 | Feature flags | SAS | `modulos/M-SAS.md` | Activación por organización, plan, beta y país |
| 113 | Demo | QA, PLT | `modulos/M-QA.md`, `modulos/M-PLT.md` | Organización demo coherente que recorre el ciclo completo |
| 114 | No crear botones falsos | UI, QA | `modulos/M-UI.md`, `modulos/M-QA.md` | Regla de calidad bloqueante |
| 115 | Persistencia | PLT, QA, UI | `modulos/M-PLT.md`, `modulos/M-QA.md`, `modulos/M-UI.md` | Cambios persistentes y vistas conectadas |
| 116 | Una sola fuente de verdad | COL, HIS | `modulos/M-COL.md`, `modulos/M-HIS.md` | Los módulos consumen sueldo, jefe, puesto y saldos; no los duplican |
| 117 | Diseño API-first | PLT, API | `modulos/M-PLT.md`, `modulos/M-API.md` | Lógica en servicios y API |
| 118 | Modularidad comercial | SAS | `modulos/M-SAS.md` | Módulos por organización sin versiones distintas del código |
| 119 | Menú propuesto | UI | `modulos/M-UI.md` | Menú adaptado a permisos y módulos (rutas «Prototipo:» de cada módulo) |
| 120 | Estrategia de implementación | QA, SAS | `modulos/M-QA.md`, `modulos/M-SAS.md` | Fases 0 a 9 de docs/00-especificacion-maestra.md; la plataforma SaaS pasa a la fase 0 (C-04) |
| 121 | Proceso de desarrollo | QA | `modulos/M-QA.md` | Definición de terminado por épica |
| 122 | Si ya existe código | QA | `modulos/M-QA.md` | Inventario antes de reemplazar |
| 123 | Continuidad automática | QA | `modulos/M-QA.md` | Regla de trabajo del equipo y de los agentes |
| 124 | Objetivo final | SAS, PAI, QA | `modulos/M-SAS.md`, `modulos/M-PAI.md`, `modulos/M-QA.md` | Activación progresiva de módulos sin migrar; base peruana y paquetes de país |

## 2. Correcciones C-01 a C-12

| Código | Corrección | Épica(s) | Documento(s) | Dónde se concreta |
|---|---|---|---|---|
| C-01 | Persona ≠ usuario ≠ colaborador ≠ relación laboral | COL, SEG | `modulos/M-COL.md`, `modulos/M-SEG.md` | RN-COL-01 a 03, RN-SEG-09; ME-01 |
| C-02 | Puesto ≠ posición | ORG, POS | `modulos/M-ORG.md`, `modulos/M-POS.md` | Catálogo de puestos en ORG, plazas en POS; ME-03 |
| C-03 | Historia con vigencia | HIS, CON, CFG, PAR | `modulos/M-HIS.md`, `modulos/M-CON.md`, `modulos/M-CFG.md`, `modulos/M-PAR.md` | RN-HIS-01 a 09 |
| C-04 | La plataforma SaaS va en los cimientos | SAS, WFL, NOT, CFG | `modulos/M-SAS.md`, `modulos/M-WFL.md`, `modulos/M-NOT.md`, `modulos/M-CFG.md` | Fase 0 completa |
| C-05 | Planilla = motor + reglas + país + régimen | NOM, CPT, PAR, PAI | `modulos/M-NOM.md`, `modulos/M-CPT.md`, `modulos/M-PAR.md`, `modulos/M-PAI.md` | RN-NOM, RN-PAR; documentos 14 y 18 (estrategia por régimen) |
| C-06 | Anonimato real | ENC, DEN | `modulos/M-ENC.md`, `modulos/M-DEN.md` | RN-ENC-01 a 05, RN-DEN-01 a 03 |
| C-07 | Auditoría, actividad y logs separados; asistencia inalterable | AUD, ASI | `modulos/M-AUD.md`, `modulos/M-ASI.md` | RN-AUD-07, RN-ASI-01 |
| C-08 | IA con gobierno | IAR, PRI, CAR | `modulos/M-IAR.md`, `modulos/M-PRI.md`, `modulos/M-CAR.md` | RN-IAR; evaluación de impacto en PRI |
| C-09 | Acceso sin correo corporativo | SEG, ESS, NOT | `modulos/M-SEG.md`, `modulos/M-ESS.md`, `modulos/M-NOT.md` | RN-SEG-09, RN-NOT-07 |
| C-10 | Permisos por campo y alcance | SEG | `modulos/M-SEG.md` | Documento 07, secciones 2 y 3 |
| C-11 | Una sola fuente de verdad | COL, HIS, COM, VAC | `modulos/M-COL.md`, `modulos/M-HIS.md`, `modulos/M-COM.md`, `modulos/M-VAC.md` | RN-COL-05, RN-COM-01, RN-VAC-02 |
| C-12 | Firma electrónica con evidencia y capa de proveedor | DOC | `modulos/M-DOC.md` | RN-DOC-03 a 12; ME-10 |

## 3. Ampliaciones E-01 a E-27

| Código | Ampliación | Épica(s) | Documento(s) | Dónde se concreta |
|---|---|---|---|---|
| E-01 | Tablero de cumplimiento Perú | CUM | `modulos/M-CUM.md` | Obligaciones con semáforo, responsable y evidencia |
| E-02 | Expediente de inspección SUNAFIL y casilla electrónica | CUM | `modulos/M-CUM.md` | Expediente por trabajador y periodo; bandeja de requerimientos; libro inalterable encadenado (documento 18 §5) |
| E-03 | Provisiones y centralización contable | PRV | `modulos/M-PRV.md` | Provisiones, asiento por centro de costo, exportación |
| E-04 | Liquidación de beneficios sociales con simulador | LIQ | `modulos/M-LIQ.md` | Simulador y documentos de cese |
| E-05 | Igualdad salarial (Ley 30709) | COM, ORG | `modulos/M-COM.md`, `modulos/M-ORG.md` | Cuadro de categorías, política, brecha, evidencia |
| E-06 | Bolsa de horas | BHO | `modulos/M-BHO.md` | Compensación de sobretiempo con saldos |
| E-07 | Marcación universal | ASI, INT | `modulos/M-ASI.md`, `modulos/M-INT.md` | Web, app, kiosko, QR, PIN, cuadrilla, sin conexión, relojes |
| E-08 | Contratos a modalidad | CON | `modulos/M-CON.md` | Vencimiento, tope acumulado, renovación masiva con firma |
| E-09 | Adelanto de sueldo y billeteras | PRE, INT | `modulos/M-PRE.md`, `modulos/M-INT.md` | Adelanto sobre lo devengado y pago por billetera |
| E-10 | Organigrama por posiciones | POS, ORG | `modulos/M-POS.md`, `modulos/M-ORG.md` | Vacantes y presupuesto en el organigrama |
| E-11 | Autoservicio por WhatsApp | WHA, NOT | `modulos/M-WHA.md`, `modulos/M-NOT.md` | Canal de autoservicio tras la capa de mensajería |
| E-12 | EPP y uniformes | ACT, SST | `modulos/M-ACT.md`, `modulos/M-SST.md` | Tallas, entregas firmadas, vencimientos |
| E-13 | Hojas de tiempo por proyecto | TSH | `modulos/M-TSH.md` | Registro, aprobación y costeo |
| E-14 | Movilidad interna, referidos y matriz de habilidades | ATS, CMP | `modulos/M-ATS.md`, `modulos/M-CMP.md` | Vacantes internas, referidos, matriz de skills |
| E-15 | Hostigamiento sexual (Ley 27942) | DEN, LMS, CUM | `modulos/M-DEN.md`, `modulos/M-LMS.md`, `modulos/M-CUM.md` | Comité, medidas de protección, plazos por etapa (ME-48, documento 18 §5.2), capacitación |
| E-16 | Privacidad (Ley 29733, DS 016-2024-JUS) | PRI | `modulos/M-PRI.md` | Consentimientos, derechos, banco de datos, incidentes, evaluación de impacto |
| E-17 | Sindicatos y convenios | SIN, CPT | `modulos/M-SIN.md`, `modulos/M-CPT.md` | Afiliación, cuota, licencias, cláusulas como conceptos |
| E-18 | Locadores / cuarta categoría | LOC | `modulos/M-LOC.md` | Prestadores separados de la planilla |
| E-19 | Derechohabientes, seguros y regímenes | COL, DEC, CUM, BNF | `modulos/M-COL.md`, `modulos/M-DEC.md`, `modulos/M-CUM.md`, `modulos/M-BNF.md` | Ficha T-Registro con validación previa (documento 18 §1), Vida Ley, SCTR, EPS, régimen pensionario |
| E-20 | Teletrabajo (Ley 31572) | CON, AUS, CPT | `modulos/M-CON.md`, `modulos/M-AUS.md`, `modulos/M-CPT.md` | Acuerdo con contenido mínimo, desconexión, compensación no remunerativa |
| E-21 | Utilidades (DL 892) | BSO | `modulos/M-BSO.md` | Distribución por días y remuneraciones, liquidación individual |
| E-22 | API pública y servidor MCP | API | `modulos/M-API.md` | API, webhooks y MCP desde el inicio |
| E-23 | Viáticos y rendiciones | GAS | `modulos/M-GAS.md` | Políticas por puesto, anticipos y rendición |
| E-24 | Modo estudio contable | EST | `modulos/M-EST.md` | Consola de socio |
| E-25 | Clima con mapa de calor y planes de acción | ENC | `modulos/M-ENC.md` | Mapa de calor por área y seguimiento |
| E-26 | Riesgo de rotación explicable | CAR, ALE, IAR | `modulos/M-CAR.md`, `modulos/M-ALE.md`, `modulos/M-IAR.md` | Factores visibles, sin decisiones automáticas |
| E-27 | Puesta en marcha guiada | IMP, PAI | `modulos/M-IMP.md`, `modulos/M-PAI.md` | Asistente, plantillas peruanas, importador con validación |

## 4. Cobertura inversa: épicas y puntos que atienden

| Fase | Épica | Módulo | Puntos del pedido | Correcciones | Ampliaciones |
|---|---|---|---|---|---|
| 0 | PLT | Plataforma técnica | §1, §60, §70, §72, §90, §100, §101, §102, §103, §104, §113, §115, §117 | — | — |
| 0 | TEN | Multiempresa y aislamiento | §1, §4, §79 | — | — |
| 0 | SEG | Identidad, roles, permisos por campo y alcance | §3, §60, §61, §62, §92, §105 | C-01, C-09, C-10 | — |
| 0 | SAS | Consola SaaS | §2, §3, §89, §111, §112, §118, §120, §124 | C-04 | — |
| 0 | AUD | Auditoría, actividad y logs | §63, §110 | C-07 | — |
| 0 | UI | Sistema de diseño y armazón | §65, §70, §80, §84, §85, §87, §88, §96, §97, §98, §99, §100, §105, §106, §107, §108, §114, §115, §119 | — | — |
| 0 | WFL | Motor de aprobaciones y workflows | §55, §56, §76, §77, §87, §95, §103, §109 | C-04 | — |
| 0 | NOT | Notificaciones y mensajería | §78, §86 | C-04, C-09 | E-11 |
| 0 | CFG | Configuración, parámetros, campos personalizados, i18n y multimoneda | §24, §79, §80, §81, §93, §94 | C-03, C-04 | — |
| 0 | WEB | Sitio comercial gestiahr.com | — | — | — |
| 1 | ORG | Estructura organizacional | §1, §4 | C-02 | E-05, E-10 |
| 1 | POS | Posiciones y headcount | §5 | C-02 | E-10 |
| 1 | COL | Colaboradores y ficha 360 | §6, §102, §104, §116 | C-01, C-11 | E-19 |
| 1 | HIS | Historia con vigencia | §7, §104, §116 | C-03, C-11 | — |
| 1 | CON | Contratos y adendas | §18 | C-03 | E-08, E-20 |
| 1 | DOC | Gestión documental, plantillas y firma | §19, §20, §21 | C-12 | — |
| 1 | ESS | Portal del colaborador | §8, §85, §108 | C-09 | — |
| 1 | MSS | Portal del manager | §9, §107 | — | — |
| 1 | IMP | Importador y puesta en marcha | §71 | — | E-27 |
| 1 | AYU | Centro de ayuda | §54, §69, §85 | — | — |
| 2 | ASI | Asistencia y marcación | §22, §85 | C-07 | E-07 |
| 2 | TUR | Horarios, jornadas y turnos | §23, §24 | — | — |
| 2 | VAC | Vacaciones | §25 | C-11 | — |
| 2 | AUS | Ausencias y permisos | §26 | — | E-20 |
| 2 | BHO | Horas extra y bolsa de horas | — | — | E-06 |
| 2 | TSH | Hojas de tiempo por proyecto | — | — | E-13 |
| 3 | NOM | Motor de nómina | §27 | C-05 | — |
| 3 | CPT | Conceptos y fórmulas | §29 | C-05 | E-17, E-20 |
| 3 | PAR | Parámetros legales con vigencia | §28 | C-03, C-05 | — |
| 3 | PER | Periodos, incidencias, cálculo, simulación y cierre | §27, §30 | — | — |
| 3 | BOL | Boletas de pago | §31 | — | — |
| 3 | BSO | Beneficios sociales | §28 | — | E-21 |
| 3 | PRE | Préstamos, adelantos y adelanto de sueldo | §32 | — | E-09 |
| 3 | DEC | Declaraciones y archivos | §28 | — | E-19 |
| 3 | PRV | Provisiones y asientos contables | — | — | E-03 |
| 3 | LIQ | Liquidación de beneficios sociales | §17, §28 | — | E-04 |
| 3 | LOC | Locadores y cuarta categoría | — | — | E-18 |
| 4 | ATS | Reclutamiento, portal de empleos y base de talento | §10, §11, §12 | — | E-14 |
| 4 | ENT | Entrevistas y ofertas | §13, §14 | — | — |
| 4 | ONB | Pre-onboarding y onboarding | §15, §16, §77 | — | — |
| 4 | OFF | Offboarding | §17, §77 | — | — |
| 4 | DES | Evaluación de desempeño | §38 | — | — |
| 4 | OKR | Objetivos | §40 | — | — |
| 4 | CMP | Competencias y habilidades | §39, §44 | — | E-14 |
| 4 | FBK | Reuniones 1:1 y feedback continuo | §41, §42 | — | — |
| 5 | LMS | Aprendizaje y plan anual de capacitación | §47, §48 | — | E-15 |
| 5 | CAR | Desarrollo, carrera, sucesión y 9-box | §43, §44, §45, §46 | C-08 | E-26 |
| 5 | COM | Compensaciones, bandas, revisión salarial e igualdad salarial | §33, §34 | C-11 | E-05 |
| 5 | BNF | Beneficios | §35 | — | E-19 |
| 6 | ENC | Encuestas, clima y eNPS | §49, §50 | C-06 | E-25 |
| 6 | REC | Reconocimientos | §42, §51 | — | — |
| 6 | CIN | Comunicación interna | §52 | — | — |
| 6 | HSD | Mesa de ayuda y base de conocimiento | §53, §54 | — | — |
| 7 | SST | Seguridad y salud en el trabajo | §57 | — | E-12 |
| 7 | DEN | Canal de denuncias y hostigamiento | §58 | C-06 | E-15 |
| 7 | DIS | Casos disciplinarios | §59 | — | — |
| 7 | ACT | Activos, EPP y uniformes | §37 | — | E-12 |
| 7 | GAS | Gastos, viáticos y reembolsos | §36 | — | E-23 |
| 7 | CUM | Cumplimiento Perú y SUNAFIL | — | — | E-01, E-02, E-15, E-19 |
| 7 | PRI | Privacidad y retención | §91, §92 | C-08 | E-16 |
| 7 | SIN | Sindicatos y convenios | — | — | E-17 |
| 8 | ANA | People analytics y tableros | §64, §65, §106 | — | — |
| 8 | RPT | Reportes y constructor | §66, §67, §72 | — | — |
| 8 | ALE | Alertas explicadas | §68 | — | E-26 |
| 8 | IAR | Asistente de IA y gobierno | §69 | C-08 | E-26 |
| 9 | API | API pública, webhooks y MCP | §73, §74, §117 | — | E-22 |
| 9 | INT | Integraciones | §75 | — | E-07, E-09 |
| 9 | WHA | Autoservicio por WhatsApp | — | — | E-11 |
| 9 | PAI | Paquetes de país | §28, §82, §124 | C-05 | E-27 |
| 9 | WLB | Marca blanca | §83 | — | — |
| 9 | EST | Modo estudio contable | — | — | E-24 |
| Transversal | QA | Calidad, seguridad y salida a producción | §101, §102, §113, §114, §115, §120, §121, §122, §123, §124 | — | — |
