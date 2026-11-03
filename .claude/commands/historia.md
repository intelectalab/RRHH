---
description: Implementa una historia del backlog de punta a punta (migración, dominio, API, pruebas, pantalla)
argument-hint: <código de historia, p. ej. VAC-3>
---

Implementa la historia **$ARGUMENTS** de Ayni HR.

1. **Lee** (sin escribir código aún): la historia en `backlog/backlog.md` (por código); estados, reglas y permisos en `docs/05`, `docs/06` y `docs/07`; norma peruana en `docs/14` si aplica; la especificación del módulo en `docs/modulos/`; las tablas implicadas en `database/schema.sql`; las rutas en `api/openapi.yaml` (busca con `grep -n`); las reglas de `CLAUDE.md`.
2. **Comprueba dependencias**: si la historia depende de otra no implementada o de una decisión abierta de `docs/13-decisiones-abiertas.md`, detente y dilo.
3. **Escribe un plan corto** (máximo 12 líneas): tablas, casos de uso, endpoints, permisos, eventos, pantallas, pruebas. Continúa sin pedir confirmación salvo que haya una decisión abierta.
4. **Implementa en este orden**: migración (con `tenant_id`, RLS, FK compuestas, índices) → dominio (entidad, máquina de estados, eventos) → caso de uso (transacción + auditoría + outbox) → endpoint (FormRequest, Policy, Resource) → pruebas → pantalla.
5. **Pruebas mínimas**: caso feliz; 403 sin permiso; 403 con módulo apagado; 404 desde otra organización; 422 de validación; 409 por transición inválida (si hay estados); auditoría con antes y después; evento en outbox; campo sensible ausente sin permiso por campo. Si toca planilla: caso dorado.
6. **Contrato**: si cambió la API, edita `tools/gen_openapi.py`, ejecuta `make openapi contracts`.
7. **Verifica**: `make test lint`. Corrige hasta verde.
8. **Informa**: criterios de aceptación cumplidos (uno por uno), archivos tocados, pruebas añadidas, y lo que quedó fuera con su motivo.

No añadas funcionalidad que la historia no pide. No pongas números legales en código: son parámetros con vigencia.
