# Empezar aquí · GestiaHR para el área de desarrollo

Esta guía dice qué hacer el primer día y en qué orden avanzar. El equipo trabaja con **Claude Code** sobre este kit. No hay tiempos, costos ni responsables: los define el equipo al planificar.

## 1. Preparar el repositorio

1. Crea el repositorio del producto y descomprime el zip en su raíz. Deben quedar `CLAUDE.md` y `.claude/commands/` en la raíz y el kit en `kit/`.
2. Copia el arranque a la raíz y comprueba el entorno:
   ```bash
   cp -r kit/referencia/arranque/{docker-compose.yml,Makefile,.env.example,.gitignore,.github} .
   make up && make kit-check
   ```
   `kit-check` carga el esquema en PostgreSQL 17, corre las pruebas de aislamiento y verifica que los archivos generados estén al día.
3. Abre Claude Code en la raíz. Lee `CLAUDE.md` solo y aplica sus 15 reglas no negociables en cada cambio.
4. Importa `GestiaHR_backlog_jira.csv` en Jira (ver §5).
5. Lee `kit/docs/20-entrega-al-equipo.md`: estado por módulo, cómo repartir el trabajo y definición de hecho.

## 2. Decisiones de la fase 0: cerradas

Las once decisiones que bloqueaban la fase 0 se cerraron el 05/11/2026 con la opción recomendada. Cada una tiene su ADR en `kit/docs/adr/`.

| Código | Decisión | Qué se eligió | ADR |
|---|---|---|---|
| A-01 | Stack | Laravel 12, Next.js 15, PostgreSQL 17 y Redis | 0004 |
| A-02 | Aislamiento multiempresa | Esquema compartido con `tenant_id` y RLS | 0005 |
| A-03 | Identificadores | UUID v7 | 0001 |
| A-04 | Sesión entre web y API | Cookie `HttpOnly` en el mismo dominio | 0006 |
| A-08 | Región de alojamiento | Fuera del Perú, con garantías documentadas | 0007 |
| D-01 | Planes y módulos | Planes con módulos incluidos, más complementos | 0008 |
| D-03 | Límite de colaboradores | Personas únicas con una relación activa | 0009 |
| D-04 | Cobro de la suscripción | Registro en la consola primero y pasarela después, con factura electrónica | 0010 |
| D-08 | Verificación en dos pasos | Obligatoria para roles con datos sensibles | 0011 |
| D-13 | Datos al apagar un módulo o dar de baja | Solo lectura, exportación, gracia y eliminación certificada | 0012 |
| D-29 | Códigos de permiso | En inglés, con etiqueta en español | 0002 |

Quedan tres datos pendientes, que no frenan el desarrollo porque el código los recibe como parámetro o adaptador:

- el visto bueno legal de la región de alojamiento (A-08, dentro de D-15);
- el proveedor de factura electrónica (OSE o PSE) y el tratamiento del IGV, que se confirma con el contador (D-04);
- los plazos de gracia y de conservación al dar de baja (D-13), que se fijan con legal.

El resto de `kit/docs/13-decisiones-abiertas.md` se cierra antes de la fase que indica cada decisión. Mientras una siga abierta, el código usa la recomendación detrás de un parámetro o interfaz.

## 3. Por dónde avanzar

`kit/backlog/ruta-mvp.md` ordena todo el backlog en **tandas**. Dentro de una tanda, las actividades no dependen entre sí y se pueden repartir entre varias personas a la vez.

- **Primero el MVP** (etiqueta `mvp`): plataforma, seguridad, organizaciones y planes, ficha del colaborador, vacaciones y planilla peruana.
- **En paralelo, el sitio comercial** (etiqueta `web`, WEB-1 a WEB-13). Solo depende del MVP. El diseño de referencia ya está hecho: abre `kit/docs/web/sitio/portada.html` en el navegador.
- **Después, la segunda ola** (etiqueta `ola-2`), y luego las fases 2 a 9 en orden.

Lo que se puede empezar desde el primer día (tandas 1 y 2 del MVP): `PLT-1` (monorepo); luego `PLT-2`, `PLT-3`, `PLT-4`, `PLT-12`, `PLT-14` y `UI-1`.

## 4. Cómo trabajar cada actividad con Claude Code

```
/historia PLT-1
```

El comando lee la historia, la especificación del módulo, las tablas y las rutas, implementa en el orden migración → dominio → caso de uso → endpoint → pruebas → pantalla, y termina con las pruebas en verde.

| Comando | Para |
|---|---|
| `/historia <clave>` | Implementar una actividad completa |
| `/migracion <qué>` | Crear una migración con aislamiento, vigencia e índices |
| `/endpoint <MÉTODO /ruta>` | Crear un endpoint según `kit/api/openapi.yaml` |
| `/pantalla <nombre>` | Construir una pantalla tomando el prototipo como referencia |
| `/revisar [rango]` | Revisar un cambio contra las reglas antes de integrarlo |

Para avanzar una épica completa en una sesión nueva, pega el prompt de `kit/prompts/<ÉPICA>.md`: trae las actividades en el orden en que se desbloquean, las dependencias de otras épicas y las decisiones abiertas que la tocan.

Más prompts en `kit/PROMPTS.md`. Para el sitio, el prompt 8 explica cómo usar el diseño de referencia.

Reglas de oro:

- Una actividad por rama y por pull request, con `/revisar` antes de integrar.
- Ningún número legal en el código: toda tasa, tope o plazo es un parámetro con vigencia (`kit/docs/19`).
- El prototipo (`kit/prototipo/`) es referencia de flujo y de aspecto, no de código (`kit/docs/15`).
- Si una actividad toca una decisión abierta, Claude Code se detiene y pregunta.

## 5. Jira

- Archivo: `GestiaHR_backlog_jira.csv`, también en `kit/backlog/backlog.csv`. Trae épicas, historias y tareas con su descripción, criterios de aceptación, referencias, componente, etiquetas y la columna «Bloqueada por».
- **No trae tiempos, puntos ni responsables.** El equipo los asigna al planificar.
- Para importarlo: en Jira, ve a **Importar desde CSV**. Asigna «Issue ID» y «Parent» para la jerarquía, «Labels» (las cuatro columnas) a etiquetas y «Bloqueada por» a vínculos de tipo «is blocked by».
- Filtros útiles: `labels = mvp`, `labels = web`, `labels = ola-2`, `labels = producto-planilla`.

## 6. Diseño

- El sistema y el sitio usan los mismos tokens (`kit/docs/10`): azul #0A3A73, fondo #F3F5F9, verde #0E7C6B, amarillo #F4B400 para el estado activo, y la tipografía **IBM Plex Sans** con IBM Plex Mono para códigos y cifras.
- Sistema: `kit/prototipo/GestiaHR_prototipo.html`.
- Sitio: `kit/docs/web/sitio/` (47 pantallas, con SEO por página, textos legales y blog).

## 7. Lo que no está en el kit y lo aporta el negocio

- Precios definitivos de los planes y complementos. El sitio de referencia muestra montos de ejemplo, y en el producto se cargan en la consola.
- Razón social, RUC y datos de contacto de la empresa.
- Revisión legal de términos, privacidad, cookies y Libro de Reclamaciones.
- Validación de parámetros legales por el especialista laboral (`kit/docs/19`).
- Clientes, logos y testimonios reales, con su permiso.
- Compra del dominio `gestiahr.com` y registro de la marca.
