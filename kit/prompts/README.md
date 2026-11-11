# Prompts por épica

Generado por `kit/tools/gen_entrega.py`. No se edita a mano.

Cada archivo trae el prompt para avanzar una épica en una sesión nueva de Claude Code, sus actividades en el orden en que se desbloquean, las dependencias de otras épicas y las decisiones abiertas que la tocan. Para repartir el trabajo entre varias personas, sigue `kit/backlog/ruta-mvp.md`: cada tanda se puede trabajar en paralelo.

| Fase | Épica | Actividades | MVP | Prompt |
|---|---|---|---|---|
| 0 | PLT · Plataforma técnica | 14 | 14 | [PLT.md](PLT.md) |
| 0 | TEN · Multiempresa y aislamiento | 6 | 6 | [TEN.md](TEN.md) |
| 0 | SEG · Identidad, roles, permisos por campo y alcance | 12 | 12 | [SEG.md](SEG.md) |
| 0 | SAS · Consola SaaS | 13 | 13 | [SAS.md](SAS.md) |
| 0 | AUD · Auditoría | 5 | 5 | [AUD.md](AUD.md) |
| 0 | UI · Sistema de diseño y armazón | 12 | 12 | [UI.md](UI.md) |
| 0 | WFL · Motor de aprobaciones y workflows | 9 | 9 | [WFL.md](WFL.md) |
| 0 | NOT · Notificaciones y mensajería | 6 | 6 | [NOT.md](NOT.md) |
| 0 | CFG · Configuración, campos personalizados, i18n y multimoneda | 10 | 10 | [CFG.md](CFG.md) |
| 0 | WEB · Sitio comercial gestiahr.com | 13 | 0 | [WEB.md](WEB.md) |
| 1 | ORG · Estructura organizacional | 7 | 7 | [ORG.md](ORG.md) |
| 1 | POS · Posiciones y headcount | 7 | 7 | [POS.md](POS.md) |
| 1 | COL · Colaboradores y ficha 360 | 12 | 12 | [COL.md](COL.md) |
| 1 | HIS · Historia con vigencia | 6 | 6 | [HIS.md](HIS.md) |
| 1 | CON · Contratos y adendas | 6 | 6 | [CON-epica.md](CON-epica.md) |
| 1 | DOC · Gestión documental, plantillas y firma | 8 | 8 | [DOC.md](DOC.md) |
| 1 | ESS · Portal del colaborador | 6 | 6 | [ESS.md](ESS.md) |
| 1 | MSS · Portal del manager | 4 | 4 | [MSS.md](MSS.md) |
| 1 | IMP · Importador y puesta en marcha | 7 | 7 | [IMP.md](IMP.md) |
| 1 | AYU · Centro de ayuda | 7 | 7 | [AYU.md](AYU.md) |
| 2 | ASI · Asistencia y marcación | 11 | 0 | [ASI.md](ASI.md) |
| 2 | TUR · Horarios, jornadas y turnos | 6 | 0 | [TUR.md](TUR.md) |
| 2 | VAC · Vacaciones | 7 | 7 | [VAC.md](VAC.md) |
| 2 | AUS · Ausencias y permisos | 6 | 6 | [AUS.md](AUS.md) |
| 2 | BHO · Horas extra y bolsa de horas | 5 | 0 | [BHO.md](BHO.md) |
| 2 | TSH · Hojas de tiempo por proyecto | 4 | 0 | [TSH.md](TSH.md) |
| 3 | NOM · Motor de nómina | 12 | 10 | [NOM.md](NOM.md) |
| 3 | CPT · Conceptos y fórmulas | 7 | 7 | [CPT.md](CPT.md) |
| 3 | PAR · Parámetros legales con vigencia | 7 | 7 | [PAR.md](PAR.md) |
| 3 | PER · Periodos, incidencias, cálculo, simulación y cierre | 13 | 13 | [PER.md](PER.md) |
| 3 | BOL · Boletas de pago | 5 | 5 | [BOL.md](BOL.md) |
| 3 | BSO · Beneficios sociales | 5 | 5 | [BSO.md](BSO.md) |
| 3 | PRE · Préstamos, adelantos y adelanto de sueldo | 5 | 0 | [PRE.md](PRE.md) |
| 3 | DEC · Declaraciones y archivos | 11 | 11 | [DEC.md](DEC.md) |
| 3 | PRV · Provisiones y asientos contables | 5 | 0 | [PRV.md](PRV.md) |
| 3 | LIQ · Liquidación de beneficios sociales | 4 | 4 | [LIQ.md](LIQ.md) |
| 3 | LOC · Locadores y cuarta categoría | 4 | 0 | [LOC.md](LOC.md) |
| 4 | ATS · Reclutamiento, portal de empleos y base de talento | 9 | 0 | [ATS.md](ATS.md) |
| 4 | ENT · Entrevistas y ofertas | 6 | 0 | [ENT.md](ENT.md) |
| 4 | ONB · Pre-onboarding y onboarding | 6 | 0 | [ONB.md](ONB.md) |
| 4 | OFF · Offboarding | 5 | 0 | [OFF.md](OFF.md) |
| 4 | DES · Evaluación de desempeño | 6 | 0 | [DES.md](DES.md) |
| 4 | OKR · Objetivos | 5 | 0 | [OKR.md](OKR.md) |
| 4 | CMP · Competencias y habilidades | 4 | 0 | [CMP.md](CMP.md) |
| 4 | FBK · Reuniones 1:1 y feedback | 4 | 0 | [FBK.md](FBK.md) |
| 5 | LMS · Aprendizaje y plan anual | 8 | 0 | [LMS.md](LMS.md) |
| 5 | CAR · Desarrollo, carrera, sucesión y 9-box | 7 | 0 | [CAR.md](CAR.md) |
| 5 | COM · Compensaciones, bandas, revisión salarial e igualdad salarial | 8 | 0 | [COM.md](COM.md) |
| 5 | BNF · Beneficios | 5 | 0 | [BNF.md](BNF.md) |
| 6 | ENC · Encuestas, clima y eNPS | 8 | 0 | [ENC.md](ENC.md) |
| 6 | REC · Reconocimientos | 4 | 0 | [REC.md](REC.md) |
| 6 | CIN · Comunicación interna | 4 | 0 | [CIN.md](CIN.md) |
| 6 | HSD · Mesa de ayuda y base de conocimiento | 7 | 0 | [HSD.md](HSD.md) |
| 7 | SST · Seguridad y salud en el trabajo | 11 | 0 | [SST.md](SST.md) |
| 7 | DEN · Canal de denuncias y hostigamiento | 8 | 0 | [DEN.md](DEN.md) |
| 7 | DIS · Casos disciplinarios | 4 | 0 | [DIS.md](DIS.md) |
| 7 | ACT · Activos, EPP y uniformes | 6 | 0 | [ACT.md](ACT.md) |
| 7 | GAS · Gastos, viáticos y reembolsos | 6 | 0 | [GAS.md](GAS.md) |
| 7 | CUM · Cumplimiento Perú y SUNAFIL | 9 | 1 | [CUM.md](CUM.md) |
| 7 | PRI · Privacidad y retención | 7 | 0 | [PRI.md](PRI.md) |
| 7 | SIN · Sindicatos y convenios | 4 | 0 | [SIN.md](SIN.md) |
| 8 | ANA · People analytics y tableros | 6 | 0 | [ANA.md](ANA.md) |
| 8 | RPT · Reportes y constructor | 5 | 0 | [RPT.md](RPT.md) |
| 8 | ALE · Alertas explicadas | 4 | 0 | [ALE.md](ALE.md) |
| 8 | IAR · Asistente de IA y gobierno | 12 | 5 | [IAR.md](IAR.md) |
| 9 | API · API pública, webhooks y MCP | 5 | 0 | [API.md](API.md) |
| 9 | INT · Integraciones | 7 | 0 | [INT.md](INT.md) |
| 9 | WHA · Autoservicio por WhatsApp | 5 | 0 | [WHA.md](WHA.md) |
| 9 | PAI · Paquetes de país | 4 | 0 | [PAI.md](PAI.md) |
| 9 | WLB · Marca blanca | 4 | 0 | [WLB.md](WLB.md) |
| 9 | EST · Modo estudio contable | 5 | 0 | [EST.md](EST.md) |
| T | QA · Calidad, seguridad y salida a producción | 17 | 3 | [QA.md](QA.md) |
