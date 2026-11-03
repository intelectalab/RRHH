# 14 · Localización Perú (paquete de país)

Ayni HR · kit de desarrollo. Desarrolla la corrección C-05 (planilla = motor + reglas + paquete de país + régimen laboral), el punto §28 del pedido y las ampliaciones E-01 a E-05, E-08, E-12, E-15, E-16, E-19, E-20 y E-21.

**Cómo leer este documento**

- Toda cifra legal es un **parámetro con vigencia** del catálogo `database/catalogos/parametros_legales_pe.json` (tabla `legal_parameters`); aquí se cita por su código entre comillas invertidas. Nada de lo escrito aquí se programa como constante.
- «**Valor de referencia 2026**» = dato que `docs/research.md` da por verificado (casi siempre desde fuentes secundarias). Se carga como valor inicial con vigencia.
- «**A confirmar**» = la investigación no lo verificó. El parámetro existe sin valor (o con estado «a confirmar»), la simulación lo advierte y **ningún periodo que lo use puede cerrarse** hasta que el responsable lo confirme (RN-PAR-03, RN-QA-08). Todos están en la sección 21.
- Los algoritmos son seudocódigo del motor (M-NOM): describen pasos y parámetros, no sintaxis. Las fórmulas reales viven como versiones de concepto (`payroll_concept_versions`) y se prueban con casos de referencia («pruebas doradas») que debe aportar el especialista.
- Plantilla de conceptos: `database/catalogos/conceptos_pe.json` (62 conceptos). Sus afectaciones son una propuesta sin validar; los códigos de planilla electrónica marcados como verificados se cotejaron con la Tabla 22 de SUNAT.
- **Detalle técnico:** `18-localizacion-peru-detalle-tecnico.md` profundiza este documento (ficha T-Registro, motor de quinta, archivos planos, regímenes especiales, flujos de cumplimiento y registro inalterable) e indica el estado de verificación de cada dato. Donde ambos difieran, manda el 18.

---

## 1. Contenido del paquete Perú

| Componente | Qué trae | Dónde se usa |
|---|---|---|
| Regímenes laborales | `pe_general`, `pe_micro`, `pe_pequena`, `pe_construccion`, `pe_agrario`, `pe_formativo` | M-NOM, M-COL, M-CON |
| Parámetros legales | 152 parámetros con vigencia (113 con valor, 39 a confirmar) | M-PAR |
| Conceptos y fórmulas | Plantilla de ingresos, descuentos, aportes, impuestos y provisiones con afectaciones | M-CPT |
| Tablas por entidad | AFP (comisión por esquema, prima), entidades depositarias de CTS, EPS, aseguradoras, bancos | M-PAR, M-DEC |
| Tipos de documento de identidad | DNI, carné de extranjería, pasaporte y otros con su validación | M-COL |
| Tipos de contrato | Indeterminado, modalidades a plazo con tope, tiempo parcial, convenios formativos | M-CON |
| Políticas por defecto | Vacaciones, ausencias de ley, jornada, sobretiempo | M-VAC, M-AUS, M-TUR, M-BHO |
| Feriados | Calendario nacional y regionales | M-CFG |
| Plantillas de documentos | Contratos, adendas, boleta, liquidación, certificado de trabajo, carta de CTS, constancias, acuerdo de teletrabajo, cargo de política salarial | M-DOC |
| Declaraciones y archivos | T-Registro, PLAME, AFPnet, bancos, CTS, asiento | M-DEC, M-PRV |
| Obligaciones de cumplimiento | Catálogo con condición de aplicación y regla de evaluación | M-CUM |
| Registros SST | Los ocho registros obligatorios y su conservación | M-SST |
| Validaciones de datos | Formatos de documento, cuenta, CCI, RUC, ubigeo | M-COL, M-IMP |

---

## 2. Parámetros de referencia

| Parámetro (código) | Valor de referencia 2026 | Estado |
|---|---|---|
| `uit` | S/ 5 500 (DS 301-2025-EF, ejercicio 2026) | Verificado |
| `rmv` | S/ 1 130 | Referencia |
| `asignacion_familiar_pct_rmv` | 10 % de la RMV (S/ 113 con la RMV de referencia) | Referencia. Una fuente indica S/ 123, que no cuadra: se usa siempre el porcentaje sobre la RMV vigente |
| `onp_aporte_pct` | 13 % | Referencia |
| `afp_aporte_obligatorio_pct` | 10 % | Referencia |
| `afp_comision_flujo_pct`, `afp_comision_mixta_pct`, `afp_prima_seguro_pct`, `afp_remuneracion_maxima_asegurable` | — | A confirmar: se cargan cada periodo desde lo que publica la SBS |
| `essalud_aporte_pct` | 9 % | Referencia |
| `eps_credito_pct` | — | A confirmar |
| `gratificacion_meses` | Julio y diciembre | Referencia |
| `gratificacion_bonificacion_extraordinaria_pct` | 9 % | Referencia |
| `gratificacion_bonificacion_extraordinaria_eps_pct` | — | A confirmar |
| `cts_meses_deposito` | Mayo y noviembre (primera quincena) | Referencia |
| `cts_fraccion_gratificacion` | Un sexto de la última gratificación | Referencia |
| `quinta_deduccion_uit` | 7 UIT (S/ 38 500 con la UIT de referencia) | Referencia |
| `quinta_tasa_minima_pct`, `quinta_tasa_maxima_pct` | 8 % y 30 % | Referencia |
| `quinta_escala` | Hasta 5 UIT 8 % · hasta 20 UIT 14 % · hasta 35 UIT 17 % · hasta 45 UIT 20 % · más de 45 UIT 30 % | Verificado |
| `quinta_divisores`, `quinta_cortes_retenciones` | 12, 12, 12, 9, 8, 8, 8, 5, 4, 4, 4 y regularización en diciembre | Verificado |
| `quinta_no_domiciliado_tasa_pct` | 30 % sobre el total, sin deducción | Verificado |
| `utilidades_minimo_trabajadores` | Más de 20 | Referencia |
| `utilidades_pct_industria`, `utilidades_pct_comercio_restaurantes`, `utilidades_pct_otras_actividades` | 10 %, 8 %, 5 % | Referencia (la norma tiene más sectores) |
| `utilidades_pct_otros_sectores` | — | A confirmar |
| `vacaciones_dias` (micro y pequeña empresa) | 15 días | Referencia |
| `vacaciones_dias_regimen_general` | — | A confirmar |
| `cts_dias_por_anio` (pequeña empresa) | 15 remuneraciones diarias por año | Referencia |
| `gratificacion_factor` (pequeña empresa) | Media remuneración | Referencia |
| `cts_aplica`, `gratificacion_aplica` (microempresa) | No corresponde | Referencia |
| `mype_ventas_max_uit` | Micro: 150 UIT; pequeña: 1 700 UIT | Verificado (guía del MTPE) |
| `mype_permanencia_anios` | Micro 1 año, pequeña 3 años, tras dos ejercicios consecutivos de exceso | Verificado (guía del MTPE) |
| `treg_alta_trabajador_dias` · `treg_modificacion_dias_habiles` | Dentro del día del ingreso · 5 días hábiles | Verificado (SUNAT) |
| `afpnet_plazo_dias_habiles` | 5 primeros días hábiles del mes siguiente | Referencia (fuente secundaria) |
| Construcción civil (`cc_*`) y agrario (`agr_*`) | Ver `18-localizacion-peru-detalle-tecnico.md` §4.3 y §4.4 | Según cada parámetro |
| `sobretiempo_tasa_tramo1_pct`, `sobretiempo_tasa_tramo2_pct`, `nocturnidad_sobretasa_pct`, `jornada_maxima_semanal_horas` | — | A confirmar |
| `periodo_prueba_meses` | — | A confirmar |
| `contrato_inicio_actividad_max_anios` · `contrato_necesidad_mercado_max_anios` · `contrato_reconversion_max_anios` · `contrato_ocasional_max_meses_por_anio` | 3 años · hasta 5 años · 2 años · 6 meses por año | Referencia |
| `contrato_modalidad_tope_acumulado_anios` | — | A confirmar |
| `liquidacion_plazo_pago_horas` | — | A confirmar (la investigación no verificó las 48 horas) |
| `asistencia_conservacion_anios` | 5 años | Referencia |
| `boletas_conservacion_anios` | — | A confirmar (cinco años citado de memoria) |
| `sst_conservacion_accidentes_anios` · `sst_conservacion_enfermedades_anios` · `sst_conservacion_otros_anios` | 10 · 20 · 5 años | Referencia |
| `sst_comite_minimo_trabajadores` | 20 o más (con menos, supervisor) | Referencia (fuente secundaria) |
| `hostigamiento_comite_minimo_trabajadores` | 20 o más por centro de trabajo | Verificado (DS 014-2019-MIMP art. 27) |
| `host_atencion_dias_habiles` · `host_traslado_comite_dias_habiles` | 1 día hábil cada uno | Verificado (art. 29) |
| `host_medidas_proteccion_dias_habiles` | 3 días hábiles | Verificado (art. 29.2) |
| `host_informe_comite_dias_calendario` · `host_decision_dias_calendario` | 15 y 10 días calendario | Verificado (arts. 29.5 y 29.6) |
| `host_comunicacion_mtpe_queja_dias_habiles` · `host_comunicacion_mtpe_decision_dias_habiles` | 6 días hábiles cada una | Verificado (arts. 29.3 y 29.6) |
| `vida_ley_registro_poliza_dias` | 30 días | Referencia |
| `teletrabajo_respuesta_dias_habiles` · `teletrabajo_desconexion_horas` | 10 días hábiles · 12 horas continuas | Referencia |
| `privacidad_notificacion_incidente_horas` | 48 horas | Referencia |
| `discapacidad_cuota_pct`, `discapacidad_cuota_minimo_trabajadores` | — | A confirmar (3 % y más de 50 trabajadores no confirmados) |
| `lactario_minimo_mujeres_edad_fertil` | — | A confirmar (20 no verificado) |

---

## 3. Regímenes laborales y qué cambia en cada uno

El régimen no es de la organización: se acredita **por empresa** (régimen por defecto y acreditación, `legal_entity_regimes`) y **por relación laboral con vigencia** (`employment_labor_regimes`), porque en una misma empresa conviven trabajadores de regímenes distintos según su fecha de ingreso (RN-NOM-09).

| Aspecto | General (`pe_general`) | Pequeña empresa (`pe_pequena`) | Microempresa (`pe_micro`) | Modalidades formativas (`pe_formativo`) | Construcción civil · Agrario |
|---|---|---|---|---|---|
| Condición | Por defecto | Ventas hasta 1 700 UIT, inscripción en REMYPE | Ventas hasta 150 UIT, inscripción en REMYPE | Convenio según la Ley 28518 | Actividad de la empresa u obra |
| Vacaciones | `vacaciones_dias_regimen_general` (a confirmar) | 15 días | 15 días | Descanso según convenio (a confirmar) | Construcción civil: 10 % de los jornales. Agrario: 30 días |
| Gratificaciones | Una remuneración por semestre | Media remuneración | No corresponde | No corresponde (subvención adicional según la norma, a confirmar) | Construcción civil: 40 jornales por periodo. Agrario: 16,66 % prorrateado u opción semestral |
| CTS | Sí (fórmula en 5.10) | 15 remuneraciones diarias por año, tope 90 | No corresponde | No corresponde | Construcción civil: 15 % de los jornales. Agrario: 9,72 % prorrateado u opción semestral |
| Salud | EsSalud 9 % | EsSalud | SIS o EsSalud, a elección del empleador | Seguro según convenio (a confirmar) | Ver documento 18 §4.1 |
| Asignación familiar | 10 % RMV | No corresponde (fuente secundaria) | No corresponde (fuente secundaria) | No corresponde | Construcción civil: asignación escolar. Agrario: sí, proporcional |
| Utilidades | Sí, si la empresa supera el umbral | Sí | No corresponde | No corresponde | Construcción civil: incluidas en el 15 %. Agrario: 7,5 % hasta 2026, 10 % desde 2027 |
| Indemnización por despido | Fórmula y tope del régimen (a confirmar) | 20 remuneraciones diarias por año, tope 120 | 10 remuneraciones diarias por año, tope 90 | No corresponde | Agrario: 45 remuneraciones diarias por año, tope 360 |
| Planilla | Planilla de trabajadores | Igual | Igual | **Planilla separada**, sin beneficios laborales | Planilla propia con conceptos del régimen |
| T-Registro | Trabajador | Trabajador | Trabajador | Personal en formación | Trabajador |

Reglas del paquete:

1. Cada concepto de la plantilla declara para qué regímenes aplica y con qué parámetros (`regimen` en el parámetro). El motor no tiene condicionales por régimen: resuelve el parámetro del régimen vigente de la relación.
2. Cambio de régimen (la empresa deja de calificar como MYPE, o el trabajador pasa a otro régimen) = nuevo registro con vigencia; los beneficios se calculan por tramos (RN-NOM-05).
3. Construcción civil y agrario tienen **estrategia propia sobre el mismo motor** (jornal diario, planilla semanal, conceptos del convenio o de la Ley 31110). Sus reglas (`RN-PE-CC`, `RN-PE-AGR`), parámetros (`cc_*`, `agr_*`) y conceptos están en `18-localizacion-peru-detalle-tecnico.md` §4. La tabla salarial de construcción civil rige por año calendario desde 2026 (RM 197-2025-TR) y se carga como lote de parámetros. Lo no verificado sigue sin valor.
4. El régimen por empresa alimenta el tablero de cumplimiento (obligaciones que aplican o no).

---

## 4. Conceptos de planilla y afectaciones

**Tipos de concepto:** ingreso, descuento, aporte del trabajador, aporte del empleador, impuesto, beneficio, provisión, informativo.

**Bases de cálculo (afectaciones):** `pension` (aportes previsionales), `essalud`, `quinta`, `cts`, `gratificacion`, `vacaciones`, `utilidades`, más `indemnizacion` cuando el especialista la defina. Cada concepto marca a cuáles suma (`payroll_concept_affectations`).

**Plantilla inicial** (propuesta sin validar; el especialista debe revisar cada casilla antes de producción):

| Concepto (código) | Tipo | Remunerativo | Observación funcional |
|---|---|---|---|
| `rem_basica` Remuneración básica | Ingreso | Sí | Prorrateo por días y por tramos de vigencia |
| `asig_familiar` Asignación familiar | Ingreso | Sí | Derivada de `rmv` × `asignacion_familiar_pct_rmv` |
| `he_tramo1`, `he_tramo2` Horas extra | Ingreso | Sí | Tramos y sobretasas a confirmar |
| `trabajo_nocturno` Sobretasa nocturna | Ingreso | Sí | Franja y sobretasa a confirmar |
| `feriado_trabajado` Feriado o descanso trabajado | Ingreso | Sí | Regla a confirmar |
| `comisiones`, `bono_productividad` | Ingreso | Sí | Variables: entran a beneficios por promedio y regularidad (a confirmar). Código de planilla electrónica de comisiones: 0103 |
| `bono_extraordinario` Bonificación ocasional | Ingreso | A confirmar | Su tratamiento depende de su naturaleza |
| `movilidad` Movilidad supeditada a la asistencia | Ingreso | No (propuesta) | Condición no remunerativa a confirmar |
| `teletrabajo_gastos` Compensación de gastos de teletrabajo | Ingreso | No | Concepto no remunerativo (Ley 31572) |
| `rem_vacacional`, `vacaciones_compra` | Ingreso | Sí | Ver 5.11 |
| `subsidio_incapacidad`, `subsidio_maternidad` | Ingreso | Tratamiento propio | No los paga el empleador como remuneración; reembolso |
| `gratificacion`, `bonif_extraordinaria`, `gratificacion_trunca` | Ingreso | Tratamiento propio | Inafectación a aportes según la Ley 30334 (detalle a confirmar) |
| `cts` | Beneficio | — | Depósito, no pasa por la boleta mensual |
| `utilidades` | Ingreso | Tratamiento propio | Afecta a quinta; resto a confirmar |
| `vacaciones_truncas`, `indemnizacion_vacacional`, `indemnizacion_despido` | Ingreso | Tratamiento propio | Solo en liquidación |
| `afp_aporte`, `afp_comision`, `afp_prima`, `onp_aporte` | Aporte del trabajador | — | Uno u otro sistema según afiliación |
| `renta_quinta` | Impuesto | — | Ver 5.8 |
| `essalud`, `sctr`, `vida_ley` | Aporte del empleador | — | No reducen el neto |
| `dscto_prestamo`, `dscto_adelanto`, `dscto_judicial`, `cuota_sindical`, `eps_copago`, `tardanzas`, `faltas` | Descuento | — | Orden y límite de descuentos a confirmar |
| `prov_cts`, `prov_gratificacion`, `prov_vacaciones` | Provisión | — | Ver M-PRV |

Cada concepto declarable lleva su **código de la planilla electrónica** (`concept_statutory_mappings`); sin él, la validación previa de PLAME falla (RN-CPT-05, RN-DEC-06).

---

## 5. Algoritmos de cálculo

Notación: `P(codigo)` = parámetro vigente en la fecha del periodo para el régimen de la relación. `base(x)` = suma de conceptos del periodo que afectan a la base `x`. Todo resultado guarda su traza (RN-NOM-04).

### 5.1 Remuneración del periodo y prorrateo

```
para cada tramo de vigencia de compensación dentro del periodo:
    dias_tramo = días del tramo según la base de días del régimen (parámetro; base 30 o días calendario: a confirmar)
    monto_tramo = sueldo_mensual_del_tramo × dias_tramo / base_de_dias
rem_basica = Σ monto_tramo − descuento por días no remunerados (faltas, licencias sin goce, suspensiones)
```

Ingreso o cese dentro del mes, cambio de sueldo y licencias generan tramos (RN-NOM-05).

### 5.2 Asignación familiar

```
si la relación tiene al menos un derechohabiente hijo con derecho vigente y sustento aprobado
   y el régimen la contempla:
    asig_familiar = P(rmv) × P(asignacion_familiar_pct_rmv)
si no: 0
```

- Es un monto único por trabajador, no por hijo.
- Condiciones del hijo (edad límite, estudios superiores) y prorrateo por días: a confirmar.
- Un cambio de la RMV cambia el monto desde su vigencia sin tocar el concepto (RN-BSO-07).

### 5.3 Horas extra, trabajo nocturno y feriados

```
valor_hora = remuneración computable para sobretiempo / horas mensuales de la jornada (definición: a confirmar)
por cada día con sobretiempo aprobado y calificado (M-BHO):
    horas_tramo1 = min(horas_extra_del_dia, límite del primer tramo)        # «dos primeras horas»: a confirmar
    horas_tramo2 = horas_extra_del_dia − horas_tramo1
he_tramo1 = Σ horas_tramo1 × valor_hora × (1 + P(sobretiempo_tasa_tramo1_pct))
he_tramo2 = Σ horas_tramo2 × valor_hora × (1 + P(sobretiempo_tasa_tramo2_pct))
trabajo_nocturno = horas en franja nocturna × valor_hora × P(nocturnidad_sobretasa_pct)   # o piso sobre la RMV: a confirmar
feriado_trabajado = regla del día (con o sin descanso sustitutorio): a confirmar
```

Solo se paga lo aprobado (RN-BHO-01); lo compensado va a la bolsa de horas y no a este cálculo.

### 5.4 Sistema de pensiones: AFP y ONP

```
si afiliación = ONP:
    onp_aporte = base(pension) × P(onp_aporte_pct)
si afiliación = AFP (entidad E, esquema de comisión C):
    afp_aporte   = base(pension) × P(afp_aporte_obligatorio_pct)
    afp_comision = base(pension) × tabla_afp[E][C].comision            # sobre flujo; la comisión sobre saldo no se descuenta de planilla
    base_prima   = min(base(pension), P(afp_remuneracion_maxima_asegurable))
    afp_prima    = base_prima × tabla_afp[E].prima
```

- La afiliación (sistema, AFP, tipo de comisión, CUSPP, fecha) vive con vigencia en `employment_pension_affiliations`; un cambio a mitad de mes genera tramos.
- La tabla por AFP y periodo se carga desde la SBS; si falta la del periodo, los afectados quedan «con error» (RN-PAR-04).
- Trabajador recién ingresado sin afiliación elegida, jubilados, mayores de la edad límite para la prima y la reforma previsional (Ley 32123): tratamientos a confirmar; el régimen pensionario es un parámetro para poder absorberlos.

### 5.5 Salud: EsSalud y EPS

```
essalud = max(base(essalud), base mínima) × P(essalud_aporte_pct)            # base mínima: a confirmar
si la relación está afiliada a una EPS:
    credito_eps = regla del crédito contra el aporte × P(eps_credito_pct)     # a confirmar
    aporte a EsSalud = essalud − credito_eps ;  pago a EPS según plan
    eps_copago = aporte del trabajador al plan (descuento, si existe)
si el régimen es microempresa y el empleador eligió el SIS: no hay aporte a EsSalud; cobertura SIS (aporte: a confirmar). Si eligió EsSalud, aporta como el régimen general
```

Subsidios por incapacidad y maternidad: M-AUS separa los días a cargo del empleador de los días subsidiados (umbral a confirmar), M-PER los paga como concepto de subsidio y M-AUS sigue el reembolso.

### 5.6 SCTR

```
si el puesto o la sede de la relación están marcados de riesgo:
    sctr_salud   = base(sctr) × tasa de la póliza de salud vigente        # tasa contratada, valor propio de la organización
    sctr_pension = base(sctr) × tasa de la póliza de pensión vigente
```

La póliza (`insurance_policies`), su vigencia y sus asegurados por periodo (`insurance_policy_members`) se administran en el sistema; un ocupante de puesto de riesgo fuera de la póliza es una alerta de cumplimiento (RN-SST-08).

### 5.7 Vida Ley

```
desde el primer día de la relación:
    incluir al trabajador en la póliza vigente y registrar a sus beneficiarios (declaración firmada)
    vida_ley = base del seguro × tasa de la póliza por categoría de trabajador      # tasa y base: valor contratado; topes a confirmar
al contratar o renovar la póliza: tarea de registro ante el MTPE dentro de P(vida_ley_registro_poliza_dias)
```

### 5.8 Renta de quinta categoría (retención mensual por proyección)

Resumen; la definición formal, el seudocódigo completo, el diccionario de datos y cuatro casos resueltos están en `18-localizacion-peru-detalle-tecnico.md` §2.

```
cada mes M del ejercicio (trabajador domiciliado, no exonerado, y esta empresa es la que retiene):
 1. rem_proyectada = remuneración ordinaria del mes × meses que faltan (incluido M)
                   + gratificaciones ordinarias por percibir, con su bonificación extraordinaria
                   + remuneraciones y otros ingresos afectos percibidos en los meses anteriores (incluidos extraordinarios)
                   + rentas de otros empleadores declaradas por el trabajador
                   (el ingreso extraordinario del propio mes NO entra aquí: paso 6)
 2. renta_neta = rem_proyectada − P(quinta_deduccion_uit) × P(uit)        ; si ≤ 0 → impuesto 0
 3. impuesto_anual = P(quinta_escala) por tramos acumulativos: 8 %, 14 %, 17 %, 20 %, 30 % (topes de 5, 20, 35 y 45 UIT)
 4. por_retener = impuesto_anual − retenciones de los meses hasta el corte del mes:
        enero a marzo: ninguna · abril: enero a marzo · mayo a julio: enero a abril · agosto: enero a julio
        setiembre a noviembre: enero a agosto · diciembre: enero a noviembre
 5. retencion_ordinaria = por_retener / P(quinta_divisores)[M]             # 12, 12, 12, 9, 8, 8, 8, 5, 4, 4, 4, 1
 6. si en el mes hay ingresos extraordinarios (utilidades, bonos, gratificación extraordinaria):
        retencion_adicional = impuesto(renta_neta + extraordinario) − impuesto(renta_neta)     # se retiene íntegra
 7. retención del mes = max(0, retencion_ordinaria) + retencion_adicional
en diciembre: regularización (puede resultar exceso a devolver) · al cese: ajuste final con lo realmente percibido
no domiciliado: P(quinta_no_domiciliado_tasa_pct) sobre el total afecto del mes, sin proyección ni deducción
```

Datos y documentos asociados:

- **Las 3 UIT adicionales por gastos no entran en la retención**: solo las aplica la administración tributaria en la determinación anual. El motor no tiene campo para ellas.
- Declaración jurada de rentas y retenciones de otros empleadores (`external_income_declarations`): la presenta quien ingresa a mitad de año o tiene empleadores simultáneos. Con varios empleadores retiene el que paga la mayor remuneración; los demás dejan de retener.
- Libro de retenciones (`fifth_category_ledger`, solo inserción): una fila por cálculo con sus entradas, parámetros y traza.
- Certificado de rentas y retenciones (`withholding_certificates`): se emite al cese, al cierre o a solicitud. **Su entrega dejó de ser obligatoria** (DS 033-2017-EF); el sistema lo ofrece siempre al trabajador.
- Retenciones en exceso (`fifth_category_refunds`): al cese se devuelven en la liquidación y se compensan con las retenciones del periodo; el saldo se arrastra o se pide a la administración tributaria.
- La UIT del ejercicio se toma por vigencia: un cambio de UIT cambia la deducción y los tramos sin tocar la fórmula.
- A confirmar: pagos semanales, promedio de remuneración variable, créditos contra el impuesto, retenciones previas declaradas sin detalle mensual y momento de la devolución cuando el trabajador continúa.

### 5.9 Gratificaciones (Ley 27735 y Ley 30334)

```
proceso de julio (semestre enero–junio) y de diciembre (julio–diciembre):
 rc = remuneración computable vigente al cierre del semestre
      (básico + conceptos regulares que afectan a base(gratificacion); variables por promedio: a confirmar)
 meses = meses calendario completos laborados en el semestre                 # días que cuentan y que no: a confirmar
 gratificacion = rc × P(gratificacion_factor del régimen) × meses / 6
        # régimen general: factor 1 · pequeña empresa: 0,5 · microempresa: no corresponde
 bonif_extraordinaria = gratificacion × P(gratificacion_bonificacion_extraordinaria_pct)
        # afiliados a EPS: P(gratificacion_bonificacion_extraordinaria_eps_pct), a confirmar
 requisito: estar laborando en la oportunidad del pago o en descanso con derecho (a confirmar)
gratificación trunca (al cese): mismos sextos por los meses completos del semestre en curso, en la liquidación
```

Se procesa como un periodo propio (cálculo, aprobación por persona distinta, cierre, boleta, pago).

### 5.10 CTS (DS 001-97-TR)

```
proceso de mayo (semestre noviembre–abril) y de noviembre (mayo–octubre):
 rc = remuneración computable del mes de cierre del semestre
      + P(cts_fraccion_gratificacion) × última gratificación percibida        # un sexto
      (variables por promedio y regularidad: a confirmar)
 régimen general:
      cts = rc / 12 × meses completos del semestre + rc / 12 / 30 × días adicionales     # fórmula a confirmar por el especialista
 pequeña empresa:
      cts = remuneración diaria × P(cts_dias_por_anio) × tiempo del semestre en años      # 15 remuneraciones diarias por año; tope: a confirmar
 microempresa: no corresponde
depósito: dentro de la primera quincena del mes (P(cts_meses_deposito)), en la entidad elegida por el trabajador
tras el depósito: liquidación de CTS al trabajador (fecha, entidad, cuenta, periodo, cálculo)
al cese: CTS trunca en la liquidación y carta de liberación a la entidad depositaria
```

Requisitos de acceso (jornada mínima, antigüedad mínima) y tratamiento de tiempo parcial: a confirmar. Si el trabajador no tiene entidad depositaria, el proceso lo reporta como error (RN-BSO-05).

### 5.11 Vacaciones (DL 713)

```
récord: por cada año completo de servicios, P(vacaciones_dias del régimen) días      # general: a confirmar · micro y pequeña: 15
        condicionado al récord de días efectivos del año (umbral según jornada: a confirmar)
saldo = Σ movimientos del libro (devengo, goce, venta, ajuste, vencimiento)          # RN-VAC-02
remuneración vacacional = la que el trabajador percibiría de seguir laborando (variables por promedio: a confirmar),
        pagada antes del inicio del descanso (oportunidad: a confirmar)
fraccionamiento, adelanto, acumulación y venta de días: reglas y topes a confirmar; son políticas con mínimo legal
si el descanso no se goza dentro del año siguiente al que generó el derecho:
        indemnizacion_vacacional según la norma (monto y exclusiones: a confirmar) + alerta de periodo vencido
al cese: vacaciones no gozadas (periodos completos) + vacaciones truncas (dozavos y treintavos del periodo en curso)
```

### 5.12 Utilidades (DL 892)

```
aplica si: la empresa genera renta de tercera categoría y supera P(utilidades_minimo_trabajadores) trabajadores
           (forma de contar el promedio: a confirmar)
monto_a_repartir = renta anual antes de impuestos (definición exacta: a confirmar) × porcentaje de la actividad
        # industria 10 % · comercio y restaurantes 8 % · otras actividades 5 % · demás sectores: a confirmar
mitad_dias          = monto_a_repartir × 50 %      → por trabajador: × días laborados / Σ días laborados de todos
mitad_remuneracion  = monto_a_repartir × 50 %      → por trabajador: × remuneraciones del año / Σ remuneraciones de todos
participacion = parte_por_dias + parte_por_remuneracion, limitada al tope individual     # tope en remuneraciones: a confirmar
remanente sobre el tope: destino según la norma (a confirmar)
incluye a quienes cesaron durante el ejercicio, por el tiempo laborado
liquidación individual por trabajador con el detalle del cálculo; retención de quinta sobre el pago
plazo de pago: tras la declaración anual (plazo: a confirmar)
```

Qué días cuentan como laborados (licencias, descansos médicos, jornada parcial) y si el reparto varía entre regímenes: a confirmar.

### 5.13 Otros descuentos

- **Retenciones judiciales** (`judicial_withholdings`): porcentaje o monto fijado por mandato, base y prelación a confirmar; el dato es sensible.
- **Tardanzas y faltas**: descuento proporcional al tiempo no laborado desde asistencia cerrada; efecto en el día de descanso semanal: a confirmar.
- **Límite de descuentos y prelación** (judicial, tributario, previsional, préstamos, voluntarios): a confirmar; el motor lo aplica como regla parametrizada (RN-CPT-08, RN-PRE-03).

---

## 6. Calendario de obligaciones

Se publica como datos del paquete (M-CUM → «Calendario de obligaciones»). Las fechas exactas dependen de cronogramas oficiales que se cargan cada año.

| Frecuencia | Obligación | Fuente de la fecha |
|---|---|---|
| Al ingreso | Alta en T-Registro; contrato firmado; inclusión en Vida Ley y SCTR si aplica; entrega de reglamentos, política salarial y recomendaciones de SST; capacitación en hostigamiento sexual y SST; declaración de beneficiarios; declaración jurada de rentas de otros empleadores si ingresa después de enero | Alta dentro del día del ingreso; constancia al día hábil siguiente (`treg_*`) |
| Mensual | Pago de remuneraciones y entrega de boleta | Calendario de la empresa |
| Mensual | PLAME (declaración y pago de EsSalud, ONP, retención de quinta) | Cronograma anual de la administración tributaria según último dígito del RUC (se carga cada año) |
| Mensual | Planilla y pago de aportes en AFPnet | Cinco primeros días hábiles del mes siguiente (`afpnet_plazo_dias_habiles`) |
| Mensual | Provisiones y asiento de planilla | Cierre contable de la empresa |
| Mayo y noviembre | Depósito de CTS (primera quincena) y entrega de la liquidación de CTS | `cts_meses_deposito` |
| Julio y diciembre | Gratificaciones y bonificación extraordinaria | `gratificacion_meses` (día límite: a confirmar) |
| Anual | Utilidades: reparto y liquidación individual | Tras la declaración anual (plazo: a confirmar) |
| A solicitud | Certificado de rentas y retenciones de quinta (entrega no obligatoria desde 2017) | Cierre del ejercicio, cese o pedido del trabajador |
| Mensual | Sesión ordinaria del comité de SST y su acta | `sst_reunion_ordinaria_periodicidad` |
| Anual | Capacitación en hostigamiento sexual, evaluación anual; plan anual de SST y capacitaciones; elecciones del comité cuando vence su mandato | Reglamentos respectivos |
| Anual | Cuota de empleo de personas con discapacidad: cálculo sobre el promedio anual | Fiscalización anual |
| Por evento | Renovación de contratos antes del vencimiento; registro de la póliza Vida Ley dentro de 30 días; notificación de accidentes; respuesta a requerimientos de la casilla electrónica; notificación de incidentes de datos en 48 horas; respuesta a solicitudes de teletrabajo en 10 días hábiles | Parámetros de la sección 2 |
| Al cese | Liquidación y pago; certificado de trabajo; carta de CTS; baja en T-Registro y seguros | `liquidacion_plazo_pago_horas` (a confirmar) |

---

## 7. Archivos

Principios comunes (M-DEC): se generan solo desde periodos cerrados; pasan una validación previa que enlaza cada error con el dato de origen; cada archivo guarda versión, huella y estado (borrador → validado → presentado → aceptado u observado → rectificado, ME-24); una corrección posterior produce una rectificatoria, nunca una regeneración encima.

### 7.1 T-Registro

- **Qué declara:** altas, bajas y modificaciones de trabajadores, derechohabientes y personal en formación, con régimen pensionario, régimen de salud, tipo de contrato, establecimiento y demás datos del catálogo de la autoridad.
- **Origen:** `registry_events`, creados por los eventos `employee.hired`, `employee.terminated`, `dependent.added` y por cambios declarables de la ficha.
- **Ficha:** `peruvian_employee_profiles` y tablas hermanas guardan, con vigencia, los datos que el T-Registro pide y el núcleo no tiene; las tablas paramétricas se cargan en `pe_parametric_items` (`18-localizacion-peru-detalle-tecnico.md` §1).
- **Control:** cada novedad nace con fecha límite (alta dentro del día del ingreso, modificación 5 días hábiles, baja al día siguiente con tipo de día a confirmar) y alerta; si no pasa las comprobaciones TR-01 a TR-22 queda bloqueada con la lista de faltantes (RN-DEC-05).
- **Salida:** archivos de carga masiva en la estructura vigente de la autoridad (versión de estructura como dato del paquete) y constancia de alta para el trabajador.

### 7.2 PLAME (PDT 601)

- **Qué contiene:** archivos de importación de jornada laboral, ingresos, descuentos y aportes por trabajador, días subsidiados y no laborados, y los de otras categorías que la empresa declare (cuarta categoría para locadores, personal en formación).
- **Mapeo:** cada concepto → código de la tabla de conceptos de la autoridad (`concept_statutory_mappings`, con vigencia). El paquete trae la tabla de códigos vigente como dato versionado; los códigos de la plantilla marcados como verificados se cotejaron con la Tabla 22, las **afectaciones no están validadas**.
- **Estructuras:** cada archivo se serializa desde `statutory_file_layouts` (estructura campo por campo con vigencia). Nomenclatura `0601` + periodo + RUC + extensión, separador `|`. Las longitudes y obligatoriedades del anexo oficial están por cargar (`18-localizacion-peru-detalle-tecnico.md` §3).
- **Validación previa:** conceptos sin código; trabajadores sin alta en T-Registro; totales distintos del periodo cerrado; bases inconsistentes; días que no suman el mes.
- **Rectificatorias:** por ajuste retroactivo o error; enlazadas a la declaración original.

### 7.3 AFPnet

- **Qué contiene:** una línea por trabajador con 17 columnas: secuencia, CUSPP, tipo y número de documento, apellidos y nombres, relación laboral, inicio y cese en el mes, excepción de aportar, remuneración asegurable, aportes voluntarios (con fin previsional, sin fin previsional y del empleador), rubro y AFP. El aporte obligatorio, la comisión y la prima **no van en el archivo**: los calcula AFPnet y el sistema concilia (`18-localizacion-peru-detalle-tecnico.md` §3.5).
- **Entrada:** resultados cerrados + tabla de la AFP del periodo.
- **Salida:** archivo Excel o de texto de ancho fijo en la estructura vigente de AFPnet y ticket de pago adjunto como constancia. Plazo: cinco primeros días hábiles del mes siguiente.

### 7.4 Bancos y billeteras

- Archivos de pago masivo de haberes, de CTS (por entidad depositaria) y de honorarios, en el formato de cada banco (`payroll_payment_batches`). Los formatos son configuración (`payroll.file_formats`), no código.
- Validaciones: cuenta o CCI por trabajador, moneda, total igual al neto del periodo cerrado (RN-DEC-09), duplicados.
- Conciliación: carga del archivo de respuesta del banco; los rechazos dejan a la persona con «pago pendiente» (RN-DEC-08).
- Billeteras digitales: mismo flujo por adaptador (M-INT).

### 7.5 Asiento contable

- Asiento de planilla, de provisiones (CTS, gratificaciones, vacaciones), de pagos y de liquidaciones, por centro de costo (`journal_entries`).
- Reglas: `concept_account_mappings` por concepto, tipo de trabajador y centro de costo; el debe iguala al haber o no se exporta (RN-PRV-01).
- Salida: archivo configurable por ERP y envío por API con idempotencia; referencia del ERP guardada.
- Plan de cuentas de ejemplo del paquete: propuesta que el contador de cada empresa ajusta.

### 7.6 Otros

Liquidación de depósito de CTS, certificado de quinta categoría, constancias de alta y baja, reporte de retenciones judiciales, reporte de cuarta categoría de locadores.

---

## 8. Liquidación de beneficios sociales

Entidad `settlements` (ME-23). Se crea en borrador al registrar el cese y se calcula con los datos a la fecha de cese.

| Concepto | Algoritmo | Estado |
|---|---|---|
| Remuneración pendiente | Días laborados del mes de cese no pagados (5.1) | — |
| CTS trunca | 5.10 por el tiempo desde el último depósito | Fórmula a confirmar |
| Gratificación trunca y su bonificación | 5.9 por meses completos del semestre en curso | — |
| Vacaciones no gozadas | Periodos completos ganados y no gozados × remuneración | A confirmar la base |
| Vacaciones truncas | Dozavos y treintavos del periodo en curso | Requisitos a confirmar |
| Indemnización vacacional | Si hay periodos vencidos sin goce | Monto y exclusiones a confirmar |
| Indemnización por despido | Solo si el motivo de cese la genera; fórmula y tope por régimen y tipo de contrato | A confirmar |
| Bolsa de horas | Saldo pagado con su sobretasa | — |
| Descuentos | Préstamos, adelantos, aportes previsionales y quinta sobre lo afecto; activos no devueltos solo si la norma y la autorización del trabajador lo permiten | Límites a confirmar |

- **Simulador:** misma lógica con fecha y motivo hipotéticos, sin crear cese ni notificar; queda como acceso sensible (RN-LIQ-09).
- **Plazo de pago:** `liquidacion_plazo_pago_horas` (a confirmar); el sistema alerta antes de vencer.
- **Documentos de cese:** liquidación firmada, certificado de trabajo, carta de liberación de CTS, constancia de cese, certificado de rentas y retenciones de quinta (disponible; entrega no obligatoria), constancia de baja en T-Registro (a solicitud). La liquidación incluye el ajuste final de quinta y la devolución del exceso si lo hay.
- **Segregación:** quien calcula no aprueba (RN-LIQ-06).
- **Fallecimiento:** pago a beneficiarios con sustento (RN-LIQ-10).
- **Regímenes:** micro y pequeña empresa aplican sus factores; modalidades formativas no generan beneficios sociales.

---

## 9. Contratos a modalidad y topes

- El paquete trae el catálogo de tipos (`contract_types`): indeterminado, las **nueve modalidades** sujetas a plazo del TUO del DL 728, tiempo parcial y convenios formativos.
- Valores de referencia de plazo máximo: inicio de actividad 3 años (`contrato_inicio_actividad_max_anios`), necesidad de mercado hasta 5 (`contrato_necesidad_mercado_max_anios`), reconversión empresarial 2 (`contrato_reconversion_max_anios`), ocasional 6 meses por año (`contrato_ocasional_max_meses_por_anio`). Las otras cinco modalidades y el tope acumulado (`contrato_modalidad_tope_acumulado_anios`): a confirmar.
- Cada contrato a plazo exige forma escrita y **causa objetiva** (RN-CON-01).

```
al crear o renovar un contrato a modalidad M para la persona en la empresa:
    acumulado_M     = Σ duración de contratos de modalidad M (regla de reingreso: a confirmar)
    acumulado_total = Σ duración de todos los contratos a modalidad
    si acumulado_M + nuevo_plazo > tope(M)                         → bloquear, explicar
    si acumulado_total + nuevo_plazo > P(contrato_modalidad_tope_acumulado_anios) → bloquear, proponer indeterminado
alertas: a N días del vencimiento (varias anticipaciones, parámetro) → RRHH y jefe
si vence sin renovación ni cese y la persona sigue activa → «vencido sin regularizar»: riesgo de desnaturalización
```

- Renovación masiva con generación de prórrogas y firma (M-CON).
- El registro del contrato ante el MTPE dejó de exigirse en noviembre de 2016 (dato de la investigación); el sistema no lo pide.
- Periodo de prueba por tipo de puesto (`periodo_prueba_meses`): a confirmar; el sistema crea la evaluación de periodo de prueba antes de su término.

---

## 10. Registro de control de asistencia (DS 004-2006-TR)

- **Contenido mínimo** del registro exportable: razón social y RUC, nombre y documento del trabajador, fecha, hora y minutos de ingreso y de salida, y sobretiempo (RN-ASI-02).
- **Inalterabilidad:** las marcaciones son de solo inserción; toda corrección es un registro nuevo con motivo, autor y aprobación enlazado al original (RN-ASI-01, corrección C-07).
- **Conservación:** `asistencia_conservacion_anios` = 5 años; la política de retención no puede purgar antes.
- **Disponibilidad:** exportación por trabajador y periodo para inspección, incluida en el expediente SUNAFIL.
- **Personal excluido:** dirección y trabajadores no sujetos a fiscalización inmediata se marcan «no sujeto a control» (alcance exacto de la exclusión: a confirmar).
- **Medios:** web, aplicación, kiosko, QR, PIN, cuadrilla, relojes biométricos; cualquier medio produce el mismo registro. La biometría exige consentimiento y evaluación de impacto (sección 15).
- Tolerancias, tiempo de refrigerio y obligación de exhibir horarios: a confirmar.

---

## 11. Teletrabajo (Ley 31572, DS 002-2023-TR, Ley 32102)

- **Acuerdo** (`remote_work_agreements`) con contenido mínimo: modalidad total o parcial, jornada, desconexión, lugar, supervisión, equipos, compensación. El sistema no envía a firma un acuerdo incompleto (RN-CON-10).
- **Solicitudes** de cambio de modalidad: respuesta dentro de `teletrabajo_respuesta_dias_habiles` (10 días hábiles), con alerta.
- **Desconexión digital:** mínimo de `teletrabajo_desconexion_horas` (12 horas continuas); las notificaciones no urgentes respetan esa ventana (RN-NOT-08).
- **Compensación de gastos** (energía, internet): concepto `teletrabajo_gastos`, no remunerativo. Montos de referencia: no investigados; los define la empresa o el acuerdo.
- **MYPE:** exceptuadas de la compensación salvo pacto (dato de la investigación).
- **SST en teletrabajo:** autoevaluación de riesgos del puesto remoto: a confirmar.
- Asistencia: marcación remota según política; el día remoto se distingue del presencial en el horario.

---

## 12. Seguridad y salud en el trabajo (Ley 29783, DS 005-2012-TR)

**Los ocho registros obligatorios** (M-SST → «Registros obligatorios»), con formatos referenciales de la RM 050-2013-TR:

| Registro | Origen en el sistema | Conservación (valor de referencia) |
|---|---|---|
| Accidentes de trabajo, enfermedades ocupacionales, incidentes peligrosos y otros incidentes | `sst_incidents`, `sst_investigations` | 10 años accidentes e incidentes peligrosos; 20 años enfermedades ocupacionales |
| Exámenes médicos ocupacionales | `occupational_exams` | 5 años |
| Monitoreo de agentes físicos, químicos, biológicos, psicosociales y disergonómicos | `agent_monitorings` | 5 años |
| Inspecciones internas | `sst_inspections`, `sst_inspection_findings` | 5 años |
| Estadísticas de seguridad y salud | Calculadas | 5 años |
| Equipos de seguridad o emergencia | `safety_equipment`, `ppe_deliveries` | 5 años |
| Inducción, capacitación, entrenamiento y simulacros | `enrollments`, `training_sessions`, `sst_drills` | 5 años |
| Auditorías | `sst_audits` | 5 años |

- **Además:** matriz de peligros y riesgos (IPERC) por puesto y sede; entrega de EPP con firma; comité con 20 o más trabajadores o supervisor con menos (`sst_comite_minimo_trabajadores`), con proceso electoral (ME-46), sesiones mensuales y actas en el libro inalterable (ME-47; `18-localizacion-peru-detalle-tecnico.md` §5.1); plan y programa anual; reglamento interno; mapa de riesgos.
- **MYPE:** registros simplificados; la norma que los aprueba tiene referencia discrepante en la investigación (RM 082-2013-TR frente a RM 085-2013-TR): a confirmar.
- **Notificación de accidentes** a la autoridad: plazos y medio a confirmar.
- **Datos médicos:** el empleador ve aptitud y restricciones, no diagnósticos (RN-SST-04).
- **SCTR:** sección 5.6.

---

## 13. Hostigamiento sexual (Ley 27942, DL 1410, DS 014-2019-MIMP, DS 021-2021-MIMP)

- **Órgano:** comité de intervención de cuatro miembros (dos del empleador, uno de ellos de Recursos Humanos, y dos de los trabajadores, con paridad de género) cuando el centro de trabajo tiene 20 o más trabajadores (`hostigamiento_comite_minimo_trabajadores`); delegado si son menos. Acuerdos por mayoría simple, con voto dirimente de Recursos Humanos. El tablero de cumplimiento controla su existencia y vigencia.
- **Canal confidencial:** M-DEN, con anonimato real cuando se elige (RN-DEN-01 a 03).
- **Plazos del procedimiento** (art. 29 del DS 014-2019-MIMP; cada uno es un parámetro `host_*`):

| Acto | Plazo | Desde |
|---|---|---|
| Ofrecer atención médica y psicológica | 1 día hábil | Recepción de la queja |
| Trasladar la queja al comité | 1 día hábil | Recepción de la queja |
| Dictar y ejecutar las medidas de protección | 3 días hábiles | Recepción de la queja |
| Comunicar la queja y las medidas al MTPE | 6 días hábiles | Recepción de la queja |
| Informe del comité (incluye descargos) | 15 días calendario | Recepción de la queja |
| Decisión de Recursos Humanos (incluye alegatos) | 10 días calendario | Recepción del informe |
| Comunicar la decisión al MTPE | 6 días hábiles | Emisión de la decisión |

- No hay un parámetro de duración total: resulta de las etapas. Máquina ME-48; cada acto se asienta en el libro inalterable del caso (`18-localizacion-peru-detalle-tecnico.md` §5.2 y §5.4).
- **Capacitación** al inicio de la relación y una anual especializada para Recursos Humanos y el comité (no exigible a microempresas acreditadas), y **evaluación anual** de riesgo.
- La sanción se ejecuta por M-DIS sin exponer la identidad del denunciante.

---

## 14. Igualdad salarial (Ley 30709, DS 002-2018-TR)

- **Cuadro de categorías y funciones:** cada puesto (`job_profiles`) pertenece a una categoría con funciones, responsabilidad y requisitos. Puesto sin categoría = incumplimiento visible (RN-ORG-07, RN-COM-07).
- **Política salarial:** documento versionado, publicado y comunicado.
- **Evidencia de comunicación:** al ingreso, al cambio de puesto y al cambio de remuneración se genera el cargo y se registra el acuse (`salary_policy_communications`, RN-HIS-07, RN-COM-08).
- **Reporte de brecha:** por categoría y sexo; cada diferencia relevante se justifica con criterios objetivos (experiencia, formación, antigüedad, desempeño) o genera un plan de corrección. El umbral de revisión es una política de la organización, no un valor legal.
- **Confidencialidad:** grupos pequeños se agregan (RN-COM-10).
- Forma parte del expediente de inspección.

---

## 15. Datos personales (Ley 29733, DS 016-2024-JUS vigente desde el 30-03-2025)

| Exigencia | Cómo la cubre el sistema |
|---|---|
| Consentimiento y finalidad por tratamiento | `consents` con versión y revocación; `processing_purposes` |
| Derechos de acceso, rectificación, cancelación, oposición y portabilidad | `data_subject_requests` (ME-39); plazos de respuesta: a confirmar |
| Inscripción de bancos de datos | `data_banks`: inventario con su estado de inscripción |
| Notificación de incidentes | `privacy_incidents` con cuenta regresiva de `privacidad_notificacion_incidente_horas` (48 horas) |
| Oficial de datos personales | Rol personalizado sugerido en `07-permisos-y-roles.md`; obligatoriedad progresiva según tamaño: a confirmar |
| Evaluación de impacto | `impact_assessments`, obligatoria antes de activar biometría o perfilado con IA (RN-PRI-08) |
| Transferencia internacional | Registro de transferencias y garantías; afecta el alojamiento fuera del país |
| Seguridad | Cifrado, permisos por campo, registro de accesos (`data_access_logs`) |
| Retención | `retention_policies`, `retention_runs`, `legal_holds`; nunca por debajo de los plazos laborales (asistencia, SST, documentos de pago) |

Afecta de forma directa a: marcación facial y selfie, selección con IA, riesgo de rotación, datos de salud y discapacidad, datos de candidatos.

---

## 16. Cuota de empleo de personas con discapacidad (Ley 29973, DS 002-2014-MIMP, RM 107-2015-TR)

```
promedio_anual = promedio de trabajadores del ejercicio (método de la RM 107-2015-TR: a confirmar)
si promedio_anual > P(discapacidad_cuota_minimo_trabajadores):            # a confirmar
    cuota = promedio_anual × P(discapacidad_cuota_pct)                    # a confirmar
    cumplidos = trabajadores con condición registrada y consentimiento, ponderados por tiempo
    estado = cumple / incumple, con explicación
```

- La condición de discapacidad es dato sensible: se registra solo con consentimiento y la ven solo los roles con `people.people.health_data.view`.
- Registro de convocatorias, ajustes razonables y sustentos que la norma admite como justificación.

## 17. Lactario (Ley 29896, DS 023-2021-MIMP)

- Indicador por sede: número de mujeres en edad fértil (rango de edad y umbral `lactario_minimo_mujeres_edad_fertil`: a confirmar).
- Al alcanzar el umbral, la obligación pasa a «aplica» y pide evidencia de implementación y de uso.

## 18. Modalidades formativas (Ley 28518)

- Convenios por tipo (prácticas preprofesionales y profesionales, aprendizaje y otros) como relación de régimen `pe_formativo`.
- **Planilla separada** con subvención, sin beneficios laborales; descanso, seguro, jornada máxima, plazos máximos y límite de practicantes respecto de la planilla: **a confirmar** (parametrizables).
- Declaración en T-Registro como personal en formación.
- Alertas de vencimiento de convenio igual que en contratos.

## 19. Régimen MYPE (TUO aprobado por DS 013-2013-PRODUCE; REMYPE)

- La empresa registra su condición (micro o pequeña), su inscripción en REMYPE y sus ventas anuales en UIT; el sistema alerta si las ventas superan `mype_ventas_max_uit` del tramo (150 o 1 700 UIT).
- Efectos en el cálculo: sección 3.
- Pérdida de la condición: si las ventas superan el tope dos ejercicios consecutivos, la microempresa conserva el régimen un año más y la pequeña empresa tres; no hay retorno a la categoría anterior. Se modela como cambio de régimen con vigencia por relación. Los contratados antes de la acreditación conservan su régimen.
- La **Ley 32353** (mayo de 2025) crea un régimen MYPE nuevo cuya parte laboral rige desde la publicación de su reglamento; a la fecha de corte no consta publicado. Sus cambios se cargarán como parámetros con vigencia (`18-localizacion-peru-detalle-tecnico.md` §4.2).
- Obligaciones que cambian: SST simplificado, teletrabajo sin compensación obligatoria salvo pacto, comité frente a supervisor.

## 20. SUNAFIL

- **Casilla electrónica** (DS 003-2020-TR): la notificación es válida con el depósito, se revise o no; surte efectos ese día (o el primer día hábil siguiente) y el plazo corre desde el día hábil siguiente. Bandeja `mailbox_notices` con instante del depósito, responsable, plazo y estado (ME-45; RN-CUM-08), y registro de la revisión diaria (`18-localizacion-peru-detalle-tecnico.md` §5.3).
- **Expediente de inspección** (`inspection_dossiers`): por empresa, trabajadores y periodo reúne boletas con constancia de entrega, registro de asistencia, contratos, documentos SST, constancias de T-Registro y depósitos; lista los faltantes.
- **Tablero de cumplimiento** (M-CUM): cada obligación de este documento con semáforo, explicación, responsable y evidencia.
- Tabla de infracciones y multas: fuera del alcance (no investigada); el sistema no estima multas.
- La Ley 31736 figura como no verificada en la investigación.

---

## 21. Puntos a validar con un especialista laboral antes de producción

Ningún cálculo de nómina sale a producción con un punto de esta lista abierto (RN-QA-08). Cada punto corresponde a un parámetro, una fórmula o una regla marcada «a confirmar». La lista se actualizó con la verificación de `18-localizacion-peru-detalle-tecnico.md` (su §6.1 detalla qué quedó verificado y con qué fuente).

**Ya verificado (sale de la lista):** UIT 2026; escala completa, divisores y cortes de quinta, retención adicional por extraordinarios, no domiciliados, exclusión de las 3 UIT, declaración jurada del trabajador, más de un empleador, ajuste final al cese; plazos de alta y modificación en T-Registro; formato de AFPnet; códigos de la Tabla 22 de las series 0100 a 0700 y 0900; requisitos, beneficios, indemnización y permanencia MYPE; tabla salarial 2026 de construcción civil; régimen agrario de la Ley 31110; umbral, composición y todos los plazos del procedimiento de hostigamiento sexual; umbral del comité de SST (fuente secundaria).

**Parámetros y tasas**

1. Fecha de inicio y norma de la RMV S/ 1 130 (tomada de fuentes secundarias).
2. Comisiones por AFP y esquema, prima de seguro y remuneración máxima asegurable del periodo; fuente oficial y frecuencia de actualización.
3. Tasa de ONP (13 %) y de EsSalud (9 %): confirmar en norma y base mínima imponible de EsSalud.
4. Crédito por EPS contra el aporte a EsSalud y aporte al SIS en microempresa.
5. Bonificación extraordinaria de gratificación para afiliados a EPS, y si aplica a pequeña empresa, construcción civil y agrario.
6. Quinta categoría: redacción vigente del art. 40 del Reglamento (fórmula por diferencia), pagos semanales o quincenales, promedio de remuneración variable, créditos, retenciones previas sin detalle mensual, momento de la devolución cuando el trabajador continúa, trámite de la R.S. 036-98/SUNAT.
7. Sobretasas de horas extra por tramo, límite del primer tramo, sobretasa y franja nocturna, pago de feriado y descanso trabajados (régimen general).
8. Jornada máxima diaria y semanal, refrigerio y descanso mínimo entre jornadas.
9. Días de vacaciones del régimen general, récord mínimo, fraccionamiento, adelanto, acumulación y venta de días.
10. Monto, base y exclusiones de la indemnización por vacaciones no gozadas.
11. Porcentajes de utilidades de los sectores no investigados; definición de la renta base; tope individual y destino del remanente; forma de contar los «más de 20 trabajadores»; plazo de pago; días que cuentan como laborados.
12. Condiciones de la asignación familiar (edad límite, estudios superiores, ambos padres en la empresa, prorrateo); confirmar que no corresponde en micro y pequeña empresa.

**Fórmulas de beneficios**

13. Fórmula de CTS del régimen general (dozavos y treintavos), requisitos de acceso, tratamiento de tiempo parcial.
14. Remuneración computable de CTS y gratificación: conceptos regulares, promedio de variables y requisito de regularidad.
15. Gratificación: días y licencias que cuentan como mes completo, requisito de estar laborando, fecha límite de pago, inafectaciones de la Ley 30334.
16. Base de días para prorrateos (30 frente a días calendario) y efecto de faltas en el descanso semanal.
17. Indemnización por despido del régimen general: fórmula y tope; casos en que procede.
18. Plazo de pago de la liquidación (48 horas no verificado) y documentos que deben entregarse.
19. Límite total de descuentos sobre la remuneración, prelación entre ellos y reglas de las retenciones judiciales.
20. Descuento de activos no devueltos o de saldos de préstamo en la liquidación: cuándo es lícito.

**Regímenes**

21. MYPE: estado del reglamento de la Ley 32353 y sus efectos; régimen de quien es recontratado antes de un año; aporte al SIS; obligatoriedad pensionaria en microempresa.
22. Construcción civil: bonificación nocturna (20 % o 25 %), altitud, alta especialización por especialidad, base de CONAFOVICER, fondo de capacitación, SENCICO, oportunidad de pago de la compensación vacacional y por tiempo de servicios, afectaciones de la bonificación unificada y de la asignación escolar, jubilación anticipada.
23. Agrario: año en que el tramo menor pasa al 9 % de EsSalud, base del aporte, opción de pago semestral, efectos de la Ley 32434 y su reglamento, tratamiento de la bonificación especial en quinta.
24. Modalidades formativas: subvención mínima, descanso, seguro, jornada, plazos máximos, límites sobre la planilla, media subvención adicional.
25. Reforma previsional (Ley 32123): efectos sobre afiliación y aportes.

**Contratos y relación laboral**

26. Plazos máximos de las cinco modalidades no investigadas y tope acumulado; cómputo en caso de reingreso; efectos de la desnaturalización.
27. Periodo de prueba por tipo de puesto y su ampliación.
28. Requisitos del contrato a tiempo parcial y sus beneficios.
29. Duración de licencias de ley (maternidad, paternidad, fallecimiento, otras) y días de descanso médico a cargo del empleador antes del subsidio.
30. Plazos del procedimiento disciplinario (descargos) y protecciones especiales (dirigentes sindicales, gestantes).
31. Licencias sindicales: cupo legal y cuota sindical.

**Declaraciones y documentos**

32. T-Registro: plazo de baja (día hábil o calendario); plazos vigentes de derechohabientes; Anexo 2 vigente (códigos de las tablas 16, 20, 21, 25, 27 y 35 y actualización de las tablas 9 y 11); Anexo 3 vigente (tipos, longitudes, obligatoriedad, extensiones y prefijo de los archivos de carga masiva).
33. PLAME: longitudes y obligatoriedad de las estructuras 14, 15 y 18; barra final, codificación y fin de línea; estructuras de pensionistas y terceros; serie 0800 de la Tabla 22 (código de EsSalud); matriz de afectaciones de cada código; criterio para asignar semanas de pago al mes.
34. AFPnet: códigos de documento distintos de DNI, carné de extranjería y pasaporte; formato del ticket.
35. Conservación de boletas y documentos de pago (cinco años citado de memoria); requisitos de la entrega digital y de la firma del empleador y del trabajador (DL 1310, Informe 0135-2021-MTPE).
36. Retención de cuarta categoría para locadores: porcentaje, monto mínimo y suspensión.
37. Archivos dorados: un archivo de cada estructura aceptado por la herramienta oficial (PDT PLAME, T-Registro, AFPnet).

**Cumplimiento**

38. Comité de SST: texto oficial del DS 005-2012-TR y de la RM 245-2021-TR (todos los plazos provienen de fuentes secundarias); sentido del aviso de 60 días; capacitaciones mínimas; funciones; norma de los registros simplificados para MYPE (RM 082 o RM 085 de 2013); plazos de notificación de accidentes; autoevaluación de SST en teletrabajo.
39. Hostigamiento sexual: plazos internos de descargos y alegatos; mandato y elección del comité de intervención; cómputo del umbral con varios centros de trabajo; modificaciones del DS 021-2021-MIMP sobre el procedimiento.
40. Cuota de discapacidad: umbral, porcentaje, método del promedio anual y justificaciones admitidas.
41. Lactario: umbral y rango de edad fértil.
42. Datos personales: plazos de respuesta por derecho, obligatoriedad del oficial de datos, requisitos de la evaluación de impacto y de la transferencia internacional.
43. Vida Ley: base, coberturas, topes y registro de la póliza; SCTR: actividades de riesgo y bases.
44. Alcance del personal no sujeto a control de asistencia.
45. SUNAFIL: redacción literal de la regla de efectos de la casilla; plazos del procedimiento sancionador y de las actuaciones inspectivas; Ley 31736.
46. Validez de la firma electrónica propia (OTP, huella, sello de tiempo) frente a la firma digital con certificado para cada tipo de documento laboral, incluidas las actas del comité.
47. Retención de expedientes: actas y elecciones del comité, expediente de hostigamiento, notificaciones de SUNAFIL.

Entregables que se piden al especialista: valores y normas de cada parámetro con su vigencia; revisión de la matriz de afectaciones; **casos de referencia de cálculo** (boletas, gratificación, CTS, quinta, utilidades y liquidación resueltos a mano; los cuatro casos de quinta del documento 18 §2.9 sirven de punto de partida) y **archivos dorados** para las pruebas.

---

## 22. Cómo se diseña un segundo paquete de país sin tocar el núcleo

**Lo que el núcleo ya resuelve de forma neutral:** personas y relaciones laborales con vigencia; estructura; motor de nómina (conceptos, fórmulas, bases, acumuladores, prorrateo, retroactivos); parámetros con vigencia por país y régimen; periodos y su ciclo; boletas; archivos con validación y estados; provisiones y asientos; políticas de tiempo; contratos con tipos y topes parametrizados; obligaciones de cumplimiento como datos; plantillas de documentos; retención y privacidad.

**Puntos de extensión del paquete** (M-PAI):

| Punto de extensión | Qué aporta cada país |
|---|---|
| Regímenes | Lista de regímenes y a qué conceptos y parámetros afecta cada uno |
| Parámetros | Catálogo con vigencia y fuente |
| Conceptos y bases | Plantilla de conceptos, bases de cálculo propias y matriz de afectaciones |
| Funciones de cálculo | Funciones con nombre que las fórmulas pueden invocar (impuesto progresivo, proyección anual, promedio de variables), registradas por el paquete; el motor no las conoce por adelantado |
| Tipos de proceso | Periodicidades y procesos especiales (prima, aguinaldo, finiquito) |
| Catálogos | Documentos de identidad, entidades previsionales y de salud, bancos, tipos de contrato, causales de cese, feriados |
| Generadores de archivos | Un generador y un validador por declaración, detrás de la interfaz común de M-DEC |
| Obligaciones | Reglas de cumplimiento con su condición de aplicación |
| Registros de SST | Formatos y plazos de conservación |
| Documentos | Plantillas legales en el idioma del país |
| Validaciones | Formato de identificadores fiscales, cuentas y direcciones |
| Casos de referencia | Pruebas doradas del país |

**Qué cambiaría, a alto nivel, en los tres países siguientes** (enunciado orientativo, no investigado: cada paquete requiere su propio estudio normativo y su especialista):

- **Colombia:** seguridad social por subsistemas con aportes compartidos y planilla integrada de aportes, prestaciones sociales propias (prima de servicios, cesantías e intereses, dotación), auxilio de transporte, retención en la fuente, nómina electrónica ante la autoridad tributaria, contribuciones parafiscales.
- **Chile:** cotizaciones a administradoras de pensiones, salud pública o privada y seguro de cesantía, gratificación legal, impuesto único de segunda categoría, valores indexados (UF, UTM) que encajan como parámetros con vigencia diaria o mensual, libro de remuneraciones electrónico, finiquito.
- **México:** cuotas de seguridad social y vivienda, impuesto sobre la renta con subsidio, aguinaldo, prima vacacional, reparto de utilidades con reglas propias, impuesto estatal sobre nóminas, recibo de nómina timbrado como comprobante fiscal (integración por proveedor certificado), unidades de referencia como parámetros.

**Pasos para construirlo**

1. Estudio normativo del país con su lista de puntos a validar (equivalente a la sección 21).
2. Definir regímenes, parámetros y bases; cargar parámetros con vigencia y fuente.
3. Escribir la plantilla de conceptos con fórmulas y afectaciones; registrar las funciones de cálculo que falten.
4. Implementar generadores y validadores de archivos detrás de la interfaz de M-DEC.
5. Cargar catálogos, feriados, tipos de contrato, políticas por defecto y plantillas de documentos.
6. Definir obligaciones de cumplimiento y registros de SST.
7. Escribir los casos de referencia y pasar la regresión.
8. Publicar el paquete (versión) y activarlo por empresa.

**Criterios de que el diseño se respetó**

- El núcleo no contiene ninguna condición por país ni por régimen (RN-PAI-01): una búsqueda de códigos de país en el código del núcleo no devuelve resultados.
- Activar el paquete nuevo no altera cálculos, catálogos ni periodos cerrados de empresas de otros países (RN-PAI-02, RN-PAI-03).
- Una misma organización opera empresas de dos países con una sola ficha de persona y planillas independientes.
- Si para el país nuevo hace falta un punto de extensión que no existe, se añade al **contrato del paquete** (beneficia a todos), no como excepción para ese país.
- Sin paquete, la empresa de ese país puede usar Core HR y los módulos no legales; la nómina queda no disponible con un mensaje claro (RN-PAI-06).
