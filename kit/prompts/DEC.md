# DEC · Declaraciones y archivos

Archivos de PLAME, T-Registro, AFPnet, bancos y certificados, con validación previa.

- **Fase:** Fase 3 · Nómina Perú. **Módulo comercial:** Nómina Perú.
- **Actividades:** 11 (9 historias y 2 tareas); 11 del MVP. **Operaciones de API:** 54.
- **Especificación:** `kit/docs/modulos/M-DEC.md`.

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica DEC · Declaraciones y archivos de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-DEC.md, kit/prompts/DEC.md y la sección DEC de
kit/backlog/backlog.md (grep -n "^### DEC-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. DEC-3 · T-Registro
2. DEC-8 · Ficha del registro laboral y validación previa al alta
3. DEC-1 · Mapeo de conceptos a tablas SUNAT
4. DEC-6 · Certificados de renta y de retenciones
5. DEC-9 · Declaración jurada de rentas de otros empleadores
6. DEC-2 · Archivos de PLAME
7. DEC-4 · Planilla de aportes para AFPnet
8. DEC-11 · Devolución de retenciones de quinta en exceso
9. DEC-5 · Archivos bancarios
10. DEC-7 · Bitácora de declaraciones
11. DEC-10 · Generador de archivos con estructuras versionadas
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-35. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `DEC-3` | T-Registro | Historia | MVP | COL-6, COL-7 |
| 2 | `DEC-8` | Ficha del registro laboral y validación previa al alta | Historia | MVP | DEC-3, COL-6 |
| 3 | `DEC-1` | Mapeo de conceptos a tablas SUNAT | Tarea | MVP | CPT-5 |
| 4 | `DEC-6` | Certificados de renta y de retenciones | Historia | MVP | PER-5, DOC-5 |
| 5 | `DEC-9` | Declaración jurada de rentas de otros empleadores | Historia | MVP | PER-5, COL-6 |
| 6 | `DEC-2` | Archivos de PLAME | Historia | MVP | DEC-1, PER-8 |
| 7 | `DEC-4` | Planilla de aportes para AFPnet | Historia | MVP | PER-6, PER-8 |
| 8 | `DEC-11` | Devolución de retenciones de quinta en exceso | Historia | MVP | PER-11 |
| 9 | `DEC-5` | Archivos bancarios | Historia | MVP | PER-10 |
| 10 | `DEC-7` | Bitácora de declaraciones | Historia | MVP | DEC-2, DEC-4 |
| 11 | `DEC-10` | Generador de archivos con estructuras versionadas | Tarea | MVP | DEC-2 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`COL-6`, `COL-7`, `CPT-5`, `DOC-5`, `PER-5`, `PER-6`, `PER-8`, `PER-10`, `PER-11`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-35 | Enumerados ampliados de documento, derechohabiente y novedad del registro laboral | Fase 3 | (a) mientras Perú sea el único paquete; revisar al diseñar el segundo país. |
