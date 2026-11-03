# ADR 0012 · UI-diseño: Sistema visual aprobado y arquitectura de navegación

- **Fecha:** 2026-11-02
- **Estado:** Aceptada (provisional, a confirmar por el negocio)
- **Decisión:** UI-diseño

## Contexto

El prototipo de referencia (`core.html` y módulos) utilizaba una propuesta visual preliminar caracterizada por una barra lateral oscura fija, tipografías Bricolage Grotesque y Figtree, y paleta azul primario (#0A3A73).

La organización cuenta con un diseño visual aprobado y validado en producción previa (`C:\xampp\htdocs\aynihr\assets\css\` e `includes/`), cuya identidad se basa en tonos petróleo andino, ocre de acento, tipografía Nunito Sans / Segoe UI, y una navegación horizontal de doble barra superior sin barra lateral.

## Opción elegida

Reemplazar completamente el estilo visual del prototipo por el **diseño aprobado de la versión anterior**:
1. **Tokens visuales:**
   - Paleta de marca: Petróleo principal (`--pe-800: #0B4350`, `--pe-700: #0E4F5C`, `--pe-600: #125F6E`, `--pe-500: #17788A`, `--pe-200: #B7D9E0`, `--pe-100: #D5EAEE`, `--pe-50: #EEF6F7`) y ocre andino de acento puntual (`--oc: #C98A1B`, `--oc-600: #A9720F`, `--oc-50: #FBF1DC`).
   - Estados con alto contraste: Éxito (`--ok: #276B42`), Advertencia (`--wa: #8E5510`), Error (`--er: #A8321F`), Información (`--in: #245B91`), siempre acompañados de texto o ícono.
   - Tipografía institucional: Nunito Sans (con respaldo en Segoe UI, Arial, sans-serif).
   - Forma, radios y sombras: radios sutiles (`3px`, `4px`, `6px`, `10px`), sombras de elevación progresiva y curvas de animación `cubic-bezier(.23,1,.32,1)`.
   - Soporte para tema oscuro calculado rigurosamente para garantizar contraste WCAG AA (mínimo 4.5:1 para texto normal y 3:1 para elementos interactivos).
2. **Estructura de navegación:**
   - **Barra superior (`topbar`, 56px):** Logo AyniHR, selector de razón social/empresa activa, buscador global con atajo (`/`), botón de acción rápida «+ Nuevo», botón de ayuda contextual, centro de notificaciones con contador y menú desplegable de perfil de usuario.
   - **Barra horizontal de módulos (`modbar`, 44px):** Ubicada inmediatamente debajo de la barra superior con fondo petróleo oscuro (`--pe-700`), enlaces horizontales con íconos Phosphor, badges de pendientes y línea inferior indicadora de módulo activo en ocre (`--oc`).
   - **Sin barra lateral (sidebar):** Se elimina la barra lateral vertical del prototipo en favor del ancho completo para tablas complejas de nómina, fichas 360 y calendarios.

## Consecuencias

- **Positivas:**
  - Identidad visual coherente, sobria y profesional adecuada para plataformas empresariales de gestión de personas y nómina.
  - Mayor espacio horizontal útil para pantallas con alta densidad de datos (asistencia, cálculo de planilla, matrices de evaluación).
  - Experiencia de navegación ágil y accesible con atajos de teclado y cumplimiento WCAG AA.
- **Negativas / Compromisos:**
  - Se debe actualizar la documentación de tokens y pantallas en `docs/10-pantallas-y-ux.md` y corregir las referencias de `docs/15-que-no-copiar-del-prototipo.md`.
  - Los componentes de `apps/web/src/ui` se construirán a partir de estos tokens.

