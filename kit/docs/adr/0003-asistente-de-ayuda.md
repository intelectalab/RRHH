# 0003 · Asistente de ayuda documental: activación en tres niveles y búsqueda de texto completo

- **Estado**: Propuesta
- **Fecha**: 2026-11-04
- **Decisión de origen**: D-38 en `../13-decisiones-abiertas.md`
- **Quién decide**: dirección de producto y responsable de privacidad

## Contexto

Todos los roles necesitan ayuda dentro del producto (épica AYU) y el producto ofrece un asistente de IA que responde dudas de uso. El asistente debe poder encenderse y apagarse desde la administración, cumplir el gobierno de IA de `docs/12` §13 (C-08) y no adelantar al MVP el riesgo de privacidad de una IA que lee datos de personas.

## Opciones consideradas

1. Asistente con acceso a datos desde el MVP: responde «¿cuántos días me quedan?», pero exige herramientas tipadas, pruebas de permisos y evaluación de impacto antes del primer cliente.
2. Asistente documental en el MVP y datos del propio usuario después (IAR-12): responde solo con la ayuda del producto y los artículos de la organización que el usuario puede ver.
3. Sin asistente en el MVP: el centro de ayuda basta, pero se pierde un diferenciador barato de construir sobre la ayuda que de todos modos se escribe.

## Decisión

Opción 2. El asistente se controla en tres niveles: GestiaHR lo habilita por plan con la función `ayuda.asistente_ia` (SAS-7); el administrador de la organización lo enciende o apaga y elige roles y límite de preguntas (`ai_use_case_settings`, `ai_use_case_roles`); el usuario lo ve solo si su rol está habilitado. Nace apagado. Los artículos se recuperan con búsqueda de texto completo de PostgreSQL en español (`search_vector` con índice GIN) y el modelo recibe solo los artículos recuperados y la pregunta.

## Consecuencias

- El centro de ayuda (AYU) funciona sin IA y es el respaldo cuando el asistente está apagado, sin cupo o sin proveedor.
- Cada pregunta queda en `ai_interactions` con el caso de uso `help_assistant`; las búsquedas sin resultado, en `help_search_queries` sin identificar a la persona.
- La calidad se mide con IAR-11 (preguntas de referencia por rol). Si muchas preguntas con artículo quedan sin fuente, se evalúa agregar vectores (`pgvector`) en otro ADR.
- IAR-12 (datos del propio usuario) es un caso de uso distinto que se activa por separado.

## Comprobación

- Prueba de API: con el caso de uso apagado o el rol no habilitado, `POST /ai/help-assistant/ask` responde 404 y `GET /ai/help-assistant/availability` indica no disponible.
- Prueba de IAR-9: las herramientas del asistente documental no incluyen ninguna consulta a tablas de personas.
- Prueba de IAR-11: una respuesta que cita un artículo no visible para el rol hace fallar la integración continua.
