# MOTOR-TRAMOS-001 · cálculo a mano

Marzo de 2026 tiene 31 días. El paquete Perú indica que un mes completo cuenta como la base (`dias_base_mes`,
fijada en 30 para el caso), así que el último tramo pierde el día 31.

| Tramo | Días | Sueldo | Parte |
|---|---|---|---|
| 1 al 15 | 15 | 3000.00 | 3000 × 15 ÷ 30 = 1500.00 |
| 16 al 31 | 16 → 15 | 3600.00 | 3600 × 15 ÷ 30 = 1800.00 |

- BASICO = sueldo_tramos = 3300.00
- BONO = incidencia del periodo = 250.00
- APORTE = redondear(3300 × 13 ÷ 100, 2) = 429.00 (aporte del trabajador, descuenta)
- CONTRIB = 3300 × 9 ÷ 100 = 297.00 (aporte del empleador)
- Ingresos 3550.00 · descuentos 429.00 · neto 3121.00 · aportes del empleador 297.00 · costo 3847.00
