# Calculadora de planilla · implementación de referencia (Laravel 12)

Responde al «Documento de requerimientos: módulo de planillas y regímenes laborales (Perú)», del 05/11/2026. Es código que funciona y tiene pruebas, pero es una **referencia**. El motor completo de nómina se construye con sus historias (épicas NOM, PAR, CPT, PER y BSO), y esta carpeta muestra cómo se arma su núcleo sin escribir tasas en el código.

- **Qué hace:**
  - calcula el mes de un colaborador: sueldo bruto, asignación familiar, aporte a pensión, neto, aporte de salud del empleador y provisiones de gratificación, bonificación extraordinaria y CTS;
  - prorratea el sueldo por ingreso, cese o cambio de sueldo dentro del mes (RN-NOM-05);
  - proyecta la retención de renta de quinta categoría, con pagos extraordinarios (RN-NOM-13 a RN-NOM-16);
  - calcula la liquidación de beneficios sociales al cese: gratificación trunca con su bonificación, CTS trunca, vacaciones truncas y vacaciones pendientes, con sus aportes;
  - suma a la liquidación la indemnización por despido arbitrario (plazo indeterminado, plazo fijo, periodo de prueba y tope por régimen) y la indemnización vacacional;
  - calcula la quinta del cese sobre la liquidación, sin proyectar meses, y la regularización de diciembre con el exceso a devolver;
  - calcula el mes de los regímenes agrario (Ley 31110, con beneficios prorrateados y BETA) y de construcción civil (jornal por categoría, BUC, movilidad y provisiones propias).
- **Qué no hace:** no domiciliados, asignación escolar y bonificaciones especiales de construcción civil (altura, agua, nocturna), utilidades, ni el depósito de la devolución de quinta ante SUNAT.
- **Pruebas:** 46 pruebas en verde, 40 unitarias y 6 sobre PostgreSQL con el catálogo del kit cargado.

## 1. Cómo probarla

```bash
composer create-project laravel/laravel:^12.0 calculadora && cd calculadora
composer require brick/math                     # decimales exactos, sin flotantes
cp -r <kit>/referencia/calculadora-planilla/{app,config,database,tests} .
ln -s <kit> kit                                 # el seeder y las pruebas leen kit/database/catalogos/
# registrar App\Providers\PayrollServiceProvider en bootstrap/providers.php
# en phpunit.xml: DB_CONNECTION=pgsql y los datos de una base vacía
php artisan test
```

Uso dentro de la aplicación:

```php
$result = app(PayrollService::class)->calculate($employee, PayrollContext::forMonth(2026, 11));
$result->toArray();   // gross_salary, family_allowance, pension_deduction, net_salary, essalud_contribution, provisions, worked_days, fifth_category…

// Mes con cambio de sueldo y quinta proyectada con el historial del año
$context = PayrollContext::forMonth(2026, 11,
    salaryChanges: [new SalaryChange(new DateTimeImmutable('2026-11-21'), BigDecimal::of('3600'))],
    fifthCategoryHistory: new FifthCategoryHistory($cobradoEnElAnio, $retencionesPorMes, $gratificacionesPorCobrar),
);

// Liquidación al cese (usa hire_date y termination_date de la ficha)
$liquidacion = app(SettlementService::class)->calculate($employee, new SettlementContext(pendingVacationDays: 30));

// Con despido arbitrario, vacaciones vencidas y quinta del cese
$liquidacion = app(SettlementService::class)->calculate($employee, new SettlementContext(
    dismissal: new Dismissal(Dismissal::FIXED_TERM, new DateTimeImmutable('2026-09-30')),
    expiredVacationDays: 30,
    fifthCategoryHistory: $historial, lastMonthFifthIncome: $ingresoDelMes, lastMonthWithholding: $retenidoEnElMes,
));

// Construcción civil: los jornales del mes llegan en el contexto, desde el tareo
$result = app(PayrollService::class)->calculate($operario, PayrollContext::forMonth(2026, 11, constructionPaidDays: 26, constructionWorkedDays: 22));
```

## 2. Arquitectura

```
PayrollService::calculate(Employee, ?PayrollContext): PayrollResult
  └─ recorre los pasos de config/payroll.php sobre un CalculationState
       BaseSalary → FamilyAllowance → AdditionalEarnings → Pension → FifthCategoryTax
       → OtherDeductions → HealthContribution → GratificationProvision → CtsProvision
  └─ BaseSalary prorratea por tramos (SalaryChange) con dias_base_mes; FifthCategoryTax usa FifthCategoryCalculator
  └─ cada paso pide sus tasas a CalculationState::param(), que las pide a ParameterRepository
       ├─ EloquentParameterRepository: legal_parameters y pension_fund_rates, con vigencia
       └─ InMemoryParameterRepository: pruebas y simulaciones, con el mismo formato del catálogo del kit

SettlementService::calculate(Employee, ?SettlementContext): SettlementResult
  └─ arma las líneas truncas sobre un CalculationState y reutiliza PensionStep y HealthContributionStep
  └─ suma indemnizaciones (Dismissal en el contexto) y la quinta del cese con FifthCategoryCalculator en modo final

config('payroll.steps_by_regime'): pasos propios por régimen, en lugar de la lista general
  pe_agrario      → … AgrarianBonus → AgrarianBenefits → AgrarianHealthContribution
  pe_construccion → ConstructionWage → … → ConstructionBenefits
FifthCategoryCalculator: escala (quinta_escala), divisores y meses de corte (quinta_divisores, quinta_cortes_retenciones)
ServiceTime: días, meses y días, meses calendario completos y aniversarios (solo aritmética de calendario)
```

- **Cero valores legales en el código.** RMV, UIT, porcentajes, factores por régimen, días de CTS y de vacaciones, días de la base mensual, la escala de quinta con sus divisores y los meses de depósito de CTS salen de `legal_parameters` o de `pension_fund_rates`, vigentes en la fecha del periodo. Los valores estructurados van en `value_json`. En el código solo quedan los 6 meses de un semestre, los 12 meses del año y los semestres de enero a junio y de julio a diciembre, que son datos de calendario.
- **Extensible sin romper la firma.** Un bono o un descuento nuevo es una clase que implementa `PayrollStep` y se agrega en `config/payroll.php`. Los ingresos y descuentos del mes llegan en `PayrollContext` como líneas (`Line`). Cada línea indica si es remunerativa, es decir, si entra en la base de pensión y salud, y si es computable, es decir, si entra en la base de gratificación y CTS.
- **Por régimen, sin condicionales.** Cada parámetro se busca primero con el código del régimen del colaborador y, si no existe, con el valor general del país. Por ejemplo, `gratificacion_factor` vale 0,5 en `pe_pequena` y 1 en general. Así se cumple RN-NOM-12.
- **Un error no detiene la planilla.** Si falta un parámetro, o el colaborador no tiene días en el periodo, el resultado queda con `status = error` y un mensaje claro (RN-NOM-10 y RN-PAR-04). Si se usa un parámetro sin confirmar, el resultado lleva un aviso (RN-PAR-03).
- **Trazabilidad.** `parameters_used` guarda el valor, la vigencia y la norma de cada parámetro usado (RN-NOM-04).
- **Decimales exactos.** Se usa `brick/math` y cada línea se redondea a 2 decimales con redondeo «mitad hacia arriba» (RN-NOM-08).

## 3. Qué se tomó del requerimiento y qué se ajustó al kit

| Requerimiento | En la referencia | Por qué |
|---|---|---|
| Tabla `laboral_regimes` con columnas de tasas y factores | Tabla `labor_regimes` para el catálogo, y valores por régimen como filas de `legal_parameters` con su `labor_regime_code` | Un régimen nuevo, como el agrario o construcción civil, trae reglas que no caben en columnas fijas. Así cada valor tiene su propia vigencia (`docs/18` §4.7). |
| Tabla `payroll_parameters` llave-valor (`key`, `value`) | Tabla `legal_parameters` con `code`, `value_numeric`, `valid_from`, `valid_to`, fuente y marca de verificado | Sin fechas no se puede recalcular un mes pasado ni publicar un cambio de norma sin pisar el valor anterior (RN-NOM-01 y RN-PAR-02). La base rechaza dos vigencias que se cruzan. |
| Identificadores `BigInteger` | UUID v7 | ADR 0001. |
| `employees` con FK al régimen | `employees` con FK por `labor_regime_code`, `tenant_id` y RLS | ADR 0005. En el producto, el régimen y la afiliación están en `employment_labor_regimes` y `employment_pension_affiliations`, con vigencia. |
| Montos `decimal(10,2)` y tasas `decimal(5,4)` como fracción | Montos `numeric(14,2)` y tasas en porcentaje (`13` = 13 %) | Son las convenciones del esquema del kit (`docs/03`). |
| `has_children` y asignación familiar por régimen | `has_family_allowance` en la ficha del colaborador | Así está en el esquema (`employment_labor_regimes`). |
| `calculate(Employee $employee)` | `calculate(Employee $employee, ?PayrollContext $context = null)` | El segundo argumento es opcional y lleva el periodo, los ingresos y los descuentos del mes, así que la firma pedida sigue funcionando. |

## 4. Correcciones a los valores y fórmulas del requerimiento

Los valores del documento eran referenciales. Estos son los que usa el catálogo del kit. Lo marcado [validar] lo confirma el especialista laboral (`docs/19`).

1. **RMV:** S/ 1 130 desde el 1 de enero de 2025 (DS 006-2024-TR). El documento decía S/ 1 025.
2. **UIT 2026:** S/ 5 500 (DS 301-2025-EF). El documento decía S/ 5 150.
3. **Vacaciones:** micro y pequeña empresa tienen 15 días al año (DS 013-2013-PRODUCE), no 0. Para el régimen general, 30 días [validar].
4. **Salud:**
   - La pequeña empresa aporta a EsSalud la tasa general (9 %).
   - La microempresa afilia al SIS con un monto fijo por trabajador (`mype_sis_aporte`, hoy sin valor verificado), no con una tasa del 4 %.
   - Con EPS se aplica el crédito `eps_credito_pct` [validar].
5. **Fórmula de la CTS:** el documento divide la gratificación dos veces. La remuneración computable de CTS suma **un sexto de la gratificación del semestre**, no un sexto de la provisión mensual. Con un sueldo de S/ 2 000 en el régimen general:
   - fórmula del documento: (2 000 + 333,33 ÷ 6) ÷ 12 = **S/ 171,30**;
   - fórmula correcta: (2 000 + 2 000 ÷ 6) ÷ 12 = **S/ 194,44**.
6. **Bonificación extraordinaria:** el documento no la incluía. La Ley 30334 suma al costo de la gratificación un 9 % con EsSalud, o la tasa para EPS [validar].
7. **AFP:**
   - La prima de seguro se calcula solo hasta la remuneración máxima asegurable.
   - La comisión es sobre flujo o mixta, según el afiliado.
   - Las tasas cambian por AFP y por mes, así que viven en `pension_fund_rates`.
8. **Bases distintas:**
   - Pensión y salud se calculan sobre los ingresos remunerativos. Por ejemplo, la movilidad supeditada a la asistencia no entra. La quinta usa esa misma base más la gratificación y su bonificación, que no llevan aportes pero sí pagan impuesto.
   - La gratificación y la CTS se calculan sobre la remuneración computable, que son los pagos regulares; una hora extra ocasional no entra.
   - El «Total Ingresos» del documento mezclaba las dos bases.
9. **Quinta categoría:** no es un porcentaje del mes. Es una proyección anual con deducción de 7 UIT, escala por tramos y divisores (RN-NOM-13). La referencia la calcula con `FifthCategoryCalculator` (§5).
10. **Por validar:** si hay una base mínima de aporte a EsSalud igual a la RMV para la jornada completa, y si la asignación familiar corresponde en micro y pequeña empresa. Hoy la referencia no aplica una base mínima y no paga asignación familiar en micro ni pequeña empresa (`asignacion_familiar_aplica` = 0), con fuente secundaria.

## 5. Prorrateo, quinta y liquidación

**Prorrateo (RN-NOM-05).** Cada tramo paga su sueldo ÷ `dias_base_mes` × sus días calendario. El mes completo cuenta 30 días aunque tenga 28 o 31; si hubo cambio de sueldo, el último tramo absorbe la diferencia. El sueldo de la ficha rige hasta el primer `SalaryChange` del mes. La asignación familiar se paga completa [validar].

**Quinta categoría.** Con `FifthCategoryHistory` en el contexto:

1. **Proyección:** ingreso del mes + remuneración regular del mes completo × meses que faltan + gratificaciones por cobrar + lo cobrado antes en el año, también de otros empleadores. En el mes de cese no quedan meses por proyectar.
2. **Impuesto anual:** escala acumulativa sobre la proyección − 7 UIT.
3. **Retención:** (impuesto − retenciones hasta el mes de corte) ÷ divisor del mes.
4. **Pago extraordinario:** impuesto con el pago − impuesto sin él, retenido completo en el mes.

Una línea puede pagar quinta sin ser remunerativa (`fifthCategory: true`, como la gratificación) o marcarse como extraordinaria (`extraordinary: true`, como un bono). Si el contexto trae la retención ya calculada, se usa tal cual.

**Liquidación.** La remuneración computable es el sueldo + la asignación familiar + el promedio de variables regulares.

| Concepto | Fórmula | Tramo |
|---|---|---|
| Gratificación trunca | RC × factor ÷ 6 × meses calendario completos, más la bonificación extraordinaria | Desde el 1 de enero o el 1 de julio, o desde el ingreso |
| CTS trunca | (RC + gratificación del periodo × 1/6) × (meses ÷ 12 + días ÷ 360) × proporción del régimen | Desde el 1 del último mes de depósito (`cts_meses_deposito`) |
| Vacaciones truncas | RC × (días de vacaciones ÷ 30) × (meses ÷ 12 + días ÷ 360) | Desde el último aniversario de ingreso |
| Vacaciones pendientes | RC ÷ 30 × días ganados y no gozados | Llega en el contexto |

Las vacaciones llevan aporte a pensión y a EsSalud; la gratificación y la CTS, no. En el régimen general, `vacaciones_dias` aún no tiene valor verificado, así que la liquidación queda con error hasta que el especialista lo confirme. La gratificación cobrada en el periodo de CTS llega en el contexto, porque sale de las planillas cerradas.

**Indemnizaciones.**

| Concepto | Fórmula | Parámetros |
|---|---|---|
| Despido arbitrario, plazo indeterminado | RC ÷ 30 × días por año × (meses ÷ 12 + días ÷ 360), con tope | `indemnizacion_dias_por_anio` (45 general, 20 pequeña, 10 micro) e `indemnizacion_tope_dias` |
| Despido en plazo fijo | RC ÷ 30 × días por mes × meses que faltaban, con el mismo tope | `indemnizacion_plazo_fijo_dias_por_mes` |
| Despido en periodo de prueba | No se paga; queda un aviso | — |
| Indemnización vacacional | RC ÷ 30 × días vencidos sin gozar, aparte de la remuneración vacacional | `indemnizacion_vacacional_aplica` |

Las indemnizaciones no llevan aportes ni quinta. Un plazo fijo sin fecha de fin, o ya vencida, deja la liquidación con error.

**Quinta del cese y regularización.** En el cese, `FifthCategoryCalculator` corre en modo final: no proyecta meses ni gratificaciones por cobrar, suma la liquidación afecta (gratificación trunca, bonificación y vacaciones), y retiene el saldo completo, sin divisor, en la línea `renta_quinta_cese`. En diciembre, o en el cese, si lo retenido supera el impuesto anual, el resultado trae `refund`. La devolución se paga como línea `devolucion_quinta` solo si `quinta_devuelve_exceso` tiene valor; mientras no lo tenga, queda un aviso con el monto.

**Agrario (Ley 31110).** Si la ficha marca `agrarian_prorated_benefits`, la gratificación (`agr_gratificacion_pct`) y la CTS (`agr_cts_pct`) se pagan cada mes sobre la remuneración básica y no se provisionan. La BETA (`agr_beta_pct_rmv` de la RMV) no es remunerativa. EsSalud usa `agr_essalud_pct` según el tramo de la empresa (`agrarian_tier`); sin ese valor, el cálculo queda con error.

**Construcción civil.** El jornal sale de `cc_jornal_basico` por la categoría de la ficha (`construction_category`), por los jornales pagados. La BUC (`cc_buc_pct`) es remunerativa y no computable, y la movilidad (`cc_movilidad_dia`), por día laborado, no lleva aportes. Las provisiones son propias: CTS (`cc_compensacion_pct`), vacaciones (`cc_vacaciones_pct`) y gratificación de `cc_gratificacion_jornales` jornales en sétimos o quintos (`cc_gratificacion_fracciones`), más su bonificación extraordinaria.

## 6. Casos de las pruebas

Se calculan con el catálogo del kit. La AFP usa tasas de prueba.

| Caso | Resultado |
|---|---|
| General, S/ 2 000, con asignación familiar, ONP | Bruto 2 113,00 · ONP 274,69 · neto 1 838,31 · EsSalud 190,17 · gratificación 352,17 + bonificación 31,70 · CTS 205,43 |
| Pequeña empresa, S/ 2 000, AFP (10 % + 1,50 % + 1,37 %) | AFP 257,40 · EsSalud 180,00 · gratificación 166,67 + bonificación 15,00 · CTS 90,28 |
| General, S/ 15 000, AFP con remuneración máxima asegurable de prueba de S/ 12 000 | Prima 164,40 (sobre 12 000) |
| Microempresa con SIS sin valor | Estado `error` con mensaje sobre `mype_sis_aporte`, en lugar de usar un número inventado |
| Cambio hipotético de RMV desde enero de 2027 | Noviembre de 2026 usa 1 130 y enero de 2027 el valor nuevo, sin tocar el código |
| Horas extras, movilidad, préstamo y quinta | La movilidad no entra en la base de la ONP y las horas extras no entran en la gratificación |
| Ingreso el 16 de noviembre, S/ 3 000 | 15 días, S/ 1 500,00 |
| Cambio de S/ 3 000 a S/ 3 600 el 21 de octubre | 3 000 ÷ 30 × 20 + 3 600 ÷ 30 × 10 = S/ 3 200,00 |
| Quinta de enero, S/ 5 000 | Proyección 70 900, impuesto 2 886, retención 240,50 |
| Quinta de noviembre, S/ 10 000, con 5 600 retenidos hasta agosto | Impuesto 12 812, retención (12 812 − 5 600) ÷ 4 = 1 803,00; con un bono extraordinario de 5 000, 700,00 más |
| Liquidación general: ingreso 10/03/2024, cese 20/11/2026, S/ 3 000 con asignación familiar, 30 días pendientes | Gratificación trunca 2 075,33 + bonificación 186,78 · CTS trunca 172,94 · vacaciones truncas 2 170,45 · pendientes 3 113,00 · ONP 686,85 · EsSalud 475,51 |
| Liquidación pequeña empresa: ingreso 01/08/2025, cese 31/03/2026, S/ 2 000 | Gratificación trunca 500,00 · CTS trunca 445,60 con 833,33 de gratificación en el periodo · vacaciones truncas 666,67 |
| Liquidación microempresa | Solo vacaciones truncas, sin aporte al SIS |
| Despido, ingreso 10/03/2024, cese 20/11/2026, S/ 3 000 con asignación familiar | 3 113 ÷ 30 × 45 × (32 ÷ 12 + 11 ÷ 360) = 12 594,68 |
| Despido con 16 años de servicio | Tope: 3 113 × 12 = 37 356,00 |
| Despido en plazo fijo, S/ 2 000, faltaban 3 meses | 2 000 × 1,5 × 3 = 9 000,00 |
| Despido en pequeña empresa, 8 meses, S/ 2 000 | 2 000 ÷ 30 × 20 × 8 ÷ 12 = 888,89 |
| Despido en periodo de prueba | Sin indemnización, con aviso |
| Plazo fijo sin fecha de fin | Estado `error` |
| 30 días vencidos sin gozar, S/ 3 113 de RC | Indemnización vacacional 3 113,00, aparte de las pendientes 3 113,00 |
| Quinta del cese en junio, S/ 10 000, 3 300 retenidos | Ingreso anual 75 900, impuesto 3 586, retención en el cese 286,00 |
| Cese con 4 000 retenidos | Exceso de 414,00: aviso sin `quinta_devuelve_exceso`; con el parámetro, línea de devolución |
| Diciembre con 3 300 retenidos e impuesto anual de 1 720 | Devolución 1 580,00 |
| Noviembre con retención de más | Sin devolución: se compensa en diciembre |
| Agrario sin tasa de EsSalud | Estado `error` con mensaje sobre `agr_essalud_pct` |
| Agrario, S/ 1 500, beneficios prorrateados, tramo A de prueba (9 %) | BETA 339,00 · gratificación 249,90 · CTS 145,80 · ONP 195,00 · EsSalud 135,00 · sin provisiones |
| Construcción civil, operario, 26 jornales pagados y 22 laborados | Jornal 2 321,80 · BUC 742,98 · movilidad 189,20 · ONP 398,42 · EsSalud 275,83 · CTS 348,27 · vacaciones 232,18 · gratificación 714,40 |
| Construcción civil sin jornales | Estado `error` |

## 7. Cambios en el catálogo del kit

Para que el régimen general no dependa de valores por defecto escritos en el código, se agregaron parámetros en `tools/esquema/catalogos.py`:

- `gratificacion_aplica` = 1, `gratificacion_factor` = 1, `cts_aplica` = 1 y `cts_dias_por_anio` = 30, generales;
- `asignacion_familiar_aplica`: 1 general y 0 en micro y pequeña empresa, con fuente secundaria;
- `dias_base_mes` = 30, para prorratear y calcular truncos.

Los regímenes reemplazan los generales con su propio valor. Además, `vacaciones_dias_regimen_general` pasó a llamarse `vacaciones_dias` con régimen `pe_general`, para que todos los regímenes usen el mismo código. Sigue sin valor. El catálogo tiene 166 parámetros: `docs/19` lista 40 sin valor verificado y 102 con fecha de inicio por confirmar.

En la versión 1.13 se sumaron los parámetros generales de indemnización (`indemnizacion_dias_por_anio` = 45, `indemnizacion_tope_dias` = 360, `indemnizacion_plazo_fijo_dias_por_mes` = 45 e `indemnizacion_vacacional_aplica` = 1) y `quinta_devuelve_exceso`, sin valor hasta que el especialista confirme el procedimiento. La ficha del colaborador de la referencia suma `construction_category`, `agrarian_prorated_benefits` y `agrarian_tier`; en el producto viven en `employment_labor_regimes`.
