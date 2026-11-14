# 19 · Parámetros legales por validar

Lista de trabajo para el especialista laboral (decisiones D-15 y D-36). Se genera con `kit/tools/esquema/gen_pendientes.py` desde `database/catalogos/parametros_legales_pe.json`; no se edita a mano.

Hay **56 parámetros sin valor verificado**; **49** afectan al primer corte comercial (régimen general, micro y pequeña empresa) y bloquean su salida (QA-17). Mientras un parámetro no tenga valor, la regla RN-PAR-03 impide cerrar una planilla que lo use.

Para cada fila, el especialista anota el valor, la fecha de inicio de vigencia y la norma oficial (no una fuente secundaria). Luego se carga en `catalogos.py` con `verified=True` y se regenera.

## 1. Sin valor verificado

| Código | Parámetro | Régimen | Unidad | Base legal citada | Bloquea MVP | Nota | Valor y norma validados |
|---|---|---|---|---|---|---|---|
| `afp_comision_flujo_pct` | Comisión sobre flujo por AFP | Todos | percent | SBS | Sí | Varía por AFP; cargar en pension_fund_rates cada periodo. | |
| `afp_comision_mixta_pct` | Comisión mixta por AFP | Todos | percent | SBS | Sí | Varía por AFP; cargar en pension_fund_rates cada periodo. | |
| `afp_prima_seguro_pct` | Prima de seguro previsional | Todos | percent | SBS | Sí | Cargar desde la fuente oficial cada periodo. | |
| `afp_remuneracion_maxima_asegurable` | Remuneración máxima asegurable | Todos | amount | SBS | Sí | Cargar desde la fuente oficial cada periodo. | |
| `boletas_conservacion_anios` | Conservación de documentos de pago | Todos | years | DL 1310 | Sí | Citado de memoria en la investigación; validar con el texto vigente. | |
| `contrato_modalidad_tope_acumulado_anios` | Tope acumulado de contratos a modalidad | Todos | years | DS 003-97-TR | Sí | El tope general no consta en la investigación. | |
| `descanso_medico_dias_cargo_empleador` | Días de incapacidad temporal del año a cargo del empleador antes del subsidio | Todos | days | Ley 26790 art. 12 · DS 009-97-SA | Sí | Desde el día siguiente corre el subsidio por incapacidad temporal; por confirmar el número vigente y si se cuenta por año calendario. | |
| `discapacidad_cuota_minimo_trabajadores` | Trabajadores desde los que aplica la cuota | Todos | count | Ley 29973 | Sí | No confirmado en el texto. | |
| `discapacidad_cuota_pct` | Cuota de empleo de personas con discapacidad | Todos | percent | Ley 29973 | Sí | No confirmado en el texto. | |
| `eps_credito_pct` | Crédito por EPS contra el aporte de salud | Todos | percent | Ley 26790 | Sí | Porcentaje no verificado. | |
| `gratificacion_bonificacion_extraordinaria_eps_pct` | Bonificación extraordinaria para afiliados a EPS | Todos | percent | Ley 30334 | Sí | Porcentaje no verificado. | |
| `host_alegatos_dias` | Hostigamiento: plazo interno de alegatos | Todos | days | DS 014-2019-MIMP art. 29.6 | Sí | Dentro de los 10 días de la decisión; por confirmar. | |
| `host_descargos_dias` | Hostigamiento: plazo interno de descargos | Todos | days | DS 014-2019-MIMP art. 29.5 | Sí | Lo fija el comité dentro de sus 15 días; por confirmar. | |
| `jornada_maxima_semanal_horas` | Jornada máxima semanal | Todos | hours | DS 007-2002-TR | Sí | No consta en la investigación. | |
| `lactario_minimo_mujeres_edad_fertil` | Mujeres en edad fértil por centro de trabajo que obligan a lactario | Todos | count | Ley 29896 | Sí | Umbral no verificado. | |
| `legajo_conservacion_anios` | Conservación del legajo después del cese | Todos | years | Por definir (normas de conservación de documentos laborales) | Sí | DOC-1 · DOC-2: plazo durante el que un documento del legajo no se depura de la papelera. Sin valor validado, nada se depura. | |
| `licencia_fallecimiento_familiar_dias` | Licencia por fallecimiento de un familiar directo (días) | Todos | days | Por confirmar | Sí | Por confirmar si existe licencia general en el régimen privado o solo por convenio. | |
| `licencia_maternidad_dias` | Licencia por maternidad (días naturales, prenatal y postnatal) | Todos | days | Ley 26644 | Sí | Por confirmar la duración vigente y las extensiones (parto múltiple, discapacidad). | |
| `licencia_maternidad_dias_adicionales_discapacidad` | Licencia por maternidad: días adicionales por nacimiento de un hijo con discapacidad | Todos | days | Ley 26644 modificada por Ley 30367 | Sí | Por confirmar los días que se suman al descanso postnatal. | |
| `licencia_maternidad_dias_adicionales_parto_multiple` | Licencia por maternidad: días adicionales por parto múltiple | Todos | days | Ley 26644 modificada por Ley 27606 | Sí | Por confirmar los días que se suman al descanso postnatal. | |
| `licencia_paternidad_dias` | Licencia por paternidad (días naturales consecutivos) | Todos | days | Ley 29409 y Ley 30807 | Sí | Por confirmar la duración vigente y los supuestos con más días. | |
| `licencia_paternidad_dias_complicaciones` | Licencia por paternidad: duración por enfermedad grave, discapacidad del hijo o complicaciones de la madre | Todos | days | Ley 29409 modificada por Ley 30807 | Sí | Duración total (no se suma a la general); por confirmar. | |
| `licencia_paternidad_dias_prematuro_multiple` | Licencia por paternidad: duración por parto prematuro o múltiple | Todos | days | Ley 29409 modificada por Ley 30807 | Sí | Duración total (no se suma a la general); por confirmar. | |
| `liquidacion_plazo_pago_horas` | Plazo de pago de la liquidación | Todos | hours | DS 001-97-TR | Sí | Las 48 horas no fueron verificadas. | |
| `mype_recontratacion_meses` | Meses sin recontratación para aplicar el régimen especial a un extrabajador | Todos | months | Ley 32353 | Sí | Por confirmar con el TUO vigente. | |
| `mype_sis_aporte` | Aporte al SIS de la microempresa | Microempresa | amount | DS 013-2013-PRODUCE | Sí | Lo fija el SIS; por confirmar. | |
| `nocturnidad_sobretasa_pct` | Sobretasa por trabajo nocturno | Todos | percent | DS 007-2002-TR | Sí | No consta en la investigación. | |
| `periodo_prueba_meses` | Periodo de prueba por tipo de puesto | Todos | scale | DS 003-97-TR | Sí | No verificado. | |
| `planilla_semanal_criterio_mes` | Criterio para asignar una semana de pago al mes tributario | Todos | text | R.S. 183-2011/SUNAT | Sí | fecha_pago, fin_periodo o dividir; por confirmar. | |
| `quinta_devuelve_exceso` | El empleador devuelve el exceso de retención de quinta en la regularización o el cese | Todos | count | Reglamento LIR art. 40 y 45 | Sí | Por confirmar el procedimiento y si se devuelve en la boleta o se informa a SUNAT. | |
| `quinta_fraccionamiento_semanal` | Fraccionamiento de la retención en pagos semanales o quincenales | Todos | text | Reglamento LIR art. 40 | Sí | proporcional o ultimo_pago; por confirmar. | |
| `quinta_previas_sin_detalle` | Tratamiento de retenciones de empleadores anteriores declaradas sin detalle mensual | Todos | text | Reglamento LIR art. 40 y 41 | Sí | total o prorrateo; criterio por confirmar. | |
| `renuncia_preaviso_dias` | Preaviso de renuncia (días naturales) | Todos | days | DS 003-97-TR art. 18 | Sí | Se cita un preaviso de 30 días naturales que el empleador puede exonerar; no verificado. El cese solo advierte si el preaviso registrado es menor (COL-11). | |
| `sobretiempo_tasa_tramo1_pct` | Sobretasa de las dos primeras horas extra | Todos | percent | DS 007-2002-TR | Sí | No consta en la investigación. | |
| `sobretiempo_tasa_tramo2_pct` | Sobretasa de las horas extra siguientes | Todos | percent | DS 007-2002-TR | Sí | No consta en la investigación. | |
| `sst_capacitaciones_anuales_min` | Capacitaciones mínimas al año | Todos | count | Ley 29783 art. 35 | Sí | Por confirmar. | |
| `subsidio_reembolso_plazo_meses` | Plazo para solicitar el reembolso de un subsidio a la seguridad social | Todos | months | Ley 26790 · Acuerdo de EsSalud | Sí | Por confirmar el plazo vigente y desde cuándo se cuenta (fin del descanso). | |
| `sunafil_actuaciones_dias_habiles` | Duración de las actuaciones inspectivas | Todos | business_days | Ley 28806 | Sí | Por confirmar. | |
| `sunafil_pas_apelacion_dias_habiles` | Procedimiento sancionador: apelación | Todos | business_days | DS 019-2006-TR | Sí | Por confirmar. | |
| `sunafil_pas_descargos_dias_habiles` | Procedimiento sancionador: descargos a la imputación | Todos | business_days | DS 019-2006-TR | Sí | Por confirmar. | |
| `sunafil_pas_informe_final_dias_habiles` | Procedimiento sancionador: descargos al informe final | Todos | business_days | DS 019-2006-TR | Sí | Por confirmar. | |
| `treg_baja_dias` | T-Registro: baja | Todos | days | DS 018-2007-TR art. 4-A | Sí | SUNAT: al día siguiente; otras fuentes: primer día hábil siguiente. Tipo de día por confirmar; alertar con día calendario. | |
| `utilidades_pct_otros_sectores` | Participación en utilidades: demás sectores de la norma | Todos | scale | DL 892 | Sí | La norma tiene más sectores que los citados; verificar. | |
| `vacaciones_adelanto_max_dias` | Días de vacaciones que se pueden adelantar antes de cumplir el récord | Todos | days | DL 1405 art. 3 · DS 013-2019-TR | Sí | Adelanto a cuenta de días generados en proporción al tiempo trabajado; por confirmar. | |
| `vacaciones_dias` | Días de vacaciones por año | Régimen general | days | DL 713 | Sí | Régimen general. No consta en la investigación; se espera 30 días, por confirmar. | |
| `vacaciones_fraccion_minima_dias` | Bloque mínimo al fraccionar las vacaciones | Todos | days | DL 1405 art. 2 · DS 013-2019-TR | Sí | Fraccionamiento del descanso en bloques; por confirmar. | |
| `vacaciones_record_dias_jornada_5` | Récord vacacional: días efectivos con jornada de 5 días a la semana | Todos | days | DL 713 art. 10 | Sí | Se espera 210 días de labor efectiva; por confirmar. | |
| `vacaciones_record_dias_jornada_6` | Récord vacacional: días efectivos con jornada de 6 días a la semana | Todos | days | DL 713 art. 10 | Sí | Se espera 260 días de labor efectiva; por confirmar. | |
| `vacaciones_venta_max_dias` | Días de vacaciones que se pueden vender (reducción del descanso) | Todos | days | DL 713 art. 19 · DL 1405 | Sí | Tope de la reducción por acuerdo escrito; por confirmar por régimen. | |
| `agr_essalud_pct` | Aporte a EsSalud por tramo de la razón social | Agrario | scale | Ley 31110 art. 9 | No | Tramo A (100 o más trabajadores o ventas mayores de 1 700 UIT): 9 % desde 2025, verificado. Tramo B: 6 % hasta 2027 y 9 % desde 2028, año por confirmar. | |
| `cc_altitud` | Bonificación por altitud | Construcción civil | amount | Convenio CAPECO-FTCCP | No | No verificado. | |
| `cc_asignacion_escolar_modo` | Asignación escolar: devengo diario o pago en oportunidad única | Construcción civil | text | Convenio CAPECO-FTCCP | No | Por confirmar. | |
| `cc_bae_pct` | Bonificación por alta especialización por especialidad | Construcción civil | scale | Convenio CAPECO-FTCCP | No | Las lecturas de la tabla discrepan. | |
| `cc_fondo_capacitacion_pct` | Fondo de capacitación a cargo del empleador | Construcción civil | percent | Convenio CAPECO-FTCCP | No | Una sola fuente (0,45 %). | |
| `cc_liquidacion_modo` | Oportunidad de pago de vacaciones y compensación | Construcción civil | text | Convenio CAPECO-FTCCP | No | semanal o al término; por confirmar. | |
| `cc_nocturno_pct` | Bonificación por trabajo nocturno | Construcción civil | percent | Convenio CAPECO-FTCCP | No | Discrepancia: 20 % (Tabla 22) o 25 % (fuente secundaria). | |

## 2. Con valor, pero con fecha de inicio no verificada

104 parámetros tienen valor verificado y una fecha «vigente_desde» de referencia. Basta con confirmar que el valor regía en esa fecha.

| Código | Parámetro | Régimen | Valor | Vigente desde | Base legal citada |
|---|---|---|---|---|---|
| `afp_aporte_obligatorio_pct` | Aporte obligatorio al fondo (AFP) | Todos | 10 | 2025-01-01 | Fuentes secundarias 2026 |
| `afpnet_plazo_dias_habiles` | AFPnet: declaración y pago, días hábiles del mes siguiente | Todos | 5 | 2026-01-01 | TUO del SPP; Profuturo AFP |
| `agr_vacaciones_dias` | Días de vacaciones por año | Agrario | 30 | 2021-01-01 | Ley 31110 art. 3 |
| `asignacion_familiar_aplica` | Corresponde asignación familiar | Todos | 1 | 2025-01-01 | Ley 25129 |
| `asignacion_familiar_aplica` | Corresponde asignación familiar | Microempresa | 0 | 2025-01-01 | DS 013-2013-PRODUCE |
| `asignacion_familiar_aplica` | Corresponde asignación familiar | Pequeña empresa | 0 | 2025-01-01 | DS 013-2013-PRODUCE |
| `asignacion_familiar_pct_rmv` | Asignación familiar (% de la RMV) | Todos | 10 | 2025-01-01 | Ley 25129 |
| `asistencia_conservacion_anios` | Conservación del registro de asistencia | Todos | 5 | 2026-01-01 | DS 004-2006-TR |
| `cc_asignacion_escolar_jornales_anio` | Asignación escolar: jornales básicos por año por hijo | Construcción civil | 30 | 2026-01-01 | Convenio CAPECO-FTCCP; tabla FTCCP 2026 |
| `cc_compensacion_pct` | Compensación por tiempo de servicios (% de los jornales básicos; 12 % + 3 % de utilidades) | Construcción civil | 15 | 2026-01-01 | Convenio CAPECO-FTCCP |
| `cc_conafovicer_pct` | CONAFOVICER a cargo del trabajador | Construcción civil | 2 | 2026-01-01 | RS 001-95-MTC |
| `cc_contacto_agua_pct` | Bonificación por contacto directo con agua (% del jornal básico) | Construcción civil | 20 | 2026-01-01 | Tabla 22 SUNAT, código 0310 |
| `cc_costo_obra_min_uit` | Costo de obra desde el que aplica el régimen (en UIT) | Construcción civil | 50 | 2026-01-01 | DLeg 727 |
| `cc_gratificacion_fracciones` | Fracciones de la gratificación por periodo (meses) | Construcción civil | {"7": 7, "12": 5} | 2026-01-01 | Convenio CAPECO-FTCCP |
| `cc_gratificacion_jornales` | Jornales básicos por gratificación (Fiestas Patrias y Navidad) | Construcción civil | 40 | 2026-01-01 | Convenio CAPECO-FTCCP |
| `cc_he_tasa_1_pct` | Sobretiempo hasta la décima hora | Construcción civil | 60 | 2026-01-01 | Convenio CAPECO-FTCCP |
| `cc_he_tasa_2_pct` | Sobretiempo después de la décima hora | Construcción civil | 100 | 2026-01-01 | Convenio CAPECO-FTCCP |
| `cc_vacaciones_pct` | Compensación vacacional (% de los jornales básicos) | Construcción civil | 10 | 2026-01-01 | Convenio CAPECO-FTCCP |
| `contrato_inicio_actividad_max_anios` | Plazo máximo: contrato por inicio de actividad | Todos | 3 | 2026-01-01 | DS 003-97-TR |
| `contrato_necesidad_mercado_max_anios` | Plazo máximo: contrato por necesidad de mercado | Todos | 5 | 2026-01-01 | DS 003-97-TR |
| `contrato_ocasional_max_meses_por_anio` | Plazo máximo: contrato ocasional (meses por año) | Todos | 6 | 2026-01-01 | DS 003-97-TR |
| `contrato_reconversion_max_anios` | Plazo máximo: contrato por reconversión empresarial | Todos | 2 | 2026-01-01 | DS 003-97-TR |
| `cts_aplica` | Corresponde CTS | Todos | 1 | 2025-01-01 | DS 001-97-TR |
| `cts_aplica` | Corresponde CTS | Microempresa | 0 | 2025-01-01 | DS 013-2013-PRODUCE |
| `cts_dias_por_anio` | Días de remuneración de CTS por año | Todos | 30 | 2025-01-01 | DS 001-97-TR |
| `cts_dias_por_anio` | Días de remuneración de CTS por año | Pequeña empresa | 15 | 2025-01-01 | DS 013-2013-PRODUCE |
| `cts_fraccion_gratificacion` | Fracción de la gratificación en la remuneración computable de CTS | Todos | 0.166667 | 2025-01-01 | DS 001-97-TR |
| `cts_meses_deposito` | Meses de depósito de CTS | Todos | [5, 11] | 2025-01-01 | DS 001-97-TR |
| `cts_tope_dias` | Tope de CTS en remuneraciones diarias | Pequeña empresa | 90 | 2025-01-01 | DS 013-2013-PRODUCE; guía MTPE |
| `dias_base_mes` | Días de la base mensual para prorratear y calcular truncos | Todos | 30 | 2025-01-01 | DS 001-97-TR |
| `essalud_aporte_pct` | Aporte del empleador a EsSalud | Todos | 9 | 2025-01-01 | Ley 26790 |
| `gratificacion_aplica` | Corresponde gratificación | Todos | 1 | 2025-01-01 | Ley 27735 |
| `gratificacion_aplica` | Corresponde gratificación | Microempresa | 0 | 2025-01-01 | DS 013-2013-PRODUCE |
| `gratificacion_bonificacion_extraordinaria_pct` | Bonificación extraordinaria sobre la gratificación | Todos | 9 | 2025-01-01 | Ley 30334 |
| `gratificacion_factor` | Factor de gratificación | Todos | 1 | 2025-01-01 | Ley 27735 |
| `gratificacion_factor` | Factor de gratificación | Pequeña empresa | 0.5 | 2025-01-01 | DS 013-2013-PRODUCE |
| `gratificacion_meses` | Meses de pago de gratificaciones | Todos | [7, 12] | 2025-01-01 | Ley 27735 |
| `host_atencion_dias_habiles` | Hostigamiento: plazo para ofrecer atención médica y psicológica | Todos | 1 | 2019-07-23 | DS 014-2019-MIMP art. 17 y 29.1 |
| `host_comunicacion_mtpe_decision_dias_habiles` | Hostigamiento: plazo para comunicar la decisión al MTPE | Todos | 6 | 2019-07-23 | DS 014-2019-MIMP art. 29.6 |
| `host_comunicacion_mtpe_queja_dias_habiles` | Hostigamiento: plazo para comunicar al MTPE la queja y las medidas | Todos | 6 | 2019-07-23 | DS 014-2019-MIMP art. 29.3 |
| `host_decision_dias_calendario` | Hostigamiento: plazo de la decisión de Recursos Humanos | Todos | 10 | 2019-07-23 | DS 014-2019-MIMP art. 29.6 |
| `host_informe_comite_dias_calendario` | Hostigamiento: plazo del informe del comité | Todos | 15 | 2019-07-23 | DS 014-2019-MIMP art. 29.5 |
| `host_medidas_proteccion_dias_habiles` | Hostigamiento: plazo para dictar y ejecutar medidas de protección | Todos | 3 | 2019-07-23 | DS 014-2019-MIMP art. 18 y 29.2 |
| `host_traslado_comite_dias_habiles` | Hostigamiento: plazo para trasladar la queja al comité | Todos | 1 | 2019-07-23 | DS 014-2019-MIMP art. 29.4 |
| `hostigamiento_comite_minimo_trabajadores` | Trabajadores por centro de trabajo desde los que se exige comité de intervención (20 o más) | Todos | 20 | 2026-01-01 | DS 014-2019-MIMP art. 27.1 |
| `indemnizacion_dias_por_anio` | Indemnización por despido: remuneraciones diarias por año | Microempresa | 10 | 2025-01-01 | DS 013-2013-PRODUCE; guía MTPE |
| `indemnizacion_dias_por_anio` | Indemnización por despido: remuneraciones diarias por año | Pequeña empresa | 20 | 2025-01-01 | DS 013-2013-PRODUCE; guía MTPE |
| `indemnizacion_dias_por_anio` | Indemnización por despido: remuneraciones diarias por año | Todos | 45 | 2025-01-01 | DS 003-97-TR art. 38 |
| `indemnizacion_plazo_fijo_dias_por_mes` | Indemnización por despido en contrato a plazo fijo: remuneraciones diarias por mes que falta | Todos | 45 | 2025-01-01 | DS 003-97-TR art. 76 |
| `indemnizacion_tope_dias` | Indemnización por despido: tope en remuneraciones diarias | Microempresa | 90 | 2025-01-01 | DS 013-2013-PRODUCE; guía MTPE |
| `indemnizacion_tope_dias` | Indemnización por despido: tope en remuneraciones diarias | Pequeña empresa | 120 | 2025-01-01 | DS 013-2013-PRODUCE; guía MTPE |
| `indemnizacion_tope_dias` | Indemnización por despido: tope en remuneraciones diarias | Todos | 360 | 2025-01-01 | DS 003-97-TR art. 38 |
| `indemnizacion_vacacional_aplica` | Corresponde indemnización por vacaciones no gozadas en el año siguiente | Todos | 1 | 2025-01-01 | DL 713 art. 23 |
| `mayoria_edad_anios` | Edad en que un hijo derechohabiente pasa a revisión | Todos | 18 | 2026-01-01 | Código Civil, art. 42 |
| `mype_anios_consecutivos_exceso` | Años consecutivos de exceso de ventas que inician la permanencia | Todos | 2 | 2026-01-01 | DS 013-2013-PRODUCE art. 42; guía MTPE |
| `mype_permanencia_anios` | Permanencia en el régimen tras superar el tope de ventas | Microempresa | 1 | 2026-01-01 | DS 013-2013-PRODUCE art. 42; guía MTPE |
| `mype_permanencia_anios` | Permanencia en el régimen tras superar el tope de ventas | Pequeña empresa | 3 | 2026-01-01 | DS 013-2013-PRODUCE art. 42; guía MTPE |
| `mype_permite_retorno` | Se permite volver a la categoría anterior (0 = no) | Todos | 0 | 2026-01-01 | Guía MTPE |
| `mype_ventas_max_uit` | Ventas anuales máximas (en UIT) | Microempresa | 150 | 2026-01-01 | DS 013-2013-PRODUCE |
| `mype_ventas_max_uit` | Ventas anuales máximas (en UIT) | Pequeña empresa | 1700 | 2026-01-01 | DS 013-2013-PRODUCE |
| `onp_aporte_pct` | Aporte al sistema nacional de pensiones | Todos | 13 | 2025-01-01 | Fuentes secundarias 2026 |
| `quinta_cortes_retenciones` | Último mes cuyas retenciones se deducen, por mes de cálculo | Todos | {"1": 0, "2": 0, "3": 0, "4": 3, "5": 4, "6": 4, "7": 4, "8": 7, "9": 8, "10": 8, "11": 8, "12": 11} | 2025-01-01 | Reglamento LIR art. 40; SUNAT, Cálculo del impuesto |
| `quinta_deduccion_uit` | Deducción para renta de quinta categoría (en UIT) | Todos | 7 | 2025-01-01 | Ley del Impuesto a la Renta |
| `quinta_divisores` | Divisor de la retención de quinta por mes | Todos | {"1": 12, "2": 12, "3": 12, "4": 9, "5": 8, "6": 8, "7": 8, "8": 5, "9": 4, "10": 4, "11": 4, "12": 1} | 2025-01-01 | Reglamento LIR art. 40; SUNAT, Cálculo del impuesto |
| `quinta_escala` | Escala progresiva acumulativa de quinta categoría (tramos en UIT) | Todos | [{"hasta_uit": 5, "tasa": 8}, {"hasta_uit": 20, "tasa": 14}, {"hasta_uit": 35, "tasa": 17}, {"hasta_uit": 45, "tasa": 20}, {"hasta_uit": null, "tasa": 30}] | 2025-01-01 | LIR art. 53; SUNAT, Tasas del impuesto |
| `quinta_no_domiciliado_tasa_pct` | Retención de quinta a no domiciliados (sobre el total, sin deducción) | Todos | 30 | 2025-01-01 | SUNAT, Obligaciones por las rentas de quinta categoría |
| `quinta_tasa_maxima_pct` | Tasa máxima de la escala de quinta categoría | Todos | 30 | 2025-01-01 | Ley del Impuesto a la Renta |
| `quinta_tasa_minima_pct` | Tasa mínima de la escala de quinta categoría | Todos | 8 | 2025-01-01 | Ley del Impuesto a la Renta |
| `remype_acreditacion_dias_habiles` | REMYPE: plazo de emisión de la acreditación | Todos | 7 | 2026-01-01 | Guía MTPE |
| `sst_comite_miembros_max` | Comité de SST: máximo de miembros | Todos | 12 | 2026-01-01 | DS 005-2012-TR art. 43 |
| `sst_comite_miembros_min` | Comité de SST: mínimo de miembros | Todos | 4 | 2026-01-01 | DS 005-2012-TR art. 43 |
| `sst_comite_minimo_trabajadores` | Trabajadores desde los que se exige comité de SST (20 o más) | Todos | 20 | 2026-01-01 | Ley 29783 art. 29 |
| `sst_conservacion_accidentes_anios` | Conservación: accidentes e incidentes peligrosos | Todos | 10 | 2026-01-01 | DS 005-2012-TR |
| `sst_conservacion_enfermedades_anios` | Conservación: enfermedades ocupacionales | Todos | 20 | 2026-01-01 | DS 005-2012-TR |
| `sst_conservacion_otros_anios` | Conservación: demás registros de SST | Todos | 5 | 2026-01-01 | DS 005-2012-TR |
| `sst_eleccion_aviso_dias` | Aviso del proceso electoral respecto del fin del mandato | Todos | 60 | 2026-01-01 | RM 245-2021-TR |
| `sst_eleccion_convocatoria_sindicato_dias` | Plazo del sindicato mayoritario para convocar | Todos | 30 | 2026-01-01 | RM 245-2021-TR |
| `sst_eleccion_nominacion_dias_habiles` | Nominación de candidatos antes de la convocatoria | Todos | 15 | 2026-01-01 | DS 005-2012-TR art. 49 |
| `sst_eleccion_personeros_dias_habiles` | Designación de personeros tras la lista de candidatos aptos | Todos | 5 | 2026-01-01 | RM 245-2021-TR |
| `sst_eleccion_tachas_horas` | Resolución de tachas | Todos | 24 | 2026-01-01 | RM 245-2021-TR |
| `sst_instalacion_dias_habiles` | Instalación del comité desde la elección | Todos | 10 | 2026-01-01 | RM 245-2021-TR |
| `sst_licencia_dias` | Licencia con goce de los representantes (días naturales por año) | Todos | 30 | 2026-01-01 | DS 005-2012-TR art. 73 |
| `sst_mandato_max_anios` | Mandato máximo de los representantes de los trabajadores | Todos | 2 | 2026-01-01 | DS 005-2012-TR art. 62 |
| `sst_mandato_min_anios` | Mandato mínimo de los representantes de los trabajadores | Todos | 1 | 2026-01-01 | DS 005-2012-TR art. 62 |
| `sst_sin_quorum_reprogramacion_dias` | Nueva sesión tras una sin quórum | Todos | 8 | 2026-01-01 | DS 005-2012-TR art. 69 |
| `sst_vacancia_inasistencias_alternas` | Inasistencias alternas que causan vacancia | Todos | 4 | 2026-01-01 | DS 005-2012-TR art. 63 |
| `sst_vacancia_inasistencias_consecutivas` | Inasistencias consecutivas que causan vacancia | Todos | 3 | 2026-01-01 | DS 005-2012-TR art. 63 |
| `teletrabajo_desconexion_horas` | Desconexión digital mínima continua | Todos | 12 | 2026-01-01 | Ley 31572 |
| `teletrabajo_respuesta_dias_habiles` | Plazo de respuesta a solicitudes de teletrabajo (días hábiles) | Todos | 10 | 2026-01-01 | Ley 31572 |
| `treg_alta_derechohabiente_dias_habiles` | T-Registro: alta de derechohabiente desde la comunicación | Todos | 1 | 2026-01-01 | SUNAT, cartilla de derechohabientes (2012) |
| `treg_alta_trabajador_dias` | T-Registro: alta del trabajador (0 = dentro del día del ingreso) | Todos | 0 | 2026-01-01 | DS 018-2007-TR art. 4-A; SUNAT, Obligaciones del empleador |
| `treg_constancia_alta_dias_habiles` | T-Registro: entrega de la constancia de alta | Todos | 1 | 2026-01-01 | SUNAT, Obligaciones del empleador |
| `treg_constancia_baja_dias` | T-Registro: entrega de la constancia de baja (a solicitud) | Todos | 2 | 2026-01-01 | SUNAT, Obligaciones del empleador |
| `treg_constancia_modificacion_dias` | T-Registro: entrega de la constancia de modificación | Todos | 15 | 2026-01-01 | SUNAT, Obligaciones del empleador |
| `treg_modificacion_derechohabiente_dias_habiles` | T-Registro: modificación de derechohabiente | Todos | 5 | 2026-01-01 | SUNAT, cartilla de derechohabientes (2012) |
| `treg_modificacion_dias_habiles` | T-Registro: modificación o actualización de datos | Todos | 5 | 2026-01-01 | DS 018-2007-TR art. 4-A; SUNAT |
| `utilidades_minimo_trabajadores` | Trabajadores a partir de los cuales hay participación en utilidades (más de) | Todos | 20 | 2025-01-01 | DL 892 |
| `utilidades_pct_comercio_restaurantes` | Participación en utilidades: comercio y restaurantes | Todos | 8 | 2025-01-01 | DL 892 |
| `utilidades_pct_industria` | Participación en utilidades: industria | Todos | 10 | 2025-01-01 | DL 892 |
| `utilidades_pct_otras_actividades` | Participación en utilidades: otras actividades | Todos | 5 | 2025-01-01 | DL 892 |
| `vacaciones_dias` | Días de vacaciones por año | Microempresa | 15 | 2025-01-01 | DS 013-2013-PRODUCE |
| `vacaciones_dias` | Días de vacaciones por año | Pequeña empresa | 15 | 2025-01-01 | DS 013-2013-PRODUCE |
| `vacaciones_plazo_goce_meses` | Plazo para gozar un periodo vacacional ganado | Todos | 12 | 2025-01-01 | DL 713 art. 23 |
| `vida_ley_registro_poliza_dias` | Plazo para registrar la póliza de vida | Todos | 30 | 2026-01-01 | DS 009-2020-TR |
