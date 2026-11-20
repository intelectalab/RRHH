# BSO-CTS-001 · cálculo a mano

Depósito de mayo de 2026: semestre del 1 de noviembre de 2025 al 30 de abril de 2026 (6 meses, 0 días).
La última gratificación (diciembre de 2025) es 3000, fijada como dato del caso; en un proceso real sale de la corrida
de gratificación cerrada o del acumulado traído (PER-12).

- Remuneración computable = 3000 + 3000 × 0.166667 (sexto de gratificación, parámetro) = 3500.001
- Proporción del régimen = cts_dias_por_anio 30 ÷ dias_base_mes 30 = 1
- cts = 3500.001 × 1 × (6 ÷ 12 + 0 ÷ 360) = 1750.0005 → 1750.00

Oráculo: CtsProvisionStep (provisión mensual = (base + gratificación × fracción) ÷ 12 × proporción; seis meses).
En el proceso de CTS el neto es el monto a depositar en la entidad del trabajador (no se paga en la cuenta de haberes).
