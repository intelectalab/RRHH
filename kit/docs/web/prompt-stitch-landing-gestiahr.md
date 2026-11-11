# Prompt de Stitch: landing de GestiaHR (gestiahr.com)

Guardado para usarlo más adelante. Ya está incorporado al kit v1.9: el sitio es la épica WEB del backlog (WEB-1 a WEB-13, etiqueta `web`, pendiente y separada del MVP), los productos están en `docs/01` §4.1 y las decisiones abiertas son D-39 a D-41. Este archivo también va dentro del kit, en `kit/docs/web/`.

## Cómo usarlo

1. Abre [Stitch](https://stitch.withgoogle.com/) en modo web.
2. Pega el **Prompt principal** y espera el inicio en escritorio.
3. En el mismo proyecto, pega los **prompts de pantallas** uno por uno, para que Stitch mantenga el estilo.

Los prompts están en inglés porque Stitch los entiende mejor. Todos los textos de la página quedan en español de Perú.

## De dónde sale cada parte

- **De sell-saas.com:** la estructura, con hero y prueba social, productos en bloques alternados con maquetas de la interfaz, sección para partners con cifras, testimonios y doble cierre con chat. No se copian textos, marca ni imágenes.
- **De los otros SaaS (KipuControl, Granjero, KursivaPro, GestionCarnica):**
  - tabla de precios con interruptor mensual/anual y checkout en 3 pasos;
  - prueba gratis que crea la empresa en la app;
  - agendar demo;
  - blog SEO con filtros y comentarios moderados;
  - chatbot, popup de salida, popup de boletín y banner de cookies;
  - libro de reclamaciones;
  - versión móvil con barra inferior;
  - panel del superadmin para administrar el sitio.

**Precios:** el kit todavía no fija montos, así que las tarjetas muestran «S/ —» en el lugar del precio. Cuando finanzas defina los montos, se cambian en el prompt o directamente en el diseño.

---

## Prompt principal (inicio, escritorio 1440 px)

```
Design a professional, high-conversion SaaS marketing homepage for "GestiaHR" (gestiahr.com), an all-in-one HR and payroll platform for companies in Peru and Latin America: employee records (ficha 360), time and attendance, vacations, Peruvian payroll (planilla) with legal parameters, payslips (boletas) signed electronically, PLAME/T-Registro files, recruitment, onboarding, performance, learning, surveys, SST, compliance with SUNAFIL, people analytics, an employee app and WhatsApp self-service, and a built-in help center with an AI help assistant. All copy in Spanish (Peru), addressing the reader as "tú". Desktop 1440px, responsive-ready, max content width 1280px.

VISUAL IDENTITY — trustworthy, warm B2B HR software:
- Palette: GestiaHR navy #0A3A73 (logo, headings accents, dark sections), royal blue #11509A (links, hover), soft blue #E6F0FB (tinted panels), Gestia green #0E7C6B (primary CTA buttons "Prueba gratis" / "Suscribirme", "Más elegido" badge; white text on it), mint #E3F5EF (success tints), amber #F2A93B (small highlights and the "Nuevo" chip only), ink #15202E (text), slate #5B6678 (body), lines #E1E6EE, page background #F3F5F9, white cards.
- Typography: "Bricolage Grotesque" for H1/H2 (H1 60px Bold, tracking -0.02em; H2 40px), "Figtree" for body 17px/1.6 and UI, "JetBrains Mono" only for codes and numbers in mockups. Small uppercase kickers with wide tracking in royal blue.
- Shapes: cards 20px radius, pill buttons, 1px borders, soft shadows (0 12px 32px -6px rgba(21,32,46,.10)). Glass effect only for the sticky header and floating widgets.
- Imagery: realistic product UI mockups (HR dashboard, payroll run, payslip PDF, attendance map, mobile employee app, WhatsApp chat, help assistant panel) on soft gradient backdrops, plus a few warm photos of diverse Peruvian office, retail and plant teams. No stock clichés.
- Feel: Stripe/Linear polish, clear hierarchy, WCAG AA contrast, no gradient text.
- Product naming: every product always shows its name plus its acronym as a small pill badge in JetBrains Mono (e.g. "Gestia Tiempo" + [WFM]): Gestia Personas [HRIS], Gestia Tiempo [WFM], Gestia Planilla [PAY], Gestia Talento [TMS], Gestia Formación [LMS], Suite GestiaHR [HCM]. Each product has its own accent color for its icon and badge: HRIS navy #0A3A73, WFM teal #0E7C6B, PAY indigo #3B4FB8, TMS violet #7A3FB0, LMS amber #B86E0B, HCM dark navy with green border. Use the acronym in menus, product cards, product page heroes, breadcrumbs, the Suite map and pricing tables.

PAGE SECTIONS (top to bottom):
1. Top announcement bar (navy): "Nuevo: asistente de ayuda con IA en todos los planes Profesional y Corporativo →".
2. Sticky glass header: logo "GestiaHR" (navy rounded square with a stylized "G" made of two people shapes + wordmark); nav: Productos (mega-menu with 5 product cards, each with icon, name, one line and "Se contrata solo" chip where applies: Gestia Personas · HRIS, Gestia Tiempo · WFM, Gestia Planilla · PAY, Gestia Talento · TMS, Gestia Formación · LMS; footer link "Suite GestiaHR · HCM →"), Soluciones (Por tamaño, Por industria, Para estudios contables), Precios, Recursos (Blog, Calculadoras, Centro de ayuda, Novedades normativas), Partners; "Iniciar sesión" text link; outlined "Agendar demo"; green pill CTA "Prueba gratis".
3. Hero (white with soft blue radial glow): kicker pill "Planilla y RR. HH. hecho para el Perú"; H1 "Tu planilla y tu gente, en orden y sin Excel"; subtitle "Calcula la planilla con los parámetros legales al día, firma boletas en línea, controla asistencia y vacaciones, y deja que tu equipo resuelva sus dudas solo, con ayuda e IA."; 3 check bullets (14 días de prueba sin tarjeta · Importa desde tu sistema actual · Planilla en paralelo antes de cambiar); CTAs: green "Prueba gratis" + white outlined "Ver demo de 2 min" with play icon; under them a placeholder social-proof row (5 overlapping avatars + "Empresas en Perú ya gestionan su planilla con GestiaHR" — no numbers). Right side: large dashboard mockup (headcount, planilla del mes status "Simulada → Cerrada", vencimientos de contratos, obligaciones del mes) with two floating cards: "Boleta firmada · Octubre 2026" and "Planilla de noviembre: neto S/ 248 310 · 0 alertas".
4. Trust strip: "Confían en GestiaHR" + 8 grayscale placeholder client logos; next to it badges: "Ley 29733 de datos personales", "Datos aislados por empresa", "Cifrado y auditoría completa", "Parámetros legales con vigencia".
5. "Elige lo que necesitas o llévate la suite": 5 large clickable product cards (whole card is a link that opens the product page in the same window) with icon, product name, one line, 3 included features and "Ver producto →": Gestia Personas · HRIS (ficha 360, contratos, documentos, portal del colaborador), Gestia Tiempo · WFM (asistencia, turnos, vacaciones, ausencias, horas extra; chip "Se contrata solo"), Gestia Planilla · PAY (cálculo, boletas firmadas, PLAME, liquidaciones), Gestia Talento · TMS (selección, onboarding, desempeño, OKR; chip "Desempeño se contrata solo"), Gestia Formación · LMS (cursos, plan anual, competencias; chip "Se contrata solo"). A sixth wide card in navy: "Suite GestiaHR · HCM: todo junto, con clima, cumplimiento, SST, analítica, ayuda e IA" with button "Ver planes".
6. Alternating product blocks (6 blocks, mockup left/right alternating; each with kicker, H3, 4 check bullets and buttons "Probar gratis" + "Ver cómo funciona →"):
   a. "Planilla peruana sin sustos" — payroll run mockup with steps Incidencias → Cálculo → Simulación → Cierre, and a side card "Comparación con tu sistema anterior: 0 diferencias en 186 boletas".
   b. "Boletas que se explican solas" — payslip with a highlighted line "¿Por qué cambió mi neto? +S/ 120 por horas extra, −S/ 35 por AFP" and an e-signature stamp.
   c. "Nunca más una multa por olvido" — compliance board of monthly obligations (PLAME, CTS, gratificación, vacaciones vencidas) with status pills and a "Novedad normativa: nueva RMV desde 01/12" card.
   d. "Asistencia desde el celular" — mobile app clocking with geofence map, plus a shift calendar and overtime bank.
   e. "Tu equipo se atiende solo" — employee portal and WhatsApp chat: "Quiero mi boleta de octubre" → PDF sent; vacation request approved by the manager.
   f. "Ayuda e IA en cada pantalla" — help center panel with contextual guide, your company's own policies, and the AI help assistant answering "¿Cuántos días de vacaciones me quedan?" citing the source article; admin toggle "Asistente IA: activado · roles: todos".
7. "Cambia sin miedo" band (soft blue): 3 steps with icons: 1) Importa colaboradores y acumulados desde tu sistema o Excel, 2) Corre la planilla en paralelo y compara boleta por boleta, 3) Cierra tu primer mes con GestiaHR. Button "Ver el plan de migración".
8. Partners section, inspired by "built for partners" (navy background, white text): H2 "Para estudios contables y consultoras de RR. HH."; text "Administra la planilla de todos tus clientes desde un solo panel, con tu marca si quieres."; 3 big stat callouts as features, not numbers: "Un panel para todos tus clientes", "Tu marca y tu dominio", "Comisión por cliente referido"; a mockup of the multi-company accountant panel; buttons green "Quiero ser partner" + white outlined "Hablar con el equipo".
9. Lead-magnet band: "Calcula cuánto te cuesta un colaborador en el Perú" — small calculator card preview (sueldo, régimen, asignación familiar → costo mensual y anual con gratificaciones, CTS, EsSalud) with button "Usar la calculadora gratis".
10. PRICING (id="precios"): H2 "Planes que crecen con tu equipo"; monthly/annual toggle with green pill "Ahorra con el plan anual"; 3 plan cards + 1 contact card: Esencial (hasta 50 colaboradores: ficha, asistencia, vacaciones, portal), Profesional (hasta 200: + planilla, boletas, selección, desempeño, asistente IA de ayuda; highlighted with navy border and green "Más elegido" badge), Corporativo (hasta 1 000: todos los módulos, API, marca blanca, soporte prioritario), Empresas y estudios ("Hablar con ventas"). Show the price slot as "S/ —" with "por mes" below (prices are defined later). Each card: short description, users/admins included, 6 check features, CTA ("Suscribirme" green on Profesional, outlined on others). Below: add-on chips (WhatsApp para colaboradores, Firma electrónica adicional, Almacenamiento extra, Modo estudio contable) and a toggle "Comparar todas las funciones". Small print: "Precios más IGV. Cancela cuando quieras."
11. Testimonials: H2 "Equipos de RR. HH. que recuperaron su tiempo"; 6 cards with photo, name, role, company, city and a result chip ("cierra la planilla en 1 día", "0 multas SUNAFIL"). Mark them visually as sample content.
12. Blog preview: kicker "Recursos"; H2 "Aprende planilla y gestión de personas"; category filter pills (Todos, Planilla y beneficios, Legislación laboral, SUNAFIL, Gestión de personas, Tecnología e IA, Plantillas); 3 article cards with 16:9 image, category chip, title (e.g. "Cómo calcular la gratificación de diciembre 2026"), excerpt, author, date, minutes; link "Ver todo el blog →".
13. FAQ accordion (8 questions): ¿Necesito tarjeta para la prueba? · ¿Calcula la planilla según la ley peruana? · ¿Qué pasa cuando cambia una norma? · ¿Puedo importar desde mi sistema actual? · ¿Mis datos están seguros? · ¿El asistente IA ve los datos de mis colaboradores? · ¿Puedo usarlo como estudio contable para varios clientes? · ¿Puedo cambiar de plan?
14. Final CTA band (navy with subtle dot grid): "Deja el Excel este mes" + green "Prueba gratis" + white outlined "Chatea con nosotros"; trust row: soporte en español, novedades normativas al día, centro de ayuda 24/7.
15. Footer (dark navy): logo + tagline "Planilla y RR. HH. para el Perú" + address in Lima; columns Producto, Soluciones, Recursos (Blog, Calculadoras, Centro de ayuda, Novedades normativas, Estado del sistema), Empresa (Nosotros, Partners, Contacto, Trabaja con nosotros), Legal (Términos, Privacidad, Cookies, Libro de reclamaciones with its icon); newsletter field "Recibe las novedades laborales cada mes" + button; social icons (LinkedIn, Facebook, YouTube, TikTok); status dot "Todos los sistemas operativos".

FLOATING ELEMENTS (show them on the canvas):
- Chatbot launcher bottom-right: green circle with chat icon and notification dot, plus an open panel preview (header "Gestia · asistente", green online dot; quick replies "Ver planes", "Agendar demo", "¿Cómo migro mi planilla?", "Soy estudio contable"; input field; links "Hablar con una persona" and WhatsApp).
- Exit-intent popup (centered modal over dimmed page): "Antes de irte: llévate el calendario de obligaciones laborales 2027" with email field, green "Enviarme el calendario", text link "No, gracias", note "Te enviamos también novedades una vez al mes. Puedes darte de baja cuando quieras."
- Cookie banner bottom-left compact card with "Aceptar", "Rechazar", "Configurar".
```

---

## Prompts de pantallas (en el mismo proyecto)

### 0. Página de producto (plantilla para los 5 productos)

```
Same GestiaHR design system. Design the product page gestiahr.com/tiempo for "Gestia Tiempo · WFM" in Spanish (Peru); it is a template reused for Gestia Personas · HRIS (/personas), Gestia Planilla · PAY (/planilla), Gestia Talento · TMS (/talento) and Gestia Formación · LMS (/formacion), each with its own accent icon. Exception: on /personas there is no standalone box; the pricing area shows the 3 plan cards (Esencial highlighted as "Para empezar", Profesional, Corporativo) all with "Incluye Gestia Personas · HRIS" and what each plan adds, plus "Comparar todos los planes" to /precios. It opens in the same window from the homepage product cards. Product status: Gestia Personas · HRIS and Gestia Planilla · PAY are available at launch; Gestia Tiempo · WFM shows vacations and absences as available and attendance/shifts with a small amber "Próximamente" chip; Gestia Talento · TMS and Gestia Formación · LMS pages show a "Próximamente" banner, a waitlist form (nombre, correo, empresa, número de colaboradores) and no buy button. Breadcrumb "Productos / Gestia Tiempo · WFM"; H1 "Control de asistencia, turnos y vacaciones para tu empresa"; subtitle; CTAs green "Prueba Gestia Tiempo · WFM gratis" + outlined "Agendar demo"; hero mockup (mobile clocking with geofence + shift calendar). Sections: pain points → solution; 6 features with screenshots in alternating rows; "Cómo funciona" in 3 steps; "Cumple con la ley" chips (jornada máxima, horas extra, registro de asistencia, descanso vacacional); a pricing box "Precios de Gestia Tiempo · WFM" with two options side by side: "Gestia Tiempo · WFM solo" (price slot "S/ —" por colaborador al mes, button "Suscribirme") and "Incluido en la Suite" (plans Esencial, Profesional, Corporativo with check marks, button "Ver todos los planes" linking to /precios); "Se conecta con" chips (Gestia Planilla · PAY, relojes biométricos, WhatsApp); customer quote; FAQ (6 questions, FAQ schema ready); related products row with the other 4 product cards; final CTA. Show the chatbot launcher.
```

### 0B. Suite GestiaHR (todo junto)

```
Same GestiaHR design system. Design gestiahr.com/suite, the page that presents everything together. Hero navy "La Suite GestiaHR · HCM: todas tus áreas de RR. HH. en un solo sistema" with a composite mockup of the 5 products connected. A visual map of the 5 products (Personas at the center as the base, Tiempo, Planilla, Talento, Formación around it, plus Clima y comunicación, Cumplimiento y SST, Analítica, Ayuda e IA) with status chips "Disponible" / "Próximamente". Section "Por qué todo junto": un solo dato del colaborador, sin doble digitación, aprobaciones y reportes que cruzan módulos. Then the full plans block: monthly/annual toggle and the 3 plan cards + "Empresas y estudios" contact card (price slot "S/ —"). Under each plan card an expandable "Añadir módulos" list with checkboxes for the modules not included in that plan (e.g. on Esencial: Documentos y firma, Mesa de ayuda, Automatizaciones; on Profesional: Aprendizaje, Gastos, SST, Mesa de ayuda), each with "S/ — al mes" and a live subtotal; button "Suscribirme con estos módulos" carries the selection to checkout. Link "Comparar todas las funciones" to /precios, FAQ "¿Puedo agregar módulos después?" and final CTA.
```

### 1. Precios y comparación

```
Using the same GestiaHR design system, design the pricing page (gestiahr.com/precios) in Spanish (Peru). Hero "Elige el plan para tu empresa" with monthly/annual toggle and a tab switch "Suite completa · Productos individuales" (the second tab shows Gestia Tiempo · WFM, Gestia Talento · TMS/Desempeño and Gestia Formación · LMS as standalone cards with price slot "S/ —" and a note "Pásate a la Suite cuando quieras: te reconocemos lo pagado del mes"). A plan finder "¿Qué plan necesitas?" (sliders/inputs: número de colaboradores, número de administradores, módulos que necesitas como chips → recommended plan card). The 4 plan cards from the homepage with the price slot "S/ —". Full feature comparison table grouped by area (Personas, Tiempo, Planilla, Talento, Cultura, Cumplimiento, IA y ayuda, Plataforma) with sticky header showing plan names and CTAs. Add-ons section "Añade módulos a tu plan" with the same per-plan checkbox selector and live subtotal as /suite; modules marked "Próximamente" can be checked as "Avísame" instead of bought. Billing FAQ (comprobantes electrónicos, IGV, cambio de plan, cancelación, qué pasa con mis datos si me voy). Testimonials strip and final CTA. Show the chatbot launcher.
```

### 2. Prueba gratis y suscripción (checkout en 3 pasos)

```
Design the GestiaHR trial signup and subscription checkout in Spanish (Peru), same design system, with a progress bar of 3 steps:
1) "Tu cuenta": "Continuar con Google" or email; nombre, correo de trabajo, celular, contraseña.
2) "Tu empresa": RUC (with validation check and auto-filled razón social), nombre comercial, número de colaboradores, régimen laboral (General, MYPE pequeña, MYPE micro, Agrario), subdominio preview "miempresa.gestiahr.com", choice "Empezar con datos de ejemplo" or "Empezar en blanco".
3) "Tu plan": selected plan card editable, monthly/annual, add-on checkboxes, coupon field, and either "Empezar prueba de 14 días" (no card) or payment (card form, Yape option, billing data for factura). Right column sticky summary with subtotal, IGV 18 %, total and security badges.
Success screen: "¡Tu empresa está lista!" with a first-steps checklist (importar colaboradores, configurar planilla, invitar a tu equipo, activar el asistente de ayuda) and button "Ir a mi panel". Desktop and mobile versions.
```

### 3. Agendar demo

```
Same design system. Design "Agendar demo" for GestiaHR in 3 steps: 1) Qué necesitas (demo general, migrar mi planilla, soy estudio contable, plan para más de 1 000 colaboradores) and company size; 2) Elige día y hora: monthly calendar with free time slots as chips, specialist card with photo; 3) Tus datos (nombre, correo, WhatsApp, empresa, RUC opcional, canal: videollamada o llamada). Confirmation screen with "Añadir a mi calendario", "Reprogramar", "Cancelar" and a note about WhatsApp reminders 24 h and 1 h before.
```

### 4. Blog con filtros

```
Design the GestiaHR blog index (gestiahr.com/blog) in Spanish (Peru), same design system. H1 "Recursos de planilla y RR. HH.", search field, sticky filter bar with category pills and counts (Todos 42, Planilla y beneficios 12, Legislación laboral 9, SUNAFIL 6, Gestión de personas 8, Tecnología e IA 4, Plantillas 3) and a "Tipo" dropdown (Artículo, Guía, Plantilla descargable, Novedad normativa). Featured article large split card, 3-column grid of article cards (image, category chip, title, excerpt, author with "Especialista laboral" verified badge, date, minutes), pagination, desktop sidebar with newsletter box, popular posts, the cost calculator card and a "Prueba GestiaHR gratis" card. Inline banner after the 6th card. Empty state for no results. Mobile version with scrollable pills and a "Filtrar" bottom sheet.
```

### 5. Artículo con comentarios

```
Design a GestiaHR blog article page in Spanish (Peru), same design system. Title "Gratificación de diciembre 2026: cómo calcularla paso a paso", breadcrumb, category chip, "Actualizado el 05/11/2026" chip, author card (photo, role "Especialista laboral", verified), reading time, share buttons (WhatsApp, LinkedIn, Facebook, copiar enlace). Sticky table of contents on the left (desktop). Body with H2 sections, a worked example table, a "Base legal" callout, tip boxes, an inline CTA card "Calcula la gratificación de todo tu equipo en GestiaHR", FAQ, author bio, related articles. Comments: count "9 comentarios", sort (Más recientes / Más útiles), form (nombre, correo, comentario, privacy checkbox, "Publicar comentario", note "Los comentarios se revisan antes de publicarse"), threaded comments with "Responder" and "Útil (3)", and an "Equipo GestiaHR" badge on staff replies. Show the chatbot launcher and a slide-in newsletter card at 60 % scroll.
```

### 6. Página SEO de un módulo (plantilla para todos los módulos)

```
Design a product SEO landing page for GestiaHR in Spanish (Peru), same design system, URL gestiahr.com/software-de-planillas. It is a template reused for every module (control de asistencia, vacaciones, boletas electrónicas, reclutamiento, evaluación de desempeño, SST). Breadcrumb; H1 "Software de planillas para empresas en el Perú"; subtitle; CTAs; hero mockup. Sections: "Qué resuelve" (4 pain points → solution), feature grid with 6 items, a step-by-step "Cómo funciona" with screenshots, "Cumple con la ley" box listing regulations as chips (regímenes, CTS, gratificaciones, PLAME, T-Registro), comparison table "GestiaHR vs Excel vs sistema tradicional", a customer quote, related modules links, FAQ (6 questions, ready for FAQ schema), related blog posts and final CTA. Show the chatbot launcher.
```

### 7. Página SEO por industria o tamaño

```
Same design system. Design gestiahr.com/soluciones/retail (template reused for manufactura, agroexportación, servicios, salud, educación, MYPE). Hero "RR. HH. y planilla para retail con muchas tiendas y turnos rotativos" with industry photo; specific pains; the 4 most relevant modules as cards; a mini case study with result chips; regime notes (e.g. régimen agrario for agroexportación); FAQ; CTA "Agendar demo para retail".
```

### 8. Calculadora de costo laboral (imán de leads)

```
Same design system. Design gestiahr.com/calculadoras/costo-laboral in Spanish (Peru). Left: form with sueldo bruto, régimen laboral, asignación familiar toggle, sistema de pensiones (AFP / ONP), seguro (EsSalud / EPS), SCTR toggle. Right: result card with monthly and annual employer cost, breakdown bars (sueldo, gratificaciones, CTS, vacaciones, EsSalud, otros) and a disclaimer "Cálculo referencial con los parámetros vigentes al 05/11/2026". Button "Descargar el detalle en PDF" opens a small modal asking for email and company (lead form). Below: links to other calculators (gratificación, CTS, liquidación, vacaciones truncas, horas extra), related articles and CTA "Calcula la planilla de todo tu equipo".
```

### 9. Partners y estudios contables

```
Same design system. Design gestiahr.com/partners. Hero navy "Crece tu estudio con GestiaHR"; two partner types as cards: Estudios contables (administra la planilla de varios clientes con el modo estudio) and Consultoras y revendedores (marca blanca y comisión por referido). Mockup of the multi-company panel with client list and monthly payroll status. Benefits list, 3-step "Cómo empezar", partner testimonial (sample), FAQ and a form "Quiero ser partner" (nombre, estudio, RUC, número de clientes, ciudad, WhatsApp).
```

### 10. Iniciar sesión y recuperar acceso

```
Same design system. Design the login page app.gestiahr.com/ingresar: split layout with a product illustration on the left and a card on the right: "Continuar con Google", "Continuar con Microsoft", email + password, "¿Olvidaste tu contraseña?", "Ingresar con código por correo", and a field "Ir al espacio de mi empresa (miempresa.gestiahr.com)". Also design the 2-step verification screen (6-digit code) and the password reset screen. Link "¿No tienes cuenta? Prueba gratis".
```

### 11. Páginas legales y libro de reclamaciones

```
Same design system. Design a legal page template (Términos y condiciones, Política de privacidad, Política de cookies, Acuerdo de tratamiento de datos) with left sticky index, "Última actualización" date, readable body width 720px and a print/download PDF button. Also design the "Libro de reclamaciones virtual" form required in Peru: datos del consumidor, tipo (reclamo / queja), detalle, pedido, checkbox de conformidad, automatic code "RC-2026-000123" on submit and a confirmation screen with download button.
```

### 12. Versión móvil del inicio y chatbot abierto

```
Create the mobile version (390px) of the GestiaHR homepage with the same content: hamburger menu with full-screen drawer, stacked hero with full-width CTAs, module cards in 2 columns, product blocks stacked (mockup above text), pricing cards as a horizontal swipe carousel with the toggle on top, scrollable blog pills, FAQ accordion, sticky bottom bar with "Prueba gratis" and a chat button. Also show the chatbot opened as a full-screen sheet with a conversation: visitor "¿Calcula CTS y gratificación?" → answer with a short explanation, a link to the article and buttons "Ver planes" and "Agendar demo".
```

### 13. Panel del superadmin para el sitio web

```
Using the GestiaHR console style (left navy sidebar, white content, Figtree), design the "Sitio web" section of the GestiaHR platform console for the superadmin. Tabs: Resumen (visits, trials started, demos booked, conversion funnel visita → prueba → pago), Leads (table with source page, form, utm, status, owner, button "Pasar a CRM"), Planes publicados (read from the platform plans: name, limits, visible on the web toggle, price, "Más elegido" toggle), Blog (posts with status Borrador / En revisión / Publicado, author, category, SEO score), Comentarios (moderation queue with Aprobar / Rechazar), Popups (exit-intent, 60 % scroll, timed; rules: pages, device, frequency "máximo uno por visita, no repetir en 7 días"; on/off), Chatbot (on/off, greeting, quick replies, knowledge sources: help center, pricing, FAQ; handoff to WhatsApp or sales), SEO (sitemap status, redirects, default meta), and Prueba social (testimonials and logos with a "Publicar" toggle that requires a confirmation "Tengo el permiso del cliente").
```

---

## Versión corta en español (si prefieres escribir en español)

```
Landing de software SaaS «GestiaHR» (gestiahr.com): planilla y RR. HH. para empresas del Perú. Textos en español de Perú, tuteo. Colores: azul marino #0A3A73, azul #11509A, verde #0E7C6B para los botones principales, fondo #F3F5F9, tipografías Bricolage Grotesque (títulos) y Figtree (texto), tarjetas redondeadas y maquetas reales del software. Cada producto con su sigla en una pastilla junto al nombre: Gestia Personas (HRIS), Gestia Tiempo (WFM), Gestia Planilla (PAY), Gestia Talento (TMS), Gestia Formación (LMS) y Suite GestiaHR (HCM). Secciones: barra de anuncio, menú fijo con «Prueba gratis», hero con tablero de planilla, logos y sellos de confianza, 8 módulos, 6 bloques alternados (planilla, boletas que se explican, obligaciones del mes, asistencia móvil, autoservicio y WhatsApp, ayuda e IA), banda «Cambia sin miedo» con planilla en paralelo, sección para estudios contables, calculadora de costo laboral, precios con interruptor mensual/anual (Esencial, Profesional más elegido, Corporativo y Empresas, precio «S/ —»), testimonios, blog con filtro, preguntas frecuentes, CTA final y pie con libro de reclamaciones. Elementos flotantes: chatbot abajo a la derecha, popup de salida con el calendario de obligaciones y banner de cookies.
```

---

## Marca y productos

- Cada producto va con su sigla junto al nombre para distinguirlo: Gestia Personas · HRIS, Gestia Tiempo · WFM, Gestia Planilla · PAY, Gestia Talento · TMS, Gestia Formación · LMS y Suite GestiaHR · HCM. Las siglas son las categorías que usa el mercado y sirven también para el SEO.
- La marca y el dominio principal siguen siendo GestiaHR y gestiahr.com. gestiahcm.com, si se compra, solo redirige.
- La oferta se ordena en 5 productos según lo que el cliente necesita resolver: Personas, Tiempo, Planilla, Talento y Formación. Además está la Suite completa.
- Cada producto tiene su propia página, que abre en la misma ventana y también sirve para SEO. La página de precios es una sola.
- Tiempo, Desempeño y Formación se pueden contratar solos, para competir con los programas especializados.
- La página gestiahr.com/suite presenta todo junto y los planes. Cada plan permite añadir módulos que no trae incluidos, como en la consola (add-ons por plan).
- Gestia Personas es la base y viene en los tres planes. No se vende suelto.
- Al lanzamiento (MVP) salen Personas, vacaciones, ausencias y Planilla. Asistencia y turnos (fase 2), Talento (fase 4) y Formación (fase 5) se muestran con «Próximamente» y lista de espera, sin botón de compra.
- Formación es la épica LMS: la empresa sube sus propios cursos. No incluye una biblioteca de cursos ya hechos.
- Que cada empresa use su propio dominio es WLB-2 (fase 9). Conviene pasarla al MVP si se ofrece desde el lanzamiento.
- Equivalencias para el SEO: Personas = HRIS; Personas + Tiempo + Planilla = HRMS; Suite = HCM; Tiempo = WFM (tiempo y asistencia); Talento = TMS; Formación = LMS.

## Productos de la web y módulos del sistema (para desarrollo)

El sistema ya vende por módulos: `modules`, `module_dependencies`, `plans`, `plan_modules`, `plan_limits`, `plan_features`, `subscriptions`, `subscription_addons` y `tenant_modules`, con el catálogo de `database/catalogos/modulos.json` y las reglas de `docs/01` §7. Lo único que falta es la capa de «producto» de la web, que agrupa módulos. Entra en la épica WEB (v1.9) como un catálogo `products` / `product_modules`, que la web lee por una API pública:

| Producto (ruta) | Sigla | Módulos del sistema | Disponible |
| --- | --- | --- | --- |
| Personas (/personas) | HRIS | base, core | MVP |
| Tiempo (/tiempo) | WFM | time_off, attendance, timesheets | time_off en MVP; attendance y timesheets en fase 2 |
| Planilla (/planilla) | PAY | payroll, advances, contractors | payroll en MVP; advances y contractors en fase 3 |
| Talento (/talento) | TMS | recruiting, onboarding, performance, careers, compensation | fases 4 y 5 |
| Formación (/formacion) | LMS | learning | fase 5 |
| Resto de la Suite (/suite) | HCM | benefits, engagement, helpdesk, sst, grievances, assets, expenses, compliance, analytics, ai, integrations, whatsapp, white_label, partner | fases 5 a 9 |

La etiqueta «Disponible» o «Próximamente» sale de si el módulo está publicado en la consola, no se escribe a mano. Las dependencias siguen igual: Planilla exige Vacaciones y ausencias, y Carrera exige Desempeño.

## Lista de verificación al revisar lo que entrega Stitch

- [ ] Botón principal verde con texto blanco. El ámbar solo en detalles pequeños.
- [ ] Planes Esencial, Profesional (destacado) y Corporativo con los mismos límites del prototipo (50, 200 y 1 000 colaboradores) y el precio en «S/ —».
- [ ] El asistente de ayuda con IA aparece en Profesional y Corporativo, no en Esencial, igual que en la consola.
- [ ] Checkout con RUC, régimen laboral, subdominio y prueba de 14 días sin tarjeta.
- [ ] Blog con filtros, artículo con comentarios moderados y popup de boletín al 60 %.
- [ ] Cada producto muestra su sigla (HRIS, WFM, PAY, TMS, LMS, HCM) junto al nombre, con su color propio.
- [ ] Los 5 productos abren su página en la misma ventana, con el cuadro de precios «solo» o «incluido en la Suite».
- [ ] Páginas SEO por módulo y por industria con preguntas frecuentes y comparación.
- [ ] Calculadora de costo laboral con aviso de cálculo referencial.
- [ ] Chatbot, popup de salida y banner de cookies.
- [ ] Login, legales y libro de reclamaciones.
- [ ] Panel del superadmin con leads, popups, chatbot, blog y prueba social.
- [ ] Móvil sin desplazamiento horizontal y con la barra inferior «Prueba gratis».
- [ ] Testimonios, logos y cifras marcados como ejemplo. Ningún texto, logo o imagen de sell-saas.com.

## Lo que esto implica en el producto (ya en la épica WEB del kit v1.9)

El kit v1.8 ya cubre la consola SaaS, los planes y flags (SAS), el centro de ayuda (AYU) y el asistente IA (IAR). Esta landing suma piezas que todavía no están especificadas:

- el sitio público con CMS para páginas, blog con categorías, comentarios moderados y SEO (sitemap, datos estructurados, canónicas);
- el registro de prueba que crea la empresa en la plataforma con su subdominio;
- la suscripción con cobro recurrente y comprobante electrónico, enlazada a SAS-11;
- agendar demo y el formulario de partners;
- calculadoras públicas que leen los parámetros legales (PAR);
- popups con reglas y banner de cookies con consentimiento;
- el chatbot comercial, que responde con precios, preguntas frecuentes y artículos públicos;
- la sección «Sitio web» en la consola del superadmin.

Cuando el diseño esté aprobado se documentan como la épica WEB del backlog y se agrega la landing al prototipo.

## Pendientes de otros encargados antes de publicar

- **Finanzas:** montos de cada plan y si habrá descuento anual.
- **Legal:** términos, privacidad, cookies, acuerdo de tratamiento de datos y libro de reclamaciones con razón social, RUC y dirección.
- **Contenido laboral:** revisión de las calculadoras y de los primeros artículos del blog por el especialista.
- **Marketing:** testimonios, logos y cifras reales con permiso de cada cliente. INDECOPI puede pedir el sustento.
