# 16 · Glosario

Términos de RRHH, de planilla peruana y del propio producto. Las cifras y plazos legales **no** se fijan aquí: son parámetros con vigencia (ver `docs/00-analisis-de-competencia.md` §5.4 para lo verificado y lo pendiente de validar). Entre paréntesis, el nombre en código cuando existe.

## Producto y plataforma

| Término | Definición |
|---|---|
| **Organización** (`tenant`) | Cliente de la plataforma. Unidad de aislamiento de datos. Puede tener varias razones sociales |
| **Razón social / empresa** (`legal_entity`) | Persona jurídica con RUC; empleador legal de una relación laboral. Define país, moneda, zona horaria y régimen por defecto |
| **Sede** (`site`) | Centro de trabajo de una razón social |
| **Unidad** (`org_unit`) | Nodo del árbol organizativo: gerencia, área, departamento, equipo |
| **Puesto** (`job_profile`) | Puesto de catálogo: «Analista Contable». Describe funciones, nivel y categoría |
| **Posición** (`position`) | Plaza presupuestada: una silla concreta en una unidad y sede, ocupada o vacante |
| **Persona** (`person`) | Ser humano registrado en la organización |
| **Relación laboral** (`employment`) | Vínculo de una persona con una razón social, con inicio y fin. Una persona puede tener varias |
| **Colaborador** | Persona con al menos una relación laboral activa. Término de interfaz |
| **Usuario** (`user`) | Credencial de acceso. Puede no estar ligado a una persona |
| **Vigencia** | Intervalo de fechas en que un dato es válido (`valid_from`, `valid_to`) |
| **`as_of`** | Fecha con la que se resuelve un dato vigenciado |
| **Alcance** | Subconjunto de la organización que un rol puede ver: organización, empresa, sede, unidad, equipo, propio |
| **Permiso por campo** | Permiso adicional para ver o editar un campo sensible |
| **Módulo comercial** | Conjunto de funciones que una organización contrata |
| **Feature flag** | Interruptor para activar una función nueva por organización, plan, grupo beta o país |
| **Paquete de país** | Conjunto versionado de reglas locales: conceptos, parámetros, regímenes, feriados, plantillas, archivos, obligaciones |
| **Parámetro legal** | Valor normativo (tasa, tope, plazo, monto) con vigencia por fecha, país y régimen |
| **Outbox** | Tabla donde se escriben los eventos dentro de la transacción para entregarlos después de forma fiable |
| **Adaptador / puerto** | Puerto: interfaz de una capacidad externa. Adaptador: su implementación para un proveedor |
| **Idempotencia** | Repetir una operación produce el mismo resultado que hacerla una vez |
| **RLS** | Seguridad a nivel de fila de PostgreSQL |
| **ESS / MSS** | Autoservicio del colaborador / del manager |
| **MCP** | Protocolo para que asistentes de IA externos usen herramientas y datos de un sistema |

## Administración de personal

| Término | Definición |
|---|---|
| **Alta / ingreso** | Inicio de la relación laboral |
| **Cese** | Fin de la relación laboral (renuncia, despido, fin de contrato, mutuo acuerdo, jubilación, fallecimiento) |
| **Reingreso** | Nueva relación laboral de una persona que ya trabajó en la organización |
| **Periodo de prueba** | Tramo inicial de la relación con menor protección frente al despido; su duración depende del tipo de puesto |
| **Contrato a plazo indeterminado** | Contrato sin fecha de fin |
| **Contrato sujeto a modalidad** | Contrato a plazo fijo con causa objetiva (inicio de actividad, necesidad de mercado, reconversión, ocasional, suplencia, emergencia, obra o servicio, intermitente, temporada). Cada modalidad tiene plazo máximo |
| **Desnaturalización** | Conversión de un contrato a modalidad en indeterminado por incumplir requisitos o superar plazos |
| **Adenda** | Modificación escrita de un contrato |
| **Modalidades formativas** | Convenios de prácticas preprofesionales, profesionales y similares (Ley 28518); no son relación laboral y reciben subvención |
| **Locador / cuarta categoría** | Prestador independiente que emite recibo por honorarios; no está en planilla |
| **Derechohabiente** | Familiar del trabajador con derecho a cobertura de salud (cónyuge o concubino, hijos, gestante) |
| **Teletrabajo** | Prestación a distancia regulada por la Ley 31572; exige acuerdo con contenido mínimo y respeta la desconexión digital |
| **Desconexión digital** | Derecho a no atender comunicaciones laborales fuera de la jornada |
| **Headcount / dotación** | Número de posiciones aprobadas, ocupadas y vacantes |
| **Centro de costo** | Unidad contable a la que se imputa el costo laboral |
| **Categoría ocupacional** | Clasificación del trabajador (ejecutivo, empleado, obrero) usada en registros oficiales |
| **Legajo / file personal** | Expediente de documentos del trabajador |

## Tiempo y asistencia

| Término | Definición |
|---|---|
| **Marcación** (`time_punch`) | Registro de entrada, salida o descanso |
| **Registro de control de asistencia** | Registro obligatorio de ingreso y salida (DS 004-2006-TR): debe impedir alteraciones y conservarse por el plazo legal |
| **Jornada** | Tiempo de trabajo diario o semanal pactado |
| **Horario** | Distribución de la jornada en el día |
| **Turno** | Bloque horario asignado a una persona en una fecha |
| **Tolerancia** | Minutos tras la hora de entrada que no cuentan como tardanza |
| **Tardanza** | Ingreso posterior a la hora más la tolerancia |
| **Sobretiempo / horas extra** | Trabajo en exceso de la jornada; se paga con sobretasa o se compensa con descanso |
| **Bolsa de horas** | Saldo de horas a favor o en contra para compensar sobretiempo con descanso |
| **Trabajo nocturno** | El realizado en el horario nocturno legal; tiene remuneración mínima propia |
| **Descanso semanal obligatorio** | Día de descanso remunerado por semana |
| **Feriado** | Día no laborable remunerado; trabajarlo genera sobretasa o descanso sustitutorio |
| **Geocerca** | Zona geográfica en la que se permite marcar |
| **Marcación de cuadrilla** | El supervisor registra la asistencia de su equipo |
| **Récord vacacional** | Año de servicios que da derecho al descanso vacacional |
| **Vacaciones truncas** | Proporción de vacaciones del año en curso, pagada al cese |
| **Vacaciones no gozadas / vencidas** | Descanso no tomado dentro del año siguiente al que se generó; da lugar a indemnización vacacional |
| **Adelanto de vacaciones** | Goce de días antes de completar el récord |
| **Descanso médico** | Ausencia por incapacidad certificada |
| **Subsidio** | Pago a cargo de EsSalud por incapacidad, maternidad o lactancia tras el periodo a cargo del empleador |
| **Licencia** | Ausencia autorizada, con o sin goce de haber (maternidad, paternidad, fallecimiento de familiar, sindical, etc.) |
| **Lactario** | Ambiente para extracción y conservación de leche materna, obligatorio según número de trabajadoras |

## Planilla y beneficios

| Término | Definición |
|---|---|
| **Planilla / nómina** | Cálculo periódico de remuneraciones, descuentos y aportes |
| **Planilla electrónica** | Conjunto T-Registro + PLAME |
| **T-Registro** | Registro de información laboral ante SUNAT: empleador, trabajadores, pensionistas, practicantes, terceros y derechohabientes |
| **PLAME** | Planilla Mensual de Pagos: declaración mensual de remuneraciones, aportes y retenciones (PDT 601) |
| **SUNAT** | Administración tributaria |
| **RUC** | Registro Único de Contribuyentes |
| **Periodo** (`payroll_period`) | Mes (o tramo) que se calcula y cierra |
| **Concepto** (`payroll_concept`) | Línea de planilla: ingreso, descuento, aporte, impuesto, provisión |
| **Afectación** | Bases a las que un concepto se suma (pensiones, EsSalud, renta de quinta, CTS, gratificación) |
| **Incidencia** | Novedad del periodo que altera el cálculo: faltas, horas extra, bonos, cuotas |
| **Remuneración básica** | Sueldo pactado |
| **Remuneración computable** | Base de cálculo de un beneficio; cada beneficio define la suya |
| **Concepto no remunerativo** | Pago que no integra la base de beneficios ni aportes (ciertas condiciones de trabajo, gratificaciones extraordinarias, etc.) |
| **RMV** | Remuneración Mínima Vital. Parámetro |
| **UIT** | Unidad Impositiva Tributaria; referencia para deducciones, topes y multas. Parámetro anual |
| **Asignación familiar** | Pago mensual a trabajadores con hijos a cargo, calculado sobre la RMV (Ley 25129) |
| **Gratificaciones** | Dos pagos al año, en julio y diciembre (Ley 27735), proporcionales a los meses trabajados en el semestre |
| **Bonificación extraordinaria** | Monto adicional a la gratificación equivalente al aporte a salud que el empleador deja de pagar sobre ella (Ley 30334) |
| **Gratificación trunca** | Proporción de la gratificación pagada al cese |
| **CTS** | Compensación por Tiempo de Servicios: depósito semestral en la entidad elegida por el trabajador (DS 001-97-TR) |
| **CTS trunca** | CTS pendiente al cese |
| **Carta de liberación de CTS** | Documento del empleador para que el trabajador retire su CTS al cese |
| **Utilidades** | Participación de los trabajadores en la renta de la empresa (DL 892), distribuida por días laborados y por remuneraciones |
| **SNP / ONP** | Sistema Nacional de Pensiones, administrado por la Oficina de Normalización Previsional |
| **SPP / AFP** | Sistema Privado de Pensiones / Administradora de Fondos de Pensiones. El descuento tiene aporte al fondo, comisión y prima de seguro |
| **Comisión sobre flujo / mixta** | Esquemas de comisión de la AFP |
| **Remuneración máxima asegurable** | Tope sobre el que se calcula la prima de seguro de la AFP. Parámetro periódico |
| **AFPnet** | Plataforma para declarar y pagar aportes a las AFP |
| **EsSalud** | Seguro social de salud; aporte a cargo del empleador |
| **EPS** | Entidad Prestadora de Salud; plan privado complementario con crédito contra el aporte a EsSalud |
| **SIS** | Seguro Integral de Salud; aplicable a la microempresa |
| **SCTR** | Seguro Complementario de Trabajo de Riesgo: salud y pensión para actividades de riesgo |
| **Vida Ley** | Seguro de vida obligatorio a cargo del empleador desde el inicio de la relación |
| **Renta de quinta categoría** | Impuesto a la renta del trabajo dependiente, retenido por el empleador con proyección anual |
| **Renta de cuarta categoría** | Impuesto a la renta del trabajo independiente (locadores) |
| **Certificado de quinta** | Constancia anual de rentas y retenciones |
| **Retención judicial** | Descuento ordenado por un juez (alimentos, embargos) |
| **Neto a pagar** | Ingresos menos descuentos del trabajador |
| **Aportes del empleador** | Costos que no se descuentan al trabajador (EsSalud, SCTR, Vida Ley, etc.) |
| **Boleta de pago** (`payslip`) | Constancia del pago con el detalle de conceptos; puede entregarse de forma digital |
| **Provisión** | Registro mensual del costo devengado de CTS, gratificaciones y vacaciones |
| **Asiento / centralización contable** | Registro contable de la planilla por cuenta y centro de costo |
| **Liquidación de beneficios sociales** (`settlement`) | Pago al cese: CTS, vacaciones y gratificación truncas, vacaciones no gozadas, indemnización si corresponde |
| **Indemnización por despido arbitrario** | Compensación por despido sin causa justa; su cálculo depende del régimen y del tipo de contrato |
| **Certificado de trabajo** | Documento que acredita tiempo de servicios y cargo, entregado al cese |
| **Adelanto de sueldo** | Pago anticipado de remuneración ya devengada, descontado en el periodo |
| **Préstamo** | Monto otorgado al trabajador y descontado en cuotas |
| **Reintegro** | Pago de diferencias de periodos anteriores |
| **Rectificatoria** | Nueva declaración que corrige una ya presentada |
| **Caso dorado** | Caso de prueba de planilla con resultado esperado validado por un especialista |

## Regímenes

| Término | Definición |
|---|---|
| **Régimen general** | Régimen laboral común de la actividad privada (DL 728) |
| **Régimen MYPE** | Régimen especial para micro y pequeña empresa inscritas en el REMYPE, con beneficios reducidos |
| **Microempresa / pequeña empresa** | Categorías del régimen MYPE según ventas anuales en UIT; cada una con sus propios beneficios |
| **REMYPE** | Registro Nacional de la Micro y Pequeña Empresa |
| **Construcción civil** | Régimen con jornales por categoría (operario, oficial, peón) y beneficios propios |
| **Régimen agrario** | Régimen especial del sector agrario |

## Cumplimiento

| Término | Definición |
|---|---|
| **MTPE** | Ministerio de Trabajo y Promoción del Empleo |
| **SUNAFIL** | Superintendencia Nacional de Fiscalización Laboral |
| **Casilla electrónica** | Buzón oficial donde SUNAFIL notifica; la notificación surte efecto aunque no se lea |
| **Expediente de inspección** | Paquete de boletas, asistencia, contratos, SST y constancias por trabajador y periodo para atender una inspección |
| **SST** | Seguridad y Salud en el Trabajo (Ley 29783) |
| **Comité / supervisor de SST** | Órgano paritario o persona responsable según el tamaño de la empresa |
| **IPERC** | Identificación de Peligros, Evaluación de Riesgos y Controles |
| **EPP** | Equipo de protección personal |
| **Examen médico ocupacional** | Evaluación de ingreso, periódica y de retiro; su resultado es dato sensible |
| **Registros obligatorios de SST** | Accidentes, enfermedades e incidentes; exámenes; monitoreo de agentes; inspecciones; estadísticas; equipos; capacitaciones y simulacros; auditorías |
| **Incidente peligroso** | Suceso que pudo causar lesiones graves; se registra y notifica |
| **Hostigamiento sexual** | Conducta regulada por la Ley 27942; exige canal, comité o delegado, medidas de protección y plazos |
| **Comité de intervención** | Órgano que investiga el hostigamiento sexual en empresas a partir de cierto tamaño |
| **Igualdad salarial** | Prohibición de discriminación remunerativa (Ley 30709); exige cuadro de categorías y funciones y política salarial |
| **Cuadro de categorías y funciones** | Clasificación de puestos por categoría con funciones, responsabilidad y requisitos |
| **Cuota de discapacidad** | Proporción mínima de trabajadores con discapacidad exigida a partir de cierto tamaño (Ley 29973) |
| **Datos personales / sensibles** | Ley 29733 y su reglamento; los sensibles incluyen salud, biometría, afiliación sindical e ingresos |
| **Titular / encargado** | Titular del banco de datos: la organización cliente. Encargado: quien trata datos por cuenta de ella (la plataforma) |
| **Derechos ARCO** | Acceso, rectificación, cancelación y oposición; el reglamento añade portabilidad |
| **Evaluación de impacto** | Análisis previo exigido para tratamientos de riesgo como biometría y perfilado |
| **Sindicato** | Organización de trabajadores; implica afiliación, cuota sindical y licencias sindicales |
| **Convenio colectivo** | Acuerdo entre empleador y sindicato; sus cláusulas pueden ser conceptos de planilla |

## Talento y experiencia

| Término | Definición |
|---|---|
| **ATS** | Sistema de seguimiento de candidatos |
| **Requisición** (`job_requisition`) | Solicitud aprobada para cubrir una posición |
| **Postulación** (`application`) | Candidato en una vacante concreta |
| **Base de talento** | Candidatos conservados con su consentimiento para futuras vacantes |
| **Scorecard** | Formulario estructurado de evaluación de una entrevista |
| **Pre-onboarding** | Tareas entre la aceptación de la oferta y el primer día |
| **Onboarding / offboarding** | Procesos de ingreso / de salida |
| **Evaluación 90°, 180°, 270°, 360°** | Según quién evalúa: jefe; + autoevaluación; + pares o subordinados; todos |
| **Calibración** | Sesión para homologar calificaciones entre evaluadores |
| **OKR** | Objetivos y resultados clave |
| **Competencia** | Comportamiento observable con niveles |
| **Habilidad (skill)** | Capacidad técnica o funcional de una persona |
| **1:1** | Reunión individual periódica entre manager y colaborador |
| **9-box** | Matriz de desempeño × potencial |
| **Sucesión** | Identificación de sucesores para posiciones críticas |
| **Banda salarial** | Rango mínimo, medio y máximo para un puesto o categoría |
| **Posición en banda** | Sueldo de la persona respecto del punto medio de su banda |
| **LMS** | Sistema de gestión de aprendizaje |
| **SCORM** | Formato estándar de contenidos de formación |
| **eNPS** | Indicador de recomendación de la empresa como lugar de trabajo: promotores menos detractores |
| **Encuesta de pulso** | Encuesta breve y frecuente |
| **Mapa de calor** | Resultados de clima por unidad y dimensión, con color por nivel |
| **SLA** | Plazo comprometido de atención de una solicitud |
| **Rotación** | Proporción de ceses sobre la dotación en un periodo |
| **Ausentismo** | Proporción de tiempo no trabajado sobre el programado |
| **Time-to-hire** | Tiempo desde que se abre una vacante hasta que se cubre |
