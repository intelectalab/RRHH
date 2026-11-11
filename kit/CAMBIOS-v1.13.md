# Cambios de la versión 1.13 (05/11/2026)

## 1. Entrega al equipo de desarrollo

- **`docs/20-entrega-al-equipo.md`** (nuevo, generado). Es la puerta de entrada técnica para quienes construyen con Claude Code. Trae:
  - qué recibe el equipo y qué no está hecho todavía (la aplicación);
  - el primer día, paso a paso;
  - cómo repartir el trabajo entre varias personas con IA: una actividad por sesión, rama y pull request, y archivos generados que no se editan a mano;
  - la definición de hecho por actividad;
  - el estado de las 72 épicas: historias, tareas, actividades del MVP, operaciones de API, avance en el kit y decisiones abiertas que las tocan;
  - las 30 decisiones abiertas que tocan el MVP, con la recomendación del kit.
- **`kit/prompts/`** (nuevo, generado): un prompt por épica para pegar en una sesión nueva de Claude Code. Cada uno trae las actividades en el orden en que se desbloquean, las dependencias de otras épicas y las decisiones abiertas. `PROMPTS.md` §9 explica cómo retomar una épica a medias.
- **`tools/gen_entrega.py`** genera los dos. Falla si una decisión abierta de `docs/13` no tiene épicas asignadas.
- `CLAUDE.md`, `EMPEZAR-AQUI.md`, `README.md` y `PROMPTS.md` enlazan la entrega y los prompts.

## 2. Arranque del repositorio (adelanta PLT-1, PLT-2 y PLT-3)

`referencia/arranque/` se copia a la raíz del repositorio:

- `docker-compose.yml`: PostgreSQL 17, Redis, S3 local (SeaweedFS) y buzón de correo de pruebas (Mailpit), con chequeo de salud. `PG_PORT` cambia el puerto si ya hay otro PostgreSQL.
- `Makefile`: las metas que nombra `CLAUDE.md`. Las del kit ya funcionan: `make kit-check` carga el esquema, corre las pruebas de aislamiento y verifica que los archivos generados coincidan con sus fuentes. Las de la aplicación avisan en qué historia se crean.
- `.github/workflows/kit.yml`: integración continua con cuatro comprobaciones. El esquema y el aislamiento corren en PostgreSQL 17. También se verifica que los generadores estén sincronizados, que los diagramas mermaid se lean y que la calculadora de referencia pase sus pruebas.
- `.env.example` y `.gitignore`.

## 3. Comprobaciones que antes faltaban

- **PostgreSQL 17**: `schema.sql` carga limpio en 17.11 y `pruebas_aislamiento.sql` termina en «TODAS LAS PRUEBAS PASARON». `docs/04` se regeneró con los conteos leídos de esa base.
- **Diagramas mermaid**: hay un verificador nuevo, `tools/verificar_mermaid.js`. Los 70 diagramas se leen sin error. Uno de `docs/02` (mapa de módulos) fallaba por los puntos de las etiquetas `candidate.hired` y `employee.hired/terminated`, y se corrigió poniéndolas entre comillas.

## 4. Calculadora de planilla de referencia

En la liquidación se agregaron:

- **Indemnización por despido arbitrario**:
  - en plazo indeterminado, con las fracciones de mes y día y su tope;
  - en plazo fijo, por los meses que faltaban;
  - en periodo de prueba no se paga y queda un aviso;
  - por régimen: 45 días por año en el general, 20 en pequeña empresa y 10 en microempresa.
- **Indemnización vacacional** por vacaciones vencidas sin gozar, aparte de la remuneración vacacional.
- **Quinta del cese**: se calcula sobre la liquidación sin proyectar meses y se retiene completa.

Además:

- **Regularización de diciembre**, con el exceso a devolver. La devolución espera el parámetro nuevo `quinta_devuelve_exceso`, que va sin valor hasta que el especialista confirme el procedimiento. Mientras tanto, queda un aviso con el monto.
- **Régimen agrario** (Ley 31110): gratificación y CTS prorrateadas, BETA no remunerativa y EsSalud por tramo. La tasa por tramo (`agr_essalud_pct`) sigue sin valor: sin ella, el cálculo queda con error.
- **Construcción civil**: jornal por categoría, BUC, movilidad por día laborado, y provisiones de CTS, vacaciones y gratificación en sétimos o quintos.
- **Pasos por régimen**: van en `config/payroll.php` (`steps_by_regime`), sin condicionales en el código.
- **Pruebas**: hay 15 nuevas y suman 46, todas en verde en PostgreSQL 16 y 17.
- **Catálogo**: tiene cinco parámetros nuevos y suma 166. En `docs/19` quedan 40 sin valor verificado (33 del MVP) y 102 con la fecha por confirmar.

## 5. Sitio comercial

- **Textos legales completos**: términos y condiciones, política de privacidad (Ley 29733), política de cookies (página nueva) y acuerdo de tratamiento de datos (página nueva), con GestiaHR como encargado del tratamiento. Los datos de la empresa van marcados [por definir] y lo que debe confirmar el abogado, [validar]. Se editan en `tools/sitio/legales.py`.
- **Seis artículos del blog**:
  - calendario laboral 2026;
  - gratificación de julio;
  - CTS;
  - récord vacacional;
  - errores al subir PLAME y T-Registro;
  - migrar la planilla desde Excel.
- Cada artículo trae índice, datos estructurados `BlogPosting` y artículos relacionados. Toda cifra legal va marcada [validar]. Se editan en `tools/sitio/blog.py`.
- El sitio pasa de 40 a 47 pantallas, sin enlaces rotos, errores de JavaScript ni desborde en el celular.

## 6. Backlog y Jira

- Los conteos no cambian: 72 épicas, 362 historias y 150 tareas; MVP 241, segunda ola 9 y sitio 13.
- En el CSV, cada épica enlaza su especificación y su prompt, y cada actividad termina con su comando (`/historia <clave>`).
- Sigue sin tiempos, puntos ni responsables.

## Qué se comprobó

- `generar_backlog.py`: todas las verificaciones pasaron. `gen_ruta.py` no cambia las tandas.
- `make kit-check` con PostgreSQL 17.11: el esquema carga, el aislamiento pasa y los generadores reproducen exactamente los archivos del kit.
- `gen_openapi.py`: OpenAPI válido, sin cambios en la API (738 rutas y 998 operaciones).
- La calculadora pasa 46 de 46 pruebas en PostgreSQL 16.15 y 17.11.
- `verificar_mermaid.js`: 70 diagramas sin error.
- Sitio: 47 pantallas sin errores a 390 px.
- No se comprobó la integración continua en GitHub: corre cuando exista el repositorio.

## Archivos nuevos

- `docs/20-entrega-al-equipo.md`, `prompts/` (73 archivos), `tools/gen_entrega.py` y `tools/verificar_mermaid.js`.
- `referencia/arranque/`.
- `tools/sitio/legales.py`, `tools/sitio/blog.py`, `cookies.html`, `tratamiento-de-datos.html` y cinco artículos nuevos en `docs/web/sitio/`.
- `referencia/calculadora-planilla/`:
  - `Settlement/Dismissal.php`;
  - cinco pasos de régimen (`Agrarian*` y `Construction*`);
  - `tests/Unit/IndemnitiesFinalTaxAndSpecialRegimesTest.php`.
