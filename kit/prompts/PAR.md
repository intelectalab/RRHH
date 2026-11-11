# PAR · Parámetros legales con vigencia

Tasas, topes y tablas legales por país y régimen, con vigencia por fecha y actualización controlada.

- **Fase:** Fase 3 · Nómina Perú. **Módulo comercial:** Nómina Perú.
- **Actividades:** 7 (6 historias y 1 tareas); 7 del MVP. **Operaciones de API:** 8.
- **Especificación:** `kit/docs/modulos/M-PAR.md`.
- **Ya avanzado en el kit:** `kit/referencia/calculadora-planilla/`: repositorio de parámetros con vigencia; 166 parámetros de Perú en el catálogo.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica PAR · Parámetros legales con vigencia de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-PAR.md, kit/prompts/PAR.md y la sección PAR de
kit/backlog/backlog.md (grep -n "^### PAR-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. PAR-1 · Parámetros legales generales
2. PAR-2 · Tablas de AFP
3. PAR-3 · Escala y deducciones de renta de quinta
4. PAR-4 · Publicación de actualizaciones normativas
5. PAR-5 · Parámetros por régimen laboral
6. PAR-6 · Tabla salarial anual por convenio
7. PAR-7 · Novedades normativas visibles para el cliente
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-06, D-15, D-27, D-36. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `PAR-1` | Parámetros legales generales | Historia | MVP | CFG-3, NOM-1 |
| 2 | `PAR-2` | Tablas de AFP | Historia | MVP | PAR-1 |
| 3 | `PAR-3` | Escala y deducciones de renta de quinta | Historia | MVP | PAR-1 |
| 4 | `PAR-4` | Publicación de actualizaciones normativas | Historia | MVP | PAR-1, SAS-13 |
| 5 | `PAR-5` | Parámetros por régimen laboral | Tarea | MVP | PAR-1, NOM-2 |
| 6 | `PAR-6` | Tabla salarial anual por convenio | Historia | MVP | PAR-1 |
| 7 | `PAR-7` | Novedades normativas visibles para el cliente | Historia | MVP | PAR-4, NOT-2, PER-7 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`CFG-3`, `NOM-1`, `NOM-2`, `NOT-2`, `PER-7`, `SAS-13`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-06 | Regímenes laborales del primer lanzamiento | Fase 3 | general + microempresa + pequeña empresa + modalidades formativas primero; construcción civil y agrario después. El motor admite todos; lo que se decide es el contenido del paquete y sus casos dorados. |
| D-15 | Validación legal de plazos, retenciones y rol de encargado | Fase 1 (y antes de cada fase con norma) | (a). Cada valor entra como parámetro con estado «por validar» y no puede usarse en producción hasta que lo valide el responsable (D-27). |
| D-27 | Responsable y proceso de actualización de parámetros legales | Fase 3 | (c): parámetros legales nacionales mantenidos por la plataforma con un responsable nombrado, doble revisión, fuente normativa obligatoria y aviso a las organizaciones; parámetros de política propia mantenidos por la organización. Definir quién es el especialista que valida y mantiene los casos dorados. |
| D-36 | Qué fuente secundaria basta para marcar un parámetro legal como verificado | Fase 3 | (b) antes de producción: el cierre de planilla debería exigir verificación de especialista, no de fuente secundaria (RN-PAR-03). |
