# Archivos dorados de declaraciones (DEC-10)

Una estructura de archivo oficial (PLAME, T-Registro, AFPnet) solo produce archivos definitivos cuando está verificada contra la herramienta oficial. Cada estructura verificada del paquete de país (`packages/country-pe/data/estructuras_archivos_pe.json`, `verified: true`) lleva aquí dos archivos:

- `<filing_kind>_<structure_code>.json`: el caso, `{"rows": [ {<source>: <valor>, ...}, ... ]}` con las filas ya armadas.
- `<filing_kind>_<structure_code>.txt`: los bytes esperados (codificación, separador y fin de línea de la estructura).

`tests/Golden/DeclarationsGoldenTest.php` serializa cada caso y lo compara byte a byte. Hoy todas las estructuras del paquete siguen por validar (AR-11), así que no hay casos: al verificar una, se agrega su par de archivos en el mismo cambio.
