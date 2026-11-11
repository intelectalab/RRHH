# GestiaHR · Cambios de la versión 1.7

Versión 1.7 · 04/11/2026. Agrega al backlog las mejoras que diferencian a GestiaHR frente a la competencia (`docs/00-analisis-de-competencia.md` §5.5) y las ordena en el MVP y en una segunda ola. No cambia la estructura del esquema ni el contrato de la API, salvo los nombres del cambio de marca. Los cambios de la 1.6 siguen en `CAMBIOS-v1.6.md`.


## Cambio de nombre: Ayni HR pasa a GestiaHR

El producto se llama **GestiaHR** y su dominio es `gestiahr.com`. El cambio cubre todo el kit, y los generadores producen el mismo resultado con el nombre nuevo.

| Antes | Ahora |
|---|---|
| Ayni HR, AyniHR | GestiaHR |
| `*.aynihr.example`, `empleos.aynihr.demo` | `*.gestiahr.com` (`api.`, `empleos.`, `organizaciones.`, `{slug}.`) y `avisos@gestiahr.com` |
| Roles `ayni_app`, `ayni_platform` | `gestia_app`, `gestia_platform` |
| Base de datos local `ayni` | `gestiahr` |
| Cookie `ayni_session`, tokens `ayni_live_…` | `gestia_session`, `gestia_live_…` |
| Cabeceras de webhooks `Ayni-*`, `X-Ayni-*` | `Gestia-*`, `X-Gestia-*` |
| Registros DNS `_ayni-verificacion`, `ayni._domainkey` | `_gestia-verificacion`, `gestia._domainkey` |
| Variable `AYNI_PSQL` | `GESTIA_PSQL` |
| `AyniHR_backlog_jira.csv`, `AyniHR_prototipo.html` | `GestiaHR_backlog_jira.csv`, `GestiaHR_prototipo.html` |

## Nuevas actividades del MVP

| Clave | Mejora | Diferencia que ataca |
|---|---|---|
| CUM-9 | Tablero mínimo de obligaciones de planilla: T-Registro, PLAME, AFPnet, CTS, gratificaciones, contratos por vencer y umbral del comité de SST, con responsable, aviso y evidencia | Nadie vende el cumplimiento como producto; ya no espera a la fase 7. CUM-2 lo amplía |
| PAR-7 | Novedades normativas visibles para el cliente: qué cambió, desde cuándo, con qué norma y qué efecto tiene en el periodo abierto | Base del compromiso comercial de actualización normativa |
| IMP-7 | Importadores desde otros sistemas de RRHH y planilla, con perfiles de mapeo versionados | Implementación larga del líder local |
| PER-13 | Comparación boleta por boleta con el sistema anterior durante la planilla en paralelo | Confianza para cambiar de sistema; QA-17 ahora depende de ella |
| BOL-5 | Explicación del cambio de neto en la boleta, desde la traza del cálculo (la IA, si está activa, solo mejora la redacción) | Menos consultas a RRHH y más claridad para el colaborador |

## Segunda ola (etiqueta `ola-2`)

| Claves | Mejora |
|---|---|
| WHA-1, WHA-2, WHA-3 | WhatsApp básico: boleta, saldo de vacaciones y constancia (D-11 se adelanta) |
| PRV-5 (nueva) | Simulador público de costo laboral en la web, con el mismo motor y parámetros de la planilla |
| EST-1 a EST-5 | Modo estudio contable; EST-4 usa ahora el tablero mínimo CUM-9 en lugar de CUM-3 |

`generar_backlog.py` verifica que la segunda ola solo dependa del MVP o de la propia ola. En el CSV, la tercera columna `Labels` lleva `mvp` u `ola-2`; en el Markdown, «· MVP» u «· Ola 2».

## Documentos

- `docs/01` §5.1 suma los cinco diferenciadores del MVP y §5.2 describe la segunda ola.
- `docs/13`: D-11 (proveedor de WhatsApp) se cierra antes de la segunda ola.
- `CLAUDE.md` y `README.md` actualizados.

## Totales

70 épicas, 343 historias y 145 tareas (488 actividades): 229 del MVP y 9 de la segunda ola.
