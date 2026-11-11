# Sitio comercial gestiahr.com · diseño de referencia

Abre `portada.html` en el navegador: lista las 47 pantallas y desde ahí se navega como un visitante. «Iniciar sesión» e «Ir a mi panel» abren el prototipo del sistema (`sistema.html`).

- **Estilos:** `assets/site.css`, con los mismos tokens del sistema (`docs/10`) y la tipografía IBM Plex Sans.
- **Capturas:** `assets/img/` son pantallas reales del prototipo con datos de demostración.
- **Marca:** el logo provisional (ADR 0014) sale de `kit/tools/sitio/marca.py`; las imágenes para redes y el ícono, de `gen_og.py`; las capturas del prototipo, de `capturas.js`.
- **SEO:** cada página indexable tiene título, descripción, canónica, Open Graph y datos estructurados; el sitio incluye `sitemap.xml` y `robots.txt`. Las páginas de pasos, cuenta y confirmación llevan `noindex`. Las rutas canónicas son las de producción (`https://gestiahr.com/planilla`, sin `.html`).
- **Generador:** las páginas salen de `kit/tools/sitio/build.py`. Para cambiar un texto o una página, edita el generador y vuelve a ejecutarlo.
- **Implementación:** es una referencia visual y de contenido. La implementación real está en las historias WEB-1 a WEB-13; ver el prompt 8 de `kit/PROMPTS.md`.
- **Datos por definir:**
  - precios: son de ejemplo y se cambian en `PRECIO_PLAN` y `PRECIO_MODULO` al inicio de `build.py`; en el producto viven en la consola (ADR 0008);
  - razón social y RUC;
  - logos y testimonios (marcados como ejemplo);
  - páginas legales: el texto está completo (términos, privacidad, cookies y acuerdo de tratamiento de datos); los datos de la empresa van como [por definir] y lo que debe confirmar el abogado como [validar]. Se editan en `kit/tools/sitio/legales.py`;
  - blog: seis artículos en `kit/tools/sitio/blog.py`; las cifras legales van marcadas [validar];
  - cálculo de la calculadora (referencial, lo valida el especialista laboral).
