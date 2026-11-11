# Cambios de la versión 1.10 (05/11/2026)

## Para empezar

- **`EMPEZAR-AQUI.md`** (nuevo): qué decisiones cerrar antes de escribir código, por dónde avanzar, cómo trabajar cada actividad con Claude Code, cómo importar el backlog en Jira y qué aporta el negocio.
- **`backlog/ruta-mvp.md`** (nuevo, generado por `tools/gen_ruta.py`): todo el MVP, el sitio y la segunda ola ordenados en tandas. Dentro de una tanda, las actividades se pueden trabajar en paralelo.

## Diseño

- **Tipografía única** para el sistema y el sitio: IBM Plex Sans (títulos y texto) e IBM Plex Mono (códigos y cifras). Reemplaza a Bricolage Grotesque, Figtree y JetBrains Mono en `docs/10` y en el prototipo.
- **Sitio comercial de referencia** en `docs/web/sitio/` (29 pantallas; abrir `portada.html`). Usa los mismos tokens y formas del sistema y muestra capturas reales del prototipo:
  - **Vender:** inicio, Suite, una página por producto (Personas, Planilla, Tiempo, Talento y Formación, estas dos con lista de espera) y precios con comparador y selector de módulos.
  - **Convertir:** registro en tres pasos, bienvenida, demo, demo agendada y partners.
  - **Contenido:** blog, artículo con comentarios moderados, página de software de planillas, página de retail y calculadora de costo laboral.
  - **Cuenta y legales:** ingreso, verificación en dos pasos, recuperación, términos, privacidad, Libro de Reclamaciones y reclamo registrado.
  - **Consola:** la sección «Sitio web» con el mismo esqueleto del sistema.
  - **En todas las páginas:** el chatbot y el banner de cookies.
- Las páginas se generan con `tools/sitio/build.py`. «Iniciar sesión» e «Ir a mi panel» llevan al prototipo (`sistema.html`).

## Backlog

- WEB-1 y WEB-3 a WEB-13 citan su pantalla de referencia en `docs/web/sitio/`.
- Nuevo prompt 8 en `PROMPTS.md`: cómo construir una página del sitio a partir del diseño de referencia.
- **Sin cambios de alcance:** 72 épicas, 362 historias y 150 tareas. Hay 241 actividades del MVP, 9 de la segunda ola y 13 del sitio. El CSV de Jira no lleva tiempos, puntos ni responsables.

## Pendiente del negocio

- Precios (en el sitio dicen «S/ —»).
- Razón social y RUC.
- Revisión legal de las páginas legales.
- Clientes y testimonios reales.
- Validación de parámetros por el especialista laboral.
- Decisiones D-39 a D-41.

## Sitio más vendedor (ajuste del 05/11/2026)

- **Inicio:**
  - Arriba, un selector «¿Qué quieres resolver?» que lleva a cada producto.
  - Debajo, las tarjetas de producto con su precio, los planes que lo incluyen y los botones «Ver beneficios y precios» y «Probar».
  - Después vienen los planes con las siglas que incluye cada uno, y un submenú fijo para saltar entre secciones.
- **Páginas de producto convertidas en páginas de venta:**
  - Precio y botón de prueba en la cabecera.
  - Submenú fijo: Beneficios, Funciones, Precios, Cómo empezar y Preguntas.
  - Los planes que incluyen el producto, con el más económico resaltado.
  - El precio como producto individual.
  - Otros productos para combinar.
- **Precios:**
  - Ayuda para elegir el plan: dos preguntas y una recomendación.
  - Enlaces a cada producto desde la tabla, desde las siglas y desde los productos individuales.
- **Navegación:** menú en celular y nota de «diseño de referencia» en la barra superior.
