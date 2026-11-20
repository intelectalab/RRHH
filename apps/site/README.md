# apps/site · Sitio comercial gestiahr.com

Aplicación Next.js aparte de la del cliente (`apps/web`) y de la consola (WEB-1). Solo lee la API pública de la plataforma:

- `GET /api/v1/public/catalog`: productos, planes públicos, complementos, módulos a la venta e impuesto vigente (WEB-2).
- `GET /api/v1/public/quote`: subtotal, impuesto y total de un plan con complementos (WEB-4). El navegador lo pide por `/api/quote` del propio sitio.

No hay montos escritos en el sitio (RN-WEB-01): un plan sin precio cargado en la consola se muestra «Consultar».

## Contenido (CMS)

Los textos de las páginas, las funciones de cada producto y las preguntas frecuentes están versionados en `src/content/{es,en,pt}.json`, con las mismas claves en los tres idiomas (hay prueba). El español va sin prefijo (`/planilla`); el inglés y el portugués, con `/en` y `/pt`.

## Variables de entorno

| Variable | Uso |
|---|---|
| `SITE_API_URL` | Base de la API (por defecto `http://localhost:8000/api`). |
| `SITE_ENV` | Solo `production` se indexa; cualquier otro valor responde `noindex` y `robots.txt` lo prohíbe todo. |
| `SITE_URL` | URL canónica (por defecto `https://gestiahr.com`). |

## Comandos

`make site` (puerto 3001) · `make lint-site` · `make test-site` · dentro de la carpeta: `npm run dev | build | lint | typecheck | test`.
