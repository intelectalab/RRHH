# Fórmulas de planilla

Lenguaje propio del motor de nómina (ADR 0015, historia NOM-3). Es pequeño a propósito: no ejecuta código, no lee
la base de datos ni el reloj y solo ve el contexto de cálculo de la relación laboral y el periodo. Antes de guardar
una fórmula, el editor de conceptos la revisa con `POST /payroll-formulas/validate`.

## Qué se puede escribir

| Elemento | Forma | Ejemplo |
|---|---|---|
| Número | decimal con punto | `30`, `12.5` |
| Texto | entre comillas dobles | `"pe_micro"` |
| Verdadero o falso | `verdadero`, `falso` | `si(tiene_asignacion_familiar, 1, 0)` |
| Operaciones | `+ - * /` y paréntesis | `(sueldo / dias_base) * dias_laborados` |
| Comparaciones | `= <> < <= > >=` | `dias_sin_goce > 0` |
| Lógica | `y`, `o`, `no` | `dias_laborados > 0 y no tiene_seguro_riesgo` |
| Dato del contexto | nombre sin prefijo | `sueldo_tramos` |
| Otro concepto | `concepto.CODIGO` | `concepto.BASICO` |
| Parámetro legal | `param.codigo` | `param.dias_base_mes` |
| Incidencia del periodo | `incidencia.CODIGO` (monto) · `cantidad.CODIGO` (cantidad) | `incidencia.BONO` |

## Funciones

| Función | Qué hace | Ejemplo |
|---|---|---|
| `si(condición, a, b)` | a si se cumple, b si no | `si(regimen = "pe_micro", 0, concepto.BASICO)` |
| `min(a, b, …)` · `max(a, b, …)` | menor o mayor | `min(concepto.BASE, param.tope_de_la_empresa)` |
| `redondear(x, decimales)` | redondeo al más cercano | `redondear(concepto.BASICO * 13 / 100, 2)` |
| `truncar(x, decimales)` | corta sin redondear | `truncar(sueldo / 3, 2)` |
| `abs(x)` | valor absoluto | `abs(incidencia.AJUSTE)` |
| `dias_entre(desde, hasta)` | días calendario, ambos incluidos | `dias_entre(fecha_ingreso, fecha_fin)` |

## Datos del contexto

`sueldo` (vigente al final del periodo), `sueldo_tramos` (suma de cada tramo de sueldo por sus días sobre la base
del régimen), `dias_base`, `dias_periodo`, `dias_relacion`, `dias_laborados`, `dias_remotos`, `dias_ausencia`,
`dias_ausencia_pagada`, `dias_sin_goce`, `dias_cargo_empleador`, `dias_subsidio`, `horas_ausencia`, `fecha_inicio`,
`fecha_fin`, `fecha_ingreso`, `fecha_cese` (vacía si no cesó), `regimen`, `sistema_salud`,
`tiene_asignacion_familiar` y `tiene_seguro_riesgo`. Los días y ausencias salen del resumen de ausencias del periodo
(AUS-6).

## Reglas

- Todos los números son decimales exactos; el resultado de cada concepto se redondea con su regla y los demás
  conceptos usan ese monto redondeado.
- Una fórmula no puede usarse a sí misma ni formar un ciclo con otras: el error indica los conceptos del ciclo.
- Un concepto que no aplica al régimen o al tipo de proceso vale cero para los demás.
- Ninguna tasa ni tope legal se escribe en la fórmula: se lee con `param.` (con vigencia y estado «por validar»).
- Cada resultado guarda su traza: la fórmula, los datos, los conceptos y los parámetros usados con su vigencia.
