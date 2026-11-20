# 20 · Entrega al equipo de desarrollo

Generado por `kit/tools/gen_entrega.py` desde el backlog, la API, el catálogo de módulos y las decisiones. No se edita a mano.

Este documento es la puerta de entrada técnica para quienes construyen GestiaHR con asistentes de IA (Claude Code). Dice qué ya está resuelto en el kit, qué falta construir, en qué orden y cómo repartirlo. No trae tiempos, puntos ni responsables: los define el equipo al planificar.

## 1. Qué recibe el equipo

| Pieza | Dónde | Estado |
|---|---|---|
| Reglas para la IA | `CLAUDE.md` (15 reglas no negociables) y `.claude/commands/` (`/historia`, `/migracion`, `/endpoint`, `/pantalla`, `/revisar`) | Listo; se copia a la raíz del repositorio |
| Arranque del repositorio | `kit/referencia/arranque/` | Docker, `Makefile` y la integración continua del kit, comprobados con PostgreSQL 17 |
| Especificación | `kit/docs/00` a `kit/docs/19`, `kit/docs/modulos/` (una por épica) y `kit/docs/adr/` | Completa para las 72 épicas |
| Modelo de datos | `kit/database/schema.sql`, `kit/docs/04` | 495 tablas con RLS, FK compuestas y vigencias; carga en PostgreSQL 17 y pasa `pruebas_aislamiento.sql` |
| API | `kit/api/openapi.yaml`, tabla en `kit/docs/08` | OpenAPI 3.0.3 válido; cada operación con permiso y fase |
| Backlog | `kit/backlog/` y `GestiaHR_backlog_jira.csv` | 512 actividades, 241 del MVP, con criterios y dependencias; sin tiempos ni responsables |
| Orden de trabajo | `kit/backlog/ruta-mvp.md` | Tandas que se pueden trabajar en paralelo |
| Prompts por épica | `kit/prompts/` | Uno por épica, con las actividades en orden y sus decisiones |
| Código de referencia | `kit/referencia/calculadora-planilla/` | Núcleo de planilla en Laravel 12 con 46 pruebas |
| Diseño | `kit/prototipo/GestiaHR_prototipo.html`, `kit/docs/10`, `kit/docs/web/sitio/` | Prototipo del sistema y sitio de referencia de 47 pantallas |

**Qué no está hecho:** la aplicación. No existen todavía `apps/api`, `apps/web` ni `packages/contracts`; los crea PLT-1. El código de `kit/referencia/` muestra cómo se arma una parte, pero no reemplaza las historias: se toma como punto de partida y se lleva a la estructura de módulos de `CLAUDE.md`.

## 2. Primer día

1. Crear el repositorio y descomprimir el zip en la raíz: `CLAUDE.md`, `.claude/`, `GestiaHR_backlog_jira.csv` y `kit/`.
2. Copiar el arranque: `cp -r kit/referencia/arranque/{docker-compose.yml,Makefile,.env.example,.gitignore,.github} .`
3. `make up` y `make kit-check`. Si pasan, el entorno y el kit están sanos.
4. Importar el CSV en Jira (`kit/EMPEZAR-AQUI.md` §5).
5. Abrir Claude Code en la raíz y empezar por `/historia PLT-1`. Con PLT-1 integrada se abren PLT-2, PLT-3, PLT-4, PLT-12, PLT-14 y UI-1, que van en paralelo.

## 3. Cómo repartir el trabajo entre varias personas con IA

- **Una actividad, una sesión, una rama, un pull request.** Cada persona abre una sesión de Claude Code por actividad, con `/historia <clave>`, en la rama `feat/<clave>-<resumen>` (`kit/docs/03` §6).
- **Se toma de la tanda libre.** `kit/backlog/ruta-mvp.md` agrupa en tandas lo que no depende entre sí. Dos personas pueden tomar dos actividades de la misma tanda sin pisarse.
- **Por épica, con su prompt.** Quien se queda con un módulo usa `kit/prompts/<ÉPICA>.md`: trae el orden interno, las dependencias de otras épicas y las decisiones abiertas.
- **Archivos compartidos.** `kit/api/openapi.yaml`, `kit/database/schema.sql` y el backlog son generados: nadie los edita a mano. Se cambia la fuente en `kit/tools/` y se regenera en la misma rama; ante un conflicto, se resuelve en la fuente y se vuelve a generar.
- **Migraciones.** Cada migración nace de `/migracion` y respeta `schema.sql`; si una historia necesita una columna que no está, se agrega primero al modelo (`kit/tools/esquema/*.dsl`) y se regenera.
- **Revisión.** `/revisar` antes de pedir revisión humana. El pull request lista los criterios de aceptación cumplidos uno por uno. La integración continua debe estar en verde.
- **Decisiones.** Si una actividad toca una decisión abierta, se aplica la recomendación del kit detrás de un parámetro o interfaz y se avisa en el pull request. Si la recomendación no alcanza, la persona se detiene y pregunta al responsable de producto.
- **Números legales.** Ninguno en el código. Si falta un valor, se crea el parámetro sin valor y marcado «por validar»; la planilla que lo use queda con error hasta que el especialista lo confirme (`kit/docs/19`).

## 4. Definición de hecho por actividad

Una actividad se integra cuando:

1. cumple cada criterio de aceptación de su historia;
2. tiene las pruebas mínimas de `/historia`: caso feliz, 403 sin permiso, 403 con el módulo apagado, 404 desde otra organización, 422, 409 si hay estados, auditoría y outbox, y campo sensible oculto sin permiso;
3. si toca planilla, tiene su caso dorado;
4. si cambió la API, actualizó `kit/tools/gen_openapi.py`, regeneró y ejecutó `make contracts`;
5. pasa `make test lint` y la integración continua;
6. la pantalla, si la hay, sigue el prototipo y los tokens de `kit/docs/10`, funciona en el celular y no tiene botones sin función.

## 5. Estado por módulo

Todas las épicas tienen especificación, tablas, operaciones de API e historias con criterios. «Avance» indica que el kit ya trae algo construido (§6). La columna de decisiones lista las abiertas que la tocan.

| Fase | Épica | Módulo comercial | Historias | Tareas | MVP | Operaciones API | Avance | Decisiones abiertas |
|---|---|---|---|---|---|---|---|---|
| 0 | [PLT](../prompts/PLT.md) · Plataforma técnica | Plataforma base | 0 | 14 | 14 | 4 | Sí | A-13, D-22 |
| 0 | [TEN](../prompts/TEN.md) · Multiempresa y aislamiento | Plataforma base | 1 | 5 | 6 | 3 | Sí | — |
| 0 | [SEG](../prompts/SEG.md) · Identidad, roles, permisos por campo y alcance | Plataforma base | 9 | 3 | 12 | 41 | Sí | D-23, D-24 |
| 0 | [SAS](../prompts/SAS.md) · Consola SaaS | — | 12 | 1 | 13 | 98 | Sí | D-19 |
| 0 | [AUD](../prompts/AUD.md) · Auditoría | Plataforma base | 1 | 4 | 5 | 11 | Sí | — |
| 0 | [UI](../prompts/UI.md) · Sistema de diseño y armazón | Plataforma base | 3 | 9 | 12 | 0 | Sí | — |
| 0 | [WFL](../prompts/WFL.md) · Motor de aprobaciones y workflows | Plataforma base | 5 | 4 | 9 | 42 | — | — |
| 0 | [NOT](../prompts/NOT.md) · Notificaciones y mensajería | Plataforma base | 2 | 4 | 6 | 19 | — | D-11 |
| 0 | [CFG](../prompts/CFG.md) · Configuración, campos personalizados, i18n y multimoneda | Plataforma base | 5 | 5 | 10 | 30 | — | — |
| 0 | [WEB](../prompts/WEB.md) · Sitio comercial gestiahr.com | — | 10 | 3 | 0 | 41 | Sí | D-39, D-40, D-41 |
| 1 | [ORG](../prompts/ORG.md) · Estructura organizacional | Core HR | 7 | 0 | 7 | 64 | — | — |
| 1 | [POS](../prompts/POS.md) · Posiciones y headcount | Core HR | 5 | 2 | 7 | 47 | — | D-05 |
| 1 | [COL](../prompts/COL.md) · Colaboradores y ficha 360 | Core HR | 10 | 2 | 12 | 63 | — | A-05, A-10, D-14, D-17, D-35 |
| 1 | [HIS](../prompts/HIS.md) · Historia con vigencia | Core HR | 3 | 3 | 6 | 19 | — | — |
| 1 | [CON](../prompts/CON-epica.md) · Contratos y adendas | Core HR | 5 | 1 | 6 | 41 | — | — |
| 1 | [DOC](../prompts/DOC.md) · Gestión documental, plantillas y firma | Core HR | 5 | 3 | 8 | 53 | — | A-11 |
| 1 | [ESS](../prompts/ESS.md) · Portal del colaborador | Core HR | 5 | 1 | 6 | 33 | — | A-12, D-23 |
| 1 | [MSS](../prompts/MSS.md) · Portal del manager | Core HR | 4 | 0 | 4 | 4 | — | — |
| 1 | [IMP](../prompts/IMP.md) · Importador y puesta en marcha | Core HR | 4 | 3 | 7 | 20 | — | — |
| 1 | [AYU](../prompts/AYU.md) · Centro de ayuda | Core HR | 6 | 1 | 7 | 25 | — | A-05, A-09, D-38 |
| 2 | [ASI](../prompts/ASI.md) · Asistencia y marcación | Asistencia y turnos | 7 | 4 | 0 | 20 | — | A-06, A-12, D-16 |
| 2 | [TUR](../prompts/TUR.md) · Horarios, jornadas y turnos | Asistencia y turnos | 6 | 0 | 0 | 21 | — | — |
| 2 | [VAC](../prompts/VAC.md) · Vacaciones | Vacaciones y ausencias | 5 | 2 | 7 | 41 | — | — |
| 2 | [AUS](../prompts/AUS.md) · Ausencias y permisos | Vacaciones y ausencias | 5 | 1 | 6 | 28 | — | — |
| 2 | [BHO](../prompts/BHO.md) · Horas extra y bolsa de horas | Asistencia y turnos | 3 | 2 | 0 | 8 | — | — |
| 2 | [TSH](../prompts/TSH.md) · Hojas de tiempo por proyecto | Hojas de tiempo | 4 | 0 | 0 | 12 | — | — |
| 3 | [NOM](../prompts/NOM.md) · Motor de nómina | Nómina Perú | 3 | 9 | 10 | 31 | Sí | A-10 |
| 3 | [CPT](../prompts/CPT.md) · Conceptos y fórmulas | Nómina Perú | 5 | 2 | 7 | 16 | Sí | — |
| 3 | [PAR](../prompts/PAR.md) · Parámetros legales con vigencia | Nómina Perú | 6 | 1 | 7 | 21 | Sí | D-36 |
| 3 | [PER](../prompts/PER.md) · Periodos, incidencias, cálculo, simulación y cierre | Nómina Perú | 9 | 4 | 13 | 47 | Sí | — |
| 3 | [BOL](../prompts/BOL.md) · Boletas de pago | Nómina Perú | 3 | 2 | 5 | 6 | — | A-11 |
| 3 | [BSO](../prompts/BSO.md) · Beneficios sociales | Nómina Perú | 3 | 2 | 5 | 6 | Sí | — |
| 3 | [PRE](../prompts/PRE.md) · Préstamos, adelantos y adelanto de sueldo | Préstamos y adelanto de sueldo | 3 | 2 | 0 | 15 | — | — |
| 3 | [DEC](../prompts/DEC.md) · Declaraciones y archivos | Nómina Perú | 9 | 2 | 11 | 32 | — | D-35 |
| 3 | [PRV](../prompts/PRV.md) · Provisiones y asientos contables | Nómina Perú | 4 | 1 | 0 | 6 | — | — |
| 3 | [LIQ](../prompts/LIQ.md) · Liquidación de beneficios sociales | Nómina Perú | 3 | 1 | 4 | 9 | Sí | — |
| 3 | [LOC](../prompts/LOC.md) · Locadores y cuarta categoría | Locadores | 3 | 1 | 0 | 12 | — | — |
| 4 | [ATS](../prompts/ATS.md) · Reclutamiento, portal de empleos y base de talento | Reclutamiento | 9 | 0 | 0 | 30 | — | D-05 |
| 4 | [ENT](../prompts/ENT.md) · Entrevistas y ofertas | Reclutamiento | 6 | 0 | 0 | 19 | — | — |
| 4 | [ONB](../prompts/ONB.md) · Pre-onboarding y onboarding | Onboarding y offboarding | 6 | 0 | 0 | 12 | — | D-17 |
| 4 | [OFF](../prompts/OFF.md) · Offboarding | Onboarding y offboarding | 4 | 1 | 0 | 7 | — | D-23 |
| 4 | [DES](../prompts/DES.md) · Evaluación de desempeño | Desempeño y objetivos | 6 | 0 | 0 | 13 | — | — |
| 4 | [OKR](../prompts/OKR.md) · Objetivos | Desempeño y objetivos | 4 | 1 | 0 | 7 | — | — |
| 4 | [CMP](../prompts/CMP.md) · Competencias y habilidades | Desempeño y objetivos | 4 | 0 | 0 | 12 | — | — |
| 4 | [FBK](../prompts/FBK.md) · Reuniones 1:1 y feedback | Desempeño y objetivos | 3 | 1 | 0 | 9 | — | — |
| 5 | [LMS](../prompts/LMS.md) · Aprendizaje y plan anual | Aprendizaje | 8 | 0 | 0 | 18 | — | — |
| 5 | [CAR](../prompts/CAR.md) · Desarrollo, carrera, sucesión y 9-box | Carrera y sucesión | 7 | 0 | 0 | 22 | — | — |
| 5 | [COM](../prompts/COM.md) · Compensaciones, bandas, revisión salarial e igualdad salarial | Compensaciones | 7 | 1 | 0 | 25 | — | A-10 |
| 5 | [BNF](../prompts/BNF.md) · Beneficios | Beneficios | 4 | 1 | 0 | 11 | — | — |
| 6 | [ENC](../prompts/ENC.md) · Encuestas, clima y eNPS | Clima y encuestas | 6 | 2 | 0 | 13 | — | D-26 |
| 6 | [REC](../prompts/REC.md) · Reconocimientos | Clima y encuestas | 4 | 0 | 0 | 4 | — | — |
| 6 | [CIN](../prompts/CIN.md) · Comunicación interna | Clima y encuestas | 4 | 0 | 0 | 7 | — | — |
| 6 | [HSD](../prompts/HSD.md) · Mesa de ayuda y base de conocimiento | Mesa de ayuda | 7 | 0 | 0 | 0 | — | — |
| 7 | [SST](../prompts/SST.md) · Seguridad y salud en el trabajo | Seguridad y salud | 10 | 1 | 0 | 54 | — | D-33 |
| 7 | [DEN](../prompts/DEN.md) · Canal de denuncias y hostigamiento | Denuncias | 7 | 1 | 0 | 20 | — | D-26, D-31, D-32 |
| 7 | [DIS](../prompts/DIS.md) · Casos disciplinarios | Denuncias | 4 | 0 | 0 | 8 | — | — |
| 7 | [ACT](../prompts/ACT.md) · Activos, EPP y uniformes | Activos y EPP | 6 | 0 | 0 | 13 | — | — |
| 7 | [GAS](../prompts/GAS.md) · Gastos, viáticos y reembolsos | Gastos y viáticos | 6 | 0 | 0 | 20 | — | — |
| 7 | [CUM](../prompts/CUM.md) · Cumplimiento Perú y SUNAFIL | Cumplimiento Perú | 7 | 2 | 1 | 29 | — | D-34, D-37 |
| 7 | [PRI](../prompts/PRI.md) · Privacidad y retención | Cumplimiento Perú | 6 | 1 | 0 | 22 | — | D-14 |
| 7 | [SIN](../prompts/SIN.md) · Sindicatos y convenios | Cumplimiento Perú | 3 | 1 | 0 | 14 | — | D-33 |
| 8 | [ANA](../prompts/ANA.md) · People analytics y tableros | Analítica y reportes | 4 | 2 | 0 | 7 | — | A-07, D-20 |
| 8 | [RPT](../prompts/RPT.md) · Reportes y constructor | Analítica y reportes | 4 | 1 | 0 | 10 | — | A-07 |
| 8 | [ALE](../prompts/ALE.md) · Alertas explicadas | Analítica y reportes | 3 | 1 | 0 | 12 | — | D-20 |
| 8 | [IAR](../prompts/IAR.md) · Asistente de IA y gobierno | Asistente de IA | 7 | 5 | 5 | 12 | — | A-09, D-21, D-38 |
| 9 | [API](../prompts/API.md) · API pública, webhooks y MCP | API e integraciones | 3 | 2 | 0 | 14 | — | D-17, D-18, D-19, D-21 |
| 9 | [INT](../prompts/INT.md) · Integraciones | API e integraciones | 7 | 0 | 0 | 9 | — | — |
| 9 | [WHA](../prompts/WHA.md) · Autoservicio por WhatsApp | WhatsApp | 4 | 1 | 0 | 3 | — | D-11 |
| 9 | [PAI](../prompts/PAI.md) · Paquetes de país | — | 1 | 3 | 0 | 3 | Sí | D-37 |
| 9 | [WLB](../prompts/WLB.md) · Marca blanca | Marca blanca | 3 | 1 | 0 | 2 | — | D-40 |
| 9 | [EST](../prompts/EST.md) · Modo estudio contable | Estudio contable | 5 | 0 | 0 | 4 | — | D-28 |
| T | [QA](../prompts/QA.md) · Calidad, seguridad y salida a producción | — | 0 | 17 | 3 | 0 | — | A-06, A-13, D-22, D-24 |

## 6. Lo que el kit ya trae construido

| Épica | Avance |
|---|---|
| PLT | `kit/referencia/arranque/`: Docker con PostgreSQL 17, Redis, S3 y correo de pruebas; `Makefile`; integración continua del kit |
| TEN | `kit/database/pruebas_aislamiento.sql`: RLS, FK compuestas y tablas inalterables, comprobado en PostgreSQL 17 |
| AUD | Auditoría particionada por mes y de solo inserción en `schema.sql`, con su prueba en `pruebas_aislamiento.sql` |
| SEG | 908 permisos y 11 roles en `kit/database/catalogos/permisos_y_roles.json` |
| SAS | Planes y complementos como datos iniciales (`docs/01` §4.2, ADR 0013) |
| UI | Tokens de diseño en `docs/10` y prototipo navegable en `kit/prototipo/` |
| WEB | Sitio de referencia de 47 pantallas en `kit/docs/web/sitio/`, con SEO, textos legales y blog (`kit/tools/sitio/`) |
| NOM | `kit/referencia/calculadora-planilla/`: pasos configurables, mes, prorrateo y regímenes agrario y de construcción civil |
| PAR | `kit/referencia/calculadora-planilla/`: repositorio de parámetros con vigencia; 166 parámetros de Perú en el catálogo |
| CPT | `kit/referencia/calculadora-planilla/`: líneas remunerativas y computables; 62 conceptos en `catalogos/conceptos_pe.json` |
| PER | `kit/referencia/calculadora-planilla/`: quinta proyectada, pagos extraordinarios y regularización de diciembre |
| BSO | `kit/referencia/calculadora-planilla/`: provisiones de gratificación, bonificación extraordinaria y CTS |
| LIQ | `kit/referencia/calculadora-planilla/`: liquidación con truncos, indemnizaciones y quinta del cese |
| PAI | Paquete Perú: parámetros y conceptos en `kit/database/catalogos/` |

## 7. Decisiones abiertas que tocan el MVP

El MVP puede empezar con todas: se aplica la recomendación detrás de un parámetro o interfaz. Cada una se cierra antes de la fase indicada y se registra como ADR en `kit/docs/adr/`.

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| A-05 | Motor de búsqueda | Fase 1 (revisable en fase 8) | (a) al inicio, detrás de `SearchProvider`; pasar a (b) solo si las pruebas de carga lo exigen. |
| A-06 | Particionado de tablas grandes | Fase 2 | (a). Particionar después, con datos, es mucho más costoso; además facilita la retención (se descartan particiones). |
| A-09 | Proveedor de IA y condiciones de datos | Fase 8 | (a) al inicio, tras el puerto `AiProvider`, con condiciones contractuales de no entrenamiento y retención mínima; habilitar (b) para planes altos. |
| A-10 | Cifrado de campo para sueldos | Fase 1 | (b). La planilla, las provisiones y la analítica necesitan sumar en la base; el control efectivo es el permiso por campo y el registro de accesos. Documento de identidad, cuenta bancaria, salud y denuncias sí van cifrados a nivel de campo. |
| A-11 | Generación de PDF | Fase 1 | (a) tras el puerto `PdfRenderer`: misma maquetación que la vista previa y buen soporte tipográfico. El servicio corre sin red y sin acceso a datos. |
| A-12 | App móvil: web instalable o nativa | Fase 2 | (c). La API ya lo permite; la PWA cubre los flujos del colaborador. |
| A-13 | Objetivos de recuperación y restauración por organización | Fase 0 (salida a producción) | recuperación a un punto en el tiempo + (b) mediante exportación e importación por `tenant_id` desde un entorno aislado. (c) se cubre con papelera e historia con vigencia. |
| D-05 | Política «no contratar sin posición aprobada» | Fase 1 | (b), apagada por defecto para organizaciones pequeñas; cuando está apagada, el alta crea la posición de forma implícita para que el organigrama por posiciones siga funcionando. |
| D-11 | Proveedor de WhatsApp y alcance del autoservicio | Segunda ola (notificaciones: fase 0) | notificaciones desde la fase 0 tras el puerto; autoservicio (b) en la fase 9. Elegir proveedor según requisitos de plantilla, alojamiento de datos y condiciones. |
| D-14 | Privacidad: qué va en la base y qué en el módulo de cumplimiento | Fase 1 | (c). Base: avisos, consentimientos, derechos del titular, registro de accesos, retención. Módulo: inventario de tratamientos, evaluaciones de impacto, registro de incidentes con seguimiento, informes. |
| D-17 | Eventos públicos: `employee.created` frente a `employee.hired` | Fase 1 | (c), porque el pedido original nombra ambos y las integraciones suelen esperar `employee.created`. Si se necesita reaccionar al pre-ingreso, usar `offer.accepted`. |
| D-19 | Límites de uso de API por plan | Fase 9 | por plan, como parámetros de plataforma, con límites más estrictos en rutas públicas, autenticación e IA. |
| D-21 | Herramientas del servidor MCP | Fase 9 | (b), lista cerrada y versionada; nada de alto impacto. |
| D-22 | Presupuestos de rendimiento | Fase 1 | fijarlos como umbrales de la prueba de carga (`11` §11) antes de construir la fase 1, para que el diseño de índices y proyecciones se mida contra ellos. |
| D-23 | Acceso del colaborador tras el cese | Fase 1 | (b), con duración configurable por organización; no cuenta para el límite del plan. |
| D-24 | Certificaciones de seguridad objetivo | Fase 0 (salida a producción) | diseñar los controles desde la fase 0 conforme a `12` y decidir el calendario de certificación con el negocio. |
| D-34 | Sello de tiempo externo y forma canónica del libro de cumplimiento | Fase 7 | RFC 8785 en la aplicación, ancla diaria y al exportar, proveedor detrás de un adaptador con implementación falsa. Sin proveedor, el libro prueba orden e integridad, no fecha cierta ante terceros. |
| D-35 | Enumerados ampliados de documento, derechohabiente y novedad del registro laboral | Fase 3 | (a) mientras Perú sea el único paquete; revisar al diseñar el segundo país. |
| D-36 | Qué fuente secundaria basta para marcar un parámetro legal como verificado | Fase 3 | (b) antes de producción: el cierre de planilla debería exigir verificación de especialista, no de fuente secundaria (RN-PAR-03). |
| D-37 | Plantillas de obligaciones de cumplimiento del paquete Perú | Fase 7 | (a). Queda pendiente: no hay hoy un catálogo de plantillas en el kit donde aplicarlo. |
| D-38 | Asistente de ayuda: estado por defecto, proveedor y forma de búsqueda | Fase 1 (MVP) | (a) y (c). Apagado por defecto cumple RN-IAR-05 y evita enviar preguntas a un proveedor sin que la organización lo decida; el texto completo basta para cientos de artículos y no agrega infraestructura. Pasar a (d) solo si IAR-11 muestra muchas preguntas sin fuente que sí tenían artículo. Propuesta en [ADR 0003](adr/0003-asistente-de-ayuda.md). |

## 8. Lo que aporta el negocio

- **Parámetros legales:** 54 sin valor verificado, 47 de ellos del MVP (`kit/docs/19`). Los valida el especialista laboral y se cargan en `kit/tools/esquema/catalogos.py`.
- **Textos legales:** revisión del abogado de lo marcado [validar] y datos de la empresa marcados [por definir] (`kit/tools/sitio/legales.py`).
- **Precios definitivos**, razón social, RUC, dominio `gestiahr.com` y registro de la marca.
- **Clientes, logos y testimonios reales**, con su permiso.

## 9. Cómo se mantiene este documento

Se regenera con `python3 kit/tools/gen_entrega.py` después de cambiar el backlog, la API o las decisiones. Cuando se cierra una decisión, se actualiza `kit/docs/13` y, si cambia a qué épicas afecta, `DECISION_EPICAS` en el generador. Cuando el kit suma código de referencia, se agrega a `AVANCE`.
