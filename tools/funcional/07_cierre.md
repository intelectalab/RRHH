## 6. Reglas sobre roles

| Código | Regla |
|---|---|
| RP-01 | Los 11 roles de sistema no se pueden borrar ni renombrar. Sí se pueden duplicar para crear uno personalizado. |
| RP-02 | El rol Administrador empresa no pierde los permisos `identity.*`; el resto de roles de sistema puede ajustarse por organización, quedando registrado el cambio respecto de la plantilla. |
| RP-03 | Siempre queda al menos un usuario activo con el rol Administrador empresa. Se rechaza cualquier cambio (bloquear, desactivar, cambiar rol, cesar) que deje la organización sin ninguno. |
| RP-04 | Un usuario no puede concederse permisos ni asignar un rol con permisos que él mismo no tiene, salvo el Administrador empresa. |
| RP-05 | Un rol personalizado nace sin permisos y con alcance «Propio» hasta que se configure. |
| RP-06 | No se puede borrar un rol con usuarios asignados; primero se reasignan. Los roles personalizados sin uso se archivan. |
| RP-07 | Todo cambio de rol, permiso, alcance o asignación queda en auditoría con antes y después. |
| RP-08 | Los permisos **(S)** nunca se conceden «por paquete»: al duplicar un rol se copian, pero al crear un rol desde cero deben marcarse uno a uno. |
| RP-09 | Las asignaciones de rol pueden tener fecha de fin (suplencias, auditorías externas); al vencer se retiran solas. |
| RP-10 | Los roles Administrador empresa, Payroll e Investigador de denuncias exigen MFA por defecto (parámetro por rol). |
| RP-11 | El rol Investigador de denuncias no se combina por defecto con Administrador ni RRHH en la misma persona sin dejar constancia; su asignación la hace el órgano que la organización designe. |
| RP-12 | El Auditor y el Contador solo tienen permisos de lectura y exportación; el sistema impide añadirles permisos de escritura sin duplicar el rol. |
| RP-13 | Un usuario externo (contador de un estudio, auditor externo) se crea sin relación laboral, con asignación de rol con vigencia. |

## 7. Segregación de funciones

Controles que el sistema impone aunque el usuario tenga ambos permisos (se comprueban sobre la **persona**, no sobre el rol):

| Proceso | Quien hace | No puede |
|---|---|---|
| Planilla (periodo) | Calcula, recalcula o carga incidencias del periodo | Aprobar ese mismo periodo (RN-PER-01) |
| Liquidación | Calcula la liquidación | Aprobarla (RN-LIQ-06) |
| Conceptos de planilla | Edita la fórmula | Publicarla, con doble control activo (RN-CPT-07) |
| Cualquier trámite | Es el solicitante o el beneficiario | Aprobarlo (RN-SEG-13, RN-WFL-05) |
| Revisión salarial | Es el beneficiario o su subordinado | Proponer o aprobar ese incremento (RN-COM-06) |
| Gastos | Registra el gasto | Aprobarlo ni revisarlo (RN-GAS-07) |
| Marcación | Solicita la corrección | Aprobarla (ME-14) |
| SST | Ejecuta la medida correctiva | Verificarla (ME-36) |
| Disciplinario | Conduce el caso | Decidir suspensión o despido (RN-DIS-03) |
| Denuncias | Es mencionado en el caso | Verlo o investigarlo (RN-DEN-04) |
| Mesa de ayuda | Crea la solicitud | Atenderla (RN-HSD-07) |
| Restauración de copias | Solicita la restauración | Promoverla sin una segunda aprobación (RN-SAS-09) |

Por defecto el rol **Payroll** trae calcular, enviar y cerrar, y **no** trae `payroll.periods.approve`, `payroll.benefit_runs.approve` ni `payroll.settlements.approve`; esos permisos están en Administrador empresa. Lo recomendado es crear un rol personalizado «Aprobador de planilla» (jefe de RRHH, finanzas o gerencia) con `payroll.periods.view`, `payroll.results.view`, `payroll.periods.approve`, `payroll.periods.reject`, `payroll.benefit_runs.approve`, `payroll.settlements.view` y `payroll.settlements.approve`. En una empresa muy pequeña con una sola persona en planilla, la aprobación la da el Administrador o, en modo estudio contable, el contacto del cliente (RN-EST-04).

## 8. Perfil de cada rol de sistema

- **Superadmin SaaS.** Solo consola de plataforma (`platform.*`). Ningún dato laboral.
- **Administrador empresa.** Configura la organización, usuarios, roles, módulos y políticas. Tiene los permisos de administración y de decisión final. **No** tiene acceso al contenido de denuncias ni a datos médicos ocupacionales detallados salvo asignación expresa; puede crearse un «Administrador sin sueldos» retirando los permisos **(S)** de compensación.
- **RRHH.** Gestiona el ciclo del colaborador: fichas, contratos, documentos, tiempo, talento, experiencia, casos disciplinarios. Ve datos personales; ve sueldos, no los edita.
- **Payroll.** Opera la nómina: incidencias, cálculo, cierre, boletas, declaraciones, préstamos, liquidaciones. Edita compensación y datos de pago. No aprueba lo que calcula.
- **Recruiter.** Vacantes, candidatos, entrevistas y ofertas. No ve colaboradores más allá del directorio y la estructura.
- **Manager.** Ve y aprueba lo de su equipo; propone movimientos e incrementos. No ve sueldos, salud ni sanciones salvo permiso adicional (bandas y propuestas en ciclo de revisión sí, para su equipo).
- **Empleado.** Lo propio: perfil, documentos, boletas, tiempo, solicitudes, objetivos, cursos, encuestas.
- **Auditor.** Lectura de casi todo lo no reservado, auditoría y registros de acceso. Sin escritura.
- **SST.** Módulo SST completo, EPP, datos de salud ocupacional, cursos de seguridad.
- **Investigador de denuncias.** Solo los casos que tiene asignados. Nada más por este rol.
- **Contador.** Lectura de periodos, resultados, declaraciones, asientos, provisiones, liquidaciones y gastos; exporta asientos.

## 9. Roles personalizados de ejemplo (plantillas sugeridas)

| Rol | Base | Cambios |
|---|---|---|
| Administrador sin sueldos | Administrador empresa | Sin `people.employments.salary.*`, `people.compensations.*`, `people.people.bank_account.*`, `compensation.*`, `payroll.results.*`, `analytics.metrics.labor_cost.view`, `org.positions.budget.view` |
| Aprobador de planilla | (vacío) | `payroll.periods.view`, `payroll.results.view`, `payroll.periods.approve`, `payroll.periods.reject`, `payroll.benefit_runs.approve`, `payroll.settlements.view`, `payroll.settlements.approve` |
| RRHH de sede | RRHH | Alcance «Sede» |
| Supervisor de cuadrilla | Manager | Añade `time.punches.create_for_crew`, `time.shift_assignments.*` |
| Finanzas (gastos) | Contador | Añade `expenses.reports.review`, `expenses.reports.mark_paid`, `expenses.advances.mark_paid` |
| Especialista de compensaciones | RRHH | Añade `compensation.*` y `people.employments.salary.view`; sin `discipline.*` |
| Oficial de datos personales | (vacío) | `privacy.*`, `audit.sensitive_access.view`, `audit.logs.view` |
| Miembro del comité de intervención | Investigador de denuncias | Solo categoría hostigamiento sexual, alcance «Asignado» |

## 10. Verificación automática

- Cada ruta de la API declara su permiso; una prueba falla si alguna ruta queda sin declarar.
- Por cada permiso **(S)** existe una prueba que comprueba que, sin él, el campo no aparece en detalle, listado, exportación, reporte, búsqueda ni API.
- Por cada alcance existe una prueba que comprueba «no encontrado» fuera de alcance.
- La matriz de esta sección es la semilla de los roles de sistema; una prueba compara la semilla con este documento.
- Pruebas específicas: no se puede dejar la organización sin administrador (RP-03); quien calcula no aprueba (sección 7); ningún rol distinto del asignado obtiene datos de una denuncia.
