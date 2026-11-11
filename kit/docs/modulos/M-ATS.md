# M-ATS · Reclutamiento, portal de empleos y base de talento

Fase 4 · Talento · Épica `ATS` · Reglas `RN-ATS-nn` · Módulo comercial: Reclutamiento (`recruiting`)

## 1. Objetivo

Llevar cada vacante desde su aprobación hasta la contratación con un tablero configurable, un portal público propio y una base de talento reutilizable.

## 2. Alcance

- Vacante ligada a una posición: descripción, requisitos, modalidad, ubicación, rango salarial opcional, responsable, equipo de selección.
- Aprobación, publicación (portal propio, enlaces para bolsas externas), cierre.
- Postulaciones con tablero por etapas configurable (nuevo, preselección, entrevista RRHH, entrevista técnica, evaluación, referencias, oferta, contratado) y arrastrar y soltar.
- Candidato: datos, CV, experiencia, educación, pretensión salarial, fuente, notas, documentos, entrevistas, evaluaciones, actividad.
- Portal de empleos público por empresa con formulario configurable y SEO (títulos, descripciones, datos estructurados, mapa del sitio).
- Base de talento con etiquetas, búsqueda y filtros avanzados; referidos y movilidad interna (vacantes internas, postulación de colaboradores).
- Consentimiento y retención de datos de candidatos; resumen de CV con IA como apoyo.

## 3. Fuera de alcance

- Entrevistas y ofertas (M-ENT). Pruebas psicométricas y verificación de antecedentes (integración por socio, M-INT).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §10 Reclutamiento — ATS; §11 Portal de empleos; §12 Talent pool.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** E-14 Movilidad interna, referidos y matriz de habilidades.

## 5. Actores y permisos

- Recruiter gestiona. Manager solicitante participa y evalúa. RRHH aprueba. Candidato postula sin cuenta. Colaborador refiere o postula internamente.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `recruiting.requisitions.view` | Ver vacantes | Administrador empresa, RRHH, Recruiter, Manager, Auditor |
| `recruiting.requisitions.approve` | Aprobar vacantes | Administrador empresa, RRHH |
| `recruiting.requisitions.close` | Cerrar vacantes | Administrador empresa, RRHH, Recruiter |
| `recruiting.requisitions.create` | Crear vacantes | Administrador empresa, RRHH, Recruiter |
| `recruiting.requisitions.hold` | Pausar vacantes | Administrador empresa, RRHH, Recruiter |
| `recruiting.requisitions.publish` | Publicar vacantes | Administrador empresa, RRHH, Recruiter |
| `recruiting.requisitions.submit` | Enviar vacantes | Administrador empresa, RRHH, Recruiter |
| `recruiting.requisitions.update` | Editar vacantes | Administrador empresa, RRHH, Recruiter |
| `recruiting.applications.view` | Ver postulaciones | Administrador empresa, RRHH, Recruiter; solo sobre lo propio o asignado: Manager |
| `recruiting.applications.create` | Crear postulaciones | Administrador empresa, RRHH, Recruiter |
| `recruiting.applications.hire` | Contratar desde postulaciones | Administrador empresa, RRHH, Recruiter |
| `recruiting.applications.move_stage` | Mover de etapa postulaciones | Administrador empresa, RRHH, Recruiter |
| `recruiting.applications.reject` | Rechazar postulaciones | Administrador empresa, RRHH, Recruiter |
| `recruiting.applications.update` | Editar postulaciones | Administrador empresa, RRHH, Recruiter |
| `recruiting.applications.withdraw` | Retirar postulaciones | Administrador empresa, RRHH, Recruiter |
| `recruiting.candidates.view` | Ver candidatos | Administrador empresa, RRHH, Recruiter; solo sobre lo propio o asignado: Manager |
| `recruiting.candidates.anonymize` | Anonimizar candidatos | Administrador empresa, RRHH |
| `recruiting.candidates.create` | Crear candidatos | Administrador empresa, RRHH, Recruiter |
| `recruiting.candidates.update` | Editar candidatos | Administrador empresa, RRHH, Recruiter |
| `recruiting.talent_pools.view` | Ver la base de talento | Administrador empresa, RRHH, Recruiter |
| `recruiting.talent_pools.create` | Crear la base de talento | Administrador empresa, RRHH, Recruiter |
| `recruiting.talent_pools.delete` | Eliminar la base de talento | Administrador empresa, RRHH, Recruiter |
| `recruiting.talent_pools.update` | Editar la base de talento | Administrador empresa, RRHH, Recruiter |
| `recruiting.career_site.update` **(+)** | Configurar el portal de empleos y el formulario | Administrador empresa, RRHH, Recruiter |
| `recruiting.referrals.create` **(+)** | Referir candidatos y postular a vacantes internas | Empleado, Manager |
| `recruiting.candidates.salary_expectation.view` **(S)** **(+)** | Ver pretensión salarial del candidato | Administrador empresa, RRHH, Recruiter |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Vacante.** 1) Se crea desde una posición aprobada o vacante. 2) La IA puede proponer la descripción; el reclutador la edita. 3) Se aprueba. 4) Se publica en el portal. 5) Llegan postulaciones. 6) El equipo mueve candidatos por etapas, registra notas y evaluaciones. 7) Con oferta aceptada, el candidato pasa a «Contratado», la vacante se cierra y se precarga el ingreso.

**Postulación pública.** 1) El candidato abre la vacante. 2) Completa el formulario, adjunta CV y acepta el aviso de privacidad. 3) Recibe confirmación y un enlace para ver su estado. 4) Si ya existe por documento o correo, se une a su perfil.

**Descarte.** 1) Se elige motivo. 2) Se envía (opcional) un mensaje de cierre. 3) Si aceptó permanecer en la base, queda en talento con etiquetas.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Vacantes | Tabla con posición, estado, postulantes por etapa, días abierta, responsable; crear | Prototipo: Talento → Reclutamiento |
| Tablero de la vacante | Columnas por etapa con tarjetas arrastrables; filtros; acciones masivas | Prototipo: Talento → Reclutamiento |
| Ficha del candidato | Datos, CV, experiencia, educación, pretensión (con permiso), fuente, notas, documentos, entrevistas, evaluaciones, actividad | Prototipo: Talento → Reclutamiento |
| Base de talento | Búsqueda por etiquetas, habilidades, experiencia, fuente; invitar a vacante | Prototipo: Talento → Base de talento |
| Portal de empleos | Página pública con vacantes, ubicación, modalidad, descripción, requisitos, beneficios y formulario | Prototipo: Talento → Portal de empleos |
| Configuración del portal | Marca, textos, campos del formulario, etapas por defecto | Prototipo: Talento → Reclutamiento |
| Vacantes internas | Vacantes abiertas a colaboradores; postular, referir | Prototipo: Talento → Reclutamiento |

## 8. Datos principales

Vacantes (`job_requisitions`), etapas por vacante, candidatos (`candidates`), postulaciones (`applications`), notas, etiquetas, fuentes, referidos, consentimientos de candidato, configuración del portal.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección ATS.

- **RN-ATS-01** (parámetro): Si la empresa exige posición aprobada → no se crea una vacante sin posición en estado aprobada o vacante.
- **RN-ATS-02**: Si la vacante no está aprobada → no se puede publicar.
- **RN-ATS-03**: Si un candidato postula → debe aceptar el aviso de privacidad; sin aceptación no se guarda la postulación.
- **RN-ATS-04** (parámetro): Si un candidato no contratado supera el plazo de conservación (parámetro por organización y jurisdicción) sin renovar su consentimiento → sus datos se anonimizan.
- **RN-ATS-05**: Si un candidato ya existe (mismo documento o correo) → la nueva postulación se añade a su perfil; no se duplica.
- **RN-ATS-06**: Si se descarta un candidato → se exige motivo de un catálogo; el motivo no se muestra al candidato salvo el mensaje elegido.
- **RN-ATS-07**: Si la IA resume un CV o sugiere orden de revisión → se muestra como apoyo con su explicación; ninguna postulación se descarta ni avanza de forma automática.
- **RN-ATS-08**: Si una postulación llega a «Contratado» → requiere una oferta aceptada (M-ENT).
- **RN-ATS-09**: Si la vacante cubre todas sus plazas → se cierra y las postulaciones activas restantes pasan a descarte con motivo «vacante cubierta», previa confirmación del reclutador.
- **RN-ATS-10**: Si un colaborador refiere a un candidato → queda como fuente «referido» con el referente, que solo ve el estado general.
- **RN-ATS-11**: Si el formulario pide datos sensibles (discapacidad para ajustes razonables) → son opcionales, con finalidad explícita, y no visibles para el equipo evaluador.
- **RN-ATS-12** (parámetro): Si una vacante publica rango salarial (parámetro por empresa) → se toma de la banda del puesto.

## 10. Estados

- ME-05 · Vacante (`05-maquinas-de-estado.md`): estados Borrador, En aprobación, Aprobada, Publicada, Pausada, Cubierta, Cancelada.
- ME-06 · Postulación del candidato (`05-maquinas-de-estado.md`): estados Nuevo, En proceso (etapas configurables), Oferta, Contratado, Descartado, Retirado.

## 11. Eventos

**Emite:**

- `requisition.created`, `requisition.approved`, `requisition.published`, `requisition.on_hold`, `requisition.closed`, `candidate.applied`, `application.stage_changed`, `application.rejected`, `candidate.hired`, `candidate.anonymized`.

**Consume:**

- `position.approved`, `offer.accepted`, `offer.declined`, `interview.completed`.

## 12. Integraciones

Bolsas de empleo, pruebas y verificación de antecedentes por socio, lectura de CV; por adaptador.

## 13. Casos borde

- Candidato que postula a varias vacantes a la vez.
- Ex-colaborador que postula: se enlaza con su persona y se muestra su historia a quien tenga permiso.
- Vacante confidencial (reemplazo): visible solo para su equipo.
- Postulación interna: el jefe actual se entera según política.

## 14. Criterios de aceptación generales

- El flujo crear → aprobar → publicar → recibir → evaluar → entrevistar → calificar → contratar funciona y alimenta tiempo y costo de contratación.
- El portal público es indexable y su formulario es configurable.
