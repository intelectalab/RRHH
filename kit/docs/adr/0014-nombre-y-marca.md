# 0014 · Nombre GestiaHR y logo provisional

- **Estado**: Aceptada
- **Fecha**: 2026-11-05
- **Decisión de origen**: D-02 en `../13-decisiones-abiertas.md`
- **Quién decide**: Maximo, dirección del producto

## Contexto

El nombre de trabajo era GestiaHR y el sitio y el prototipo usaban una letra suelta como logo. Hacía falta confirmar el nombre antes de publicar el dominio, los correos y el sitio, y tener un logo coherente con los tokens del sistema.

## Opciones consideradas

1. **Mantener GestiaHR.** Ya está en el kit, el sitio, el prototipo y el dominio previsto.
2. **Cambiar de nombre.** Obligaría a rehacer textos, dominios y materiales.

## Decisión

Opción 1: el producto se llama **GestiaHR** (dominio previsto gestiahr.com) y sus productos, «Gestia Personas», «Gestia Tiempo», etc. El logo provisional es una «G» en un cuadrado azul #0A3A73, con un punto amarillo #F4B400 al centro (la persona en el centro), y el nombre con «HR» en verde #0E7C6B.

## Consecuencias

- El logo vive en `kit/tools/sitio/marca.py`, que usan el sitio (`build.py`), las imágenes para redes (`gen_og.py`) y el prototipo.
- En el código del producto el nombre sigue viviendo en configuración y textos, nunca fijo en la lógica.
- Quedan pendientes, fuera del código: comprar el dominio, registrar la marca ante INDECOPI y, si se encarga un logo profesional, reemplazar `marca.py` y volver a generar.

## Comprobación

`python3 kit/tools/sitio/build.py` no deja ninguna página con la letra provisional: todas usan el logo de `marca.py`.
