---
description: Revisa un cambio contra las reglas no negociables de Ayni HR
argument-hint: [rama, commit o rango; por defecto los cambios sin confirmar]
---

Revisa el cambio **$ARGUMENTS** (si está vacío: `git diff` + `git diff --staged`). No corrijas: informa.

Recorre esta lista y responde cada punto con «cumple», «no aplica» o el hallazgo con archivo y línea:

1. Aislamiento: tablas nuevas con `tenant_id`, RLS forzado y FK compuestas; ninguna consulta sin contexto de organización; trabajos en cola que restauran la organización.
2. Vigencias: ningún `UPDATE` sobre historia; cambios con `valid_from`; sin borrado físico de datos laborales.
3. Estados: transiciones solo por acción y por la máquina de estados; ningún `status` editable por `PATCH`.
4. Parámetros legales: ningún número legal (tasa, tope, plazo, UIT, RMV) en código.
5. Auditoría con antes y después en cada acción sensible; registro de acceso en lecturas sensibles.
6. Permisos: Policy en cada endpoint; alcance aplicado en la consulta; campos sensibles filtrados en el Resource.
7. Integraciones: puerto + adaptador falso; efecto externo por outbox; sin SDK en `Domain/` ni `Application/`.
8. Idempotencia en `POST`, consumidores y trabajos.
9. Frontend: sin cálculo de negocio ni regla legal; sin textos literales; sin datos de negocio en `localStorage`.
10. Dinero decimal con moneda; fechas en UTC y zona de la empresa.
11. IA: sin acciones de alto impacto; respeta permisos; bitácora.
12. Rendimiento: sin N+1; listados paginados en servidor; índices para filtros nuevos; procesos masivos por lotes en cola.
13. Pruebas: feliz, permiso, módulo apagado, aislamiento, validación, transición inválida, caso dorado si toca planilla.
14. Contrato: `openapi.yaml` y `packages/contracts` actualizados; prueba de contrato en verde.
15. Seguridad: sin secretos, sin datos personales en logs, archivos con URL firmada, validación de tipo y tamaño.

Termina con: hallazgos bloqueantes, hallazgos menores y pruebas que faltan. Ejecuta `make test lint` e incluye el resultado.
