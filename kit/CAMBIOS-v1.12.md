# Cambios de la versión 1.12 (05/11/2026)

## 1. `CLAUDE.md` y los comandos de Claude Code, de vuelta

Al zip de la v1.11 le faltaban `CLAUDE.md` y `.claude/commands/` en la raíz. Sin ellos, Claude Code no carga las 15 reglas no negociables ni los comandos `/historia`, `/migracion`, `/endpoint`, `/pantalla` y `/revisar`. Se restauraron sin cambios desde la v1.10.

## 2. Marca: D-02 cerrada (ADR 0014)

- El producto se llama **GestiaHR**. El logo provisional es una «G» en un cuadrado azul #0A3A73, con un punto amarillo #F4B400 al centro, que representa a la persona en el centro. En el nombre, «HR» va en verde #0E7C6B.
- El logo está en el encabezado, el pie y la consola del sitio, en el prototipo (menú lateral, acceso, certificados y kiosko) y en el ícono de la pestaña. Reemplaza a la «G» de texto del sitio y a la «A» que quedaba en el prototipo.
- Archivos nuevos en `assets/img/` del sitio: `favicon.svg`, `apple-touch-icon.png`, `logo-gestiahr.svg`, `logo-gestiahr-blanco.svg` y `logo-gestiahr-512.png`.
- Las cinco capturas del sitio se volvieron a tomar del prototipo, ya con el logo nuevo.
- Una sola fuente: `tools/sitio/marca.py`. Si se encarga un logo profesional, se cambia ahí y se vuelve a ejecutar `gen_og.py`, `capturas.js` y `build.py`.
- Pendientes fuera del código: comprar gestiahr.com y registrar la marca ante INDECOPI.

## 3. Planes y complementos como datos iniciales (ADR 0013)

- Nueva sección `docs/01` §4.2 con los cuatro planes (Esencial, Profesional, Corporativo y Empresas): tope de colaboradores, módulos y funciones de cada uno. También están los cuatro complementos por producto: Tiempo (asistencia y hojas de tiempo), Planilla, Talento y Formación.
- `catalogos.py` lee esa tabla y `gen.py` la carga en `plans`, `plan_modules`, `plan_limits`, `plan_features`, `addons` y `addon_modules`. Los planes nacen en borrador y **sin monto**; el precio se fija en la consola.
- **Cambio de esquema**: la tabla nueva `addon_modules` guarda los módulos que habilita un complemento, porque Gestia Planilla, por ejemplo, enciende tres. `addons` pierde `module_code` y gana `web_product_code`. Ahora hay 495 tablas, 59 de ellas de plataforma.
- El generador rechaza un plan que rompa una dependencia entre módulos, por ejemplo Planilla sin vacaciones.
- `database/catalogos/modulos.json` trae las claves `planes` y `complementos`.
- `pruebas_aislamiento.sql` reconoce `addon_modules` como tabla de plataforma.
- **Precios del sitio**: se mantienen como ejemplo y marcados como tales (Esencial S/ 290, Profesional S/ 790 y Corporativo S/ 1 990 al mes; los complementos se cobran por colaborador). Se cambian en `PRECIO_PLAN` y `PRECIO_MODULO`, al inicio de `tools/sitio/build.py`.

## 4. SEO del sitio comercial (WEB-9)

- **Cada página indexable** tiene:
  - título y descripción propios, pensados para la búsqueda (por ejemplo, «Software de planilla con PLAME y boletas | Gestia Planilla»);
  - canónica con la ruta de producción (`https://gestiahr.com/planilla`);
  - Open Graph y tarjeta para redes, con una imagen por producto;
  - `lang="es-PE"`, ícono y color de tema.
- **Datos estructurados** (JSON-LD):
  - `Organization` y `WebSite` en el inicio;
  - `SoftwareApplication` con su oferta en cada producto;
  - `Product` con las ofertas de cada plan en precios;
  - `Article` en el artículo del blog;
  - `FAQPage` en toda página con preguntas frecuentes;
  - `BreadcrumbList` en todas las páginas indexables.
- **`sitemap.xml` y `robots.txt`**: las páginas de pasos, cuenta y confirmación llevan `noindex`, y la consola, la portada y el prototipo no se rastrean.
- **12 páginas nuevas**, todas con la misma plantilla:
  - un índice, `soluciones.html`;
  - ocho por proceso: legajo digital, vacaciones y récord vacacional, control de asistencia (próximamente, con lista de espera), boletas de pago electrónicas, cálculo de CTS, cálculo de gratificaciones, liquidación de beneficios sociales, y PLAME y T-Registro;
  - tres por industria: agroindustria, empresas de servicios y estudios contables.
- Cada página nueva enlaza a su producto y a sus páginas hermanas, y cada producto enlaza a sus procesos.
- El menú «Soluciones» y el pie llevan a las páginas nuevas.
- Las páginas nuevas no traen cifras legales: lo que depende de la norma remite al parámetro vigente que valida el especialista laboral.
- **Verificador** en `build.py`: el sitio no se genera si una página indexable no tiene canónica o datos estructurados, si repite el título de otra, si su título o su descripción tienen un largo fuera de rango, o si su JSON-LD no es válido.
- El sitio pasa de 29 a 40 pantallas.

## 5. Backlog

WEB-9 cita las páginas nuevas como diseño de referencia y suma un criterio: cada página de proceso o industria enlaza a su producto y a sus páginas hermanas. Los conteos no cambian: 72 épicas, 362 historias y 150 tareas; MVP 241, segunda ola 9 y sitio 13. El CSV de Jira sigue sin tiempos, puntos ni responsables.

## Qué se comprobó

- `generar_backlog.py`: todas las verificaciones pasaron. `gen_ruta.py` sin cambios en las tandas.
- `gen.py`: `schema.sql` se carga en PostgreSQL 16 y `pruebas_aislamiento.sql` termina en «TODAS LAS PRUEBAS PASARON». El kit pide PostgreSQL 17; la canalización de PLT-1 debe repetirlo en esa versión.
- `gen_docs.py`: los conteos de `docs/04` se leyeron de la base cargada.
- `gen_openapi.py`: OpenAPI válido, sin cambios en la API.
- `build.py` del sitio y del prototipo: sin errores de JavaScript y sin desborde horizontal en el celular.

## Archivos nuevos

- `CLAUDE.md` y `.claude/commands/` (restaurados).
- `docs/adr/0013-catalogo-inicial-de-planes.md` y `docs/adr/0014-nombre-y-marca.md`.
- `tools/sitio/marca.py`, `tools/sitio/gen_og.py`, `tools/sitio/capturas.js` y las imágenes nuevas en `tools/sitio/img/`.
- En `docs/web/sitio/`: las 12 páginas nuevas, `sitemap.xml` y `robots.txt`.
