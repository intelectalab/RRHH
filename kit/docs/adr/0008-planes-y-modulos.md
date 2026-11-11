# 0008 · Planes con módulos incluidos, más complementos

- **Estado**: Aceptada
- **Fecha**: 2026-11-05
- **Decisión de origen**: D-01 en `../13-decisiones-abiertas.md`
- **Quién decide**: Maximo, dirección del producto, aceptando la recomendación del kit

## Contexto

La arquitectura trata los planes, los módulos, los límites y los complementos como datos de la consola (`docs/01` §4). El sitio comercial ya muestra cuatro planes y complementos (`docs/01` §4.1). Faltaba confirmar el modelo comercial y qué módulos van siempre incluidos.

## Opciones consideradas

1. **Planes cerrados por tamaño.** Son fáciles de explicar, pero no permiten sumar un módulo suelto.
2. **Base más módulos sueltos.** Es flexible, pero cuesta comparar y vender.
3. **Planes con módulos incluidos y complementos.** Cada plan trae un conjunto de módulos y un tope de colaboradores, y a cualquier plan se le suman módulos.

## Decisión

Opción 3. «Plataforma base» (`base`) y «Core HR» (`core`) van siempre incluidos. La composición inicial es la que publica el sitio:

| Plan | Tope de colaboradores | Incluye |
|---|---|---|
| Esencial | 50 | Personas y vacaciones, sin Planilla |
| Profesional | 200 | Lo anterior, más Planilla, boletas firmadas y el asistente de ayuda |
| Corporativo | 1 000 | Todos los módulos disponibles |
| Empresas | A la medida | Lo que se acuerde con el cliente |

## Consecuencias

- Los planes, sus módulos, topes y complementos se cargan como datos en la consola (épica SAS). Ningún plan está escrito en el código.
- Los montos no van en el kit: se fijan en la consola y el sitio los lee desde `/public/catalog`. Mientras no estén definidos, el material muestra «S/ —».
- Las dependencias entre módulos de `docs/01` §4 se validan al armar un plan o sumar un complemento. Por ejemplo, Planilla exige vacaciones.

## Comprobación

Una prueba de la consola rechaza un plan que incluya `payroll` sin `time_off`. Otra prueba verifica que apagar un módulo devuelve `module_not_enabled` en su API.
