
## {{n}}. Decisiones de modelado por confirmar

Se tomaron para poder entregar un esquema completo y comprobable. Conviene que el equipo las confirme antes de escribir las migraciones.

1. **Sueldos y montos de planilla sin cifrado por campo.** Van como `numeric` marcados sensibles (permiso por campo + RLS + registro de accesos) para poder calcular, provisionar y agregar en SQL. La alternativa (cifrarlos en la aplicación) impide la analítica y complica el motor.
2. **`ayni_platform` con `BYPASSRLS`.** Es lo más simple para la consola SaaS y los trabajos entre organizaciones. La alternativa es una política aparte por rol, más restrictiva pero más larga de mantener.
3. **Organización activa sin error.** Sin `app.tenant_id` las consultas devuelven cero filas en lugar de fallar. El filtro de aplicación sí debe lanzar excepción cuando falte el contexto.
4. **La aplicación no lee `tenants`.** Usa la vista `current_tenant` y la función `resolve_tenant_id()`. Si la API necesita más datos de la suscripción, se amplía la vista, no los privilegios.
5. **Tablas inalterables estrictas.** Ni la retención puede borrar auditoría, marcaciones o evidencias de firma: una depuración por antigüedad exigirá particionar por fecha y retirar particiones con el rol dueño. No se particionó todavía.
6. **Una posición, un ocupante a la vez.** Hay exclusión por `position_id` en `employment_assignments`. Si se quieren plazas compartidas, hay que retirarla o usar `positions.fte`.
7. **Unidades organizativas de la organización, no de la empresa.** `org_units.legal_entity_id` es opcional: un área puede tener personas de dos razones sociales. La pertenencia legal la da `employments`.
8. **Nombres de persona al modo peruano.** `first_names`, `paternal_surname`, `maternal_surname` en lugar de nombre y apellido únicos; la API puede exponer un nombre compuesto.
9. **Contratos con vigencia y estado.** La exclusión de solapamiento ignora borradores, reemplazados y cancelados, para poder preparar una renovación mientras el contrato actual sigue vigente.
10. **Onboarding, pre-onboarding y offboarding comparten tablas** (`onboarding_*` con `kind`). El cese en sí está en `terminations`.
11. **Sesiones presenciales del LMS como registro de capacitación de SST.** No hay tabla aparte de capacitaciones de SST; se usa `training_sessions` con `courses.compliance_tag`.
12. **Medidas correctivas genéricas.** `corrective_actions` apunta por `source_type` + `source_id` a incidentes, inspecciones, auditorías o monitoreos.
13. **Identidad en denuncias sin clave foránea.** `complaint_identities` guarda la identidad cifrada como texto; no se puede cruzar con `people` en SQL, a propósito.
14. **Respuestas de encuesta con segmento.** `survey_responses` guarda el segmento (empresa, sede, unidad, nivel) para el mapa de calor. Con unidades muy pequeñas el segmento puede reidentificar: la aplicación debe subir de nivel el segmento al guardar cuando el grupo no alcance el umbral.
15. **Permisos referenciados por código.** `role_permissions.permission_code` apunta a `permissions.code`. Renombrar un permiso exige migración. El catálogo y la matriz se generan desde `07-permisos-y-roles.md` (códigos en inglés, D-29).
16. **Alcances.** El alcance por defecto va en `role_permissions.scope`; la asignación (`user_roles`) puede restringirlo y `user_role_scopes` enumera empresas, sedes o unidades. Los valores son `organization`, `legal_entity`, `site`, `org_unit`, `team`, `own`, `assigned`. La marca «○» de la matriz (solo lo propio o asignado) se guarda como `own` en Empleado y Manager y como `assigned` en los demás roles: criterio por confirmar.
17. **Módulo de cada permiso.** `permissions.module_code` es el módulo comercial de la épica bajo la que el documento 07 lista el permiso, no el prefijo del código. La épica PAI no tiene módulo en `01-vision-y-fases.md` y se trata como parte de `base`.
18. **Estados que no se guardan.** «Por vencer» (contratos, documentos), los estados de una marcación y «Publicada/Vista/Con acuse/Anulada» de la boleta se derivan; ver la conciliación al final de `05-maquinas-de-estado.md`. Los `CHECK` admiten además algunos valores técnicos que las máquinas no nombran.
19. **Transiciones no validadas en base.** Los `CHECK` limitan los valores de estado, no las transiciones: la máquina de estados vive en el dominio.
20. **Parámetros y conceptos de Perú fuera del esquema.** No se insertan en `schema.sql`; los carga el paquete de país una vez verificados.
21. **Tasas de AFP.** Se guardan en `pension_fund_rates` (plataforma, con vigencia) y no en `legal_parameters`, porque varían por administradora.
22. **Seguridad adicional para denuncias.** El esquema las aísla por organización como al resto; el acceso «solo asignados» se aplica en la aplicación con `complaint_assignments`. Se podría reforzar con una segunda política RLS por usuario.
