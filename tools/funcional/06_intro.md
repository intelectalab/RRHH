# 06 · Reglas de negocio

Ayni HR · kit de desarrollo. Reglas numeradas `RN-<ÉPICA>-nn` con los códigos de épica de `SPEC.md`.

## Convenciones

- Cada regla es verificable: **condición → resultado**. Debe existir al menos una prueba automática por regla, nombrada con su código.
- Columna «¿Parámetro?»: **Sí** = el valor (tasa, tope, plazo, umbral, política) se lee de un parámetro con vigencia por fecha y por nivel (país/régimen en `M-PAR`, organización/empresa/sede/grupo en `M-CFG`); nunca es una constante en código. **No** = comportamiento fijo del producto.
- «Valor de referencia 2026, parámetro con vigencia» = dato tomado de `docs/research.md`, cargado como valor inicial del paquete Perú. «A confirmar con la norma vigente» = no verificado; figura en la lista de puntos a validar con un especialista laboral (`14-localizacion-peru.md`, sección final) y bloquea el cierre de planilla que lo use hasta confirmarse (RN-PAR-03).
- Las reglas se validan siempre en el servidor (regla transversal 8). La interfaz puede anticiparlas, no sustituirlas.
- Las reglas transversales de `SPEC.md` (aislamiento, vigencias, acciones de estado, auditoría, datos sensibles, adaptadores, IA asistida) se concretan en las épicas PLT, TEN, SEG, AUD, HIS, INT e IAR y aplican a todos los módulos aunque no se repitan en cada uno.
- Correcciones C-xx y ampliaciones E-xx: la correspondencia regla ↔ corrección/ampliación está en `00-trazabilidad.md`.
- Reglas de la serie `RN-PE-…` (quinta categoría `RN-PE-QTA`, micro y pequeña empresa `RN-PE-MYP`, construcción civil `RN-PE-CC`, agrario `RN-PE-AGR`): son reglas del paquete Perú y están definidas, con su estado de verificación, en `18-localizacion-peru-detalle-tecnico.md` (secciones 2.6, 2.7 y 4.2 a 4.4). Las reglas de este documento que las resumen remiten a ellas.
