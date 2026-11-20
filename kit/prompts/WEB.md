# WEB · Sitio comercial gestiahr.com

Sitio público de GestiaHR: inicio, páginas de producto, Suite, precios, registro de prueba y suscripción, demo, blog, SEO, chatbot comercial y su administración en la consola.

- **Fase:** Fase 0 · Cimientos. **Módulo comercial:** —.
- **Actividades:** 13 (10 historias y 3 tareas); 0 del MVP. **Operaciones de API:** 39.
- **Especificación:** `kit/docs/modulos/M-WEB.md`.
- **Ya avanzado en el kit:** Sitio de referencia de 47 pantallas en `kit/docs/web/sitio/`, con SEO, textos legales y blog (`kit/tools/sitio/`).

## Prompt para una sesión nueva de Claude Code

Abre Claude Code en la raíz del repositorio y pega:

```
Avanzamos la épica WEB · Sitio comercial gestiahr.com de GestiaHR.
Lee CLAUDE.md, kit/docs/modulos/M-WEB.md, kit/prompts/WEB.md y la sección WEB de
kit/backlog/backlog.md (grep -n "^### WEB-" kit/backlog/backlog.md).
Trabaja las actividades en este orden, cada una con /historia <clave>, en su propia rama
(feat/<clave>-<resumen>) y con su propio pull request:
1. WEB-1 · Aplicación del sitio comercial y su CMS
2. WEB-8 · Blog, boletín y comentarios moderados
3. WEB-11 · Popups, banner de cookies y medición
4. WEB-6 · Agendar demo, contacto y partners
5. WEB-12 · Páginas legales y libro de reclamaciones virtual
6. WEB-13 · Sección «Sitio web» de la consola
7. WEB-2 · Catálogo de productos ligado a los módulos
8. WEB-3 · Inicio, páginas de producto y página de la Suite
9. WEB-4 · Precios, comparador y selector de módulos
10. WEB-10 · Chatbot comercial del sitio
11. WEB-5 · Registro de prueba y suscripción en tres pasos
12. WEB-7 · Lista de espera de lo que está en «Próximamente»
13. WEB-9 · SEO técnico y páginas por módulo e industria
Antes de cada actividad comprueba que sus dependencias ya estén en main
(git log --oneline --grep "\[<clave>\]"). Si falta alguna, sáltala, anótala y sigue con la
siguiente que esté libre.
Decisiones abiertas que pueden tocarla: D-39, D-40, D-41. Si una actividad depende de una de ellas,
aplica la recomendación de kit/docs/13 detrás de un parámetro o interfaz y dilo en el pull request;
si la recomendación no alcanza, detente y pregunta.
Al terminar cada actividad: make test lint en verde, /revisar, y un resumen con los criterios
cumplidos uno por uno y lo que quedó fuera. Al final, dime qué actividades quedaron pendientes y por qué.
```

## Actividades en orden

| # | Clave | Actividad | Tipo | Corte | Depende de |
|---|---|---|---|---|---|
| 1 | `WEB-1` | Aplicación del sitio comercial y su CMS | Tarea | sitio | PLT-1, PLT-9, PLT-5 |
| 2 | `WEB-8` | Blog, boletín y comentarios moderados | Historia | sitio | WEB-1 |
| 3 | `WEB-11` | Popups, banner de cookies y medición | Historia | sitio | WEB-1 |
| 4 | `WEB-6` | Agendar demo, contacto y partners | Historia | sitio | WEB-1, NOT-3 |
| 5 | `WEB-12` | Páginas legales y libro de reclamaciones virtual | Historia | sitio | WEB-1, NOT-3 |
| 6 | `WEB-13` | Sección «Sitio web» de la consola | Historia | sitio | SAS-1, SAS-13, WEB-6 |
| 7 | `WEB-2` | Catálogo de productos ligado a los módulos | Tarea | sitio | SAS-3, SAS-5, WEB-1 |
| 8 | `WEB-3` | Inicio, páginas de producto y página de la Suite | Historia | sitio | WEB-2 |
| 9 | `WEB-4` | Precios, comparador y selector de módulos | Historia | sitio | WEB-2, SAS-4 |
| 10 | `WEB-10` | Chatbot comercial del sitio | Historia | sitio | WEB-2, PLT-13 |
| 11 | `WEB-5` | Registro de prueba y suscripción en tres pasos | Historia | sitio | WEB-4, SAS-2, SAS-11, SEG-6, SEG-5 |
| 12 | `WEB-7` | Lista de espera de lo que está en «Próximamente» | Historia | sitio | WEB-3, WEB-6 |
| 13 | `WEB-9` | SEO técnico y páginas por módulo e industria | Tarea | sitio | WEB-3, WEB-8 |

## Dependencias de otras épicas

Deben estar integradas antes de las actividades que las piden:

`NOT-3`, `PLT-1`, `PLT-5`, `PLT-9`, `PLT-13`, `SAS-1`, `SAS-2`, `SAS-3`, `SAS-4`, `SAS-5`, `SAS-11`, `SAS-13`, `SEG-5`, `SEG-6`

## Decisiones abiertas

| Código | Decisión | Cerrar antes de | Recomendación del kit |
|---|---|---|---|
| D-39 | Qué se publica en el sitio al abrir la venta y si asistencia (fase 2) entra antes | Antes de abrir la venta | (b). Asistencia es lo que más se compra junto con planilla y vacaciones; las fases 4 a 9 casi nunca son requisito para cambiar de sistema. El sitio se publica antes (blog, SEO, calculadora y lista de espera) porque el posicionamiento tarda en llegar. |
| D-40 | Dominio propio por organización (WLB-2) en el lanzamiento | Antes de abrir la venta | (a), salvo que ventas lo pida como condición. El subdominio cubre el lanzamiento y WLB-2 exige verificación de dominio y certificados por organización. |
| D-41 | CMS del sitio comercial | Antes de WEB-1 | (a). Reutiliza el conocimiento del área, deja editar a contenido y marketing sin desplegar y mantiene el sitio separado de la base de las organizaciones. |
