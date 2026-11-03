# ADR 0010 · D-13: Datos al desactivar un módulo o dar de baja una organización

- **Fecha:** 2026-11-02
- **Estado:** Aceptada (provisional, a confirmar por el negocio)
- **Decisión:** D-13

## Contexto

Cuando una organización desactiva un módulo contratado o finaliza su relación contractual con Ayni HR, se deben establecer reglas claras de preservación de información histórica, cumplimiento de obligaciones legales de conservación documental y plazos para la purga o eliminación definitiva de los datos.

## Opción elegida

**Módulo en solo lectura con exportación disponible; baja de organización con exportación completa, periodo de gracia y eliminación certificada.**

- Al desactivar un módulo: la organización conserva acceso en solo lectura a los datos generados mientras estuvo activo, permitiendo la generación de reportes y exportaciones. Queda bloqueada la creación y modificación de registros.
- Al dar de baja una organización: se habilita la exportación integral de datos y archivos durante un periodo de gracia reglamentario (definido por el negocio y asesoría legal). Concluido dicho periodo, se ejecuta el procedimiento de eliminación segura y certificada en la base de datos y almacén de objetos (S3).

## Consecuencias

- **Positivas:**
  - Garantiza el derecho de las empresas a conservar sus antecedentes laborales y tributarios.
  - Mitiga el riesgo legal derivado de la retención indefinida de datos personales según la Ley 29733.
  - Claridad contractual y transparencia en el ciclo de vida del dato.
- **Negativas / Compromisos:**
  - Requiere implementar tareas programadas de retención, purga definitiva y generación de archivos consolidados de exportación en la Fase 0.

