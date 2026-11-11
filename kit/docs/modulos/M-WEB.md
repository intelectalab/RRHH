# M-WEB · Sitio comercial gestiahr.com

Fase 0 · Cimientos · Épica `WEB` · Reglas `RN-WEB-nn` · Módulo comercial: transversal

## 1. Objetivo

Vender GestiaHR desde su propio sitio: presentar los productos, mostrar planes y precios que salen de la consola, convertir visitas en pruebas, demos y suscripciones, y atraer tráfico con blog y SEO, sin tocar datos de las organizaciones.

## 2. Alcance

- Aplicación `apps/site` con CMS propio (páginas, blog, preguntas frecuentes, testimonios, páginas legales y ajustes), separada de la aplicación y de la consola.
- Catálogo de productos (Personas, Tiempo, Planilla, Talento, Formación y Suite) ligado a los módulos comerciales (`docs/01` §4.1) y publicado en `/public/catalog` con planes, límites y complementos.
- Inicio, una página por producto, página de la Suite y página de precios con comparador y selector de módulos.
- Registro de prueba y suscripción en tres pasos que crea la organización con su subdominio; agendar demo, contacto, partners y lista de espera.
- Blog con categorías, boletín y comentarios moderados; páginas SEO por módulo e industria; sitemap y datos estructurados.
- Chatbot comercial, popups, banner de cookies, páginas legales y libro de reclamaciones virtual.
- Sección «Sitio web» en la consola para leads, demos, lista de espera, popups, chatbot, comentarios, reclamos y prueba social.

## 3. Fuera de alcance

- Calculadoras públicas de costo laboral: son PRV-5 (ola 2); el sitio solo las aloja y las enlaza.
- Dominio propio por organización (WLB-2) y marca blanca: siguen en M-WLB.
- Cobro y comprobantes: el sitio usa los adaptadores de SAS-11; no tiene pasarela propia.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** ninguno de forma directa; es una ampliación o un cimiento exigido por las correcciones.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Visitantes anónimos, empresas que se registran y partners. Superadministrador y equipo de la plataforma (ventas, contenido) administran el sitio desde la consola y el CMS.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `platform.web_products.view` | Ver productos de la web | Superadmin SaaS |
| `platform.web_products.hide` | Ocultar productos de la web | Superadmin SaaS |
| `platform.web_products.publish` | Publicar productos de la web | Superadmin SaaS |
| `platform.web_products.update` | Editar productos de la web | Superadmin SaaS |
| `platform.web_leads.view` | Ver leads del sitio | Superadmin SaaS |
| `platform.web_leads.assign` | Asignar leads del sitio | Superadmin SaaS |
| `platform.web_leads.convert` | Convertir leads del sitio | Superadmin SaaS |
| `platform.web_leads.discard` | Descartar leads del sitio | Superadmin SaaS |
| `platform.web_leads.update` | Editar leads del sitio | Superadmin SaaS |
| `platform.web_demo_bookings.view` | Ver demos agendadas desde el sitio | Superadmin SaaS |
| `platform.web_demo_bookings.cancel` | Cancelar demos agendadas desde el sitio | Superadmin SaaS |
| `platform.web_demo_bookings.complete` | Completar demos agendadas desde el sitio | Superadmin SaaS |
| `platform.web_demo_bookings.update` | Editar demos agendadas desde el sitio | Superadmin SaaS |
| `platform.web_complaints.view` | Ver libro de reclamaciones del sitio | Superadmin SaaS |
| `platform.web_complaints.answer` | Responder libro de reclamaciones del sitio | Superadmin SaaS |
| `platform.web_complaints.close` | Cerrar libro de reclamaciones del sitio | Superadmin SaaS |
| `platform.web_settings.view` | Ver ajustes del sitio (chatbot, popups, cookies y prueba social) | Superadmin SaaS |
| `platform.web_settings.update` | Editar ajustes del sitio (chatbot, popups, cookies y prueba social) | Superadmin SaaS |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Registro de prueba.** 1) La persona elige plan y módulos en precios o en una página de producto. 2) Crea su cuenta con correo o Google. 3) Registra la empresa con RUC, régimen laboral y subdominio. 4) Elige prueba sin tarjeta o paga. 5) Se crea la organización como en la consola y entra al asistente de primeros pasos.

**Demo.** 1) La persona elige qué necesita y el horario libre. 2) Deja sus datos con consentimiento. 3) Recibe confirmación y recordatorios. 4) El lead aparece en la consola con su origen.

**Lista de espera.** 1) La persona pide aviso de una parte en «Próximamente». 2) Cuando el módulo se publica en la consola, recibe el aviso con el enlace al producto.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Inicio | Hero, productos, bloques alternados, partners, precios resumidos, testimonios, blog, preguntas frecuentes y cierre | Sin prototipo (pantalla nueva). Ubicación prevista: Sitio web → Inicio |
| Página de producto | Qué resuelve, funciones, planes que lo incluyen, precio individual si se vende solo, preguntas frecuentes | Sin prototipo (pantalla nueva). Ubicación prevista: Sitio web → Producto |
| Precios | Interruptor mensual o anual, productos individuales, buscador de plan, comparador y selector de módulos | Sin prototipo (pantalla nueva). Ubicación prevista: Sitio web → Precios |
| Registro | Tres pasos: cuenta, empresa y plan; pantalla de bienvenida | Sin prototipo (pantalla nueva). Ubicación prevista: Sitio web → Registro |
| Sitio web (consola) | Embudo, leads, demos, lista de espera, popups, chatbot, comentarios, reclamos y prueba social | Sin prototipo (pantalla nueva). Ubicación prevista: Consola de la plataforma → Sitio web |

## 8. Datos principales

Productos de la web y sus módulos (`web_products`, `web_product_modules`), leads (`web_leads`), demos (`web_demo_bookings`), registros en curso (`web_signups`), conversaciones del chatbot (`web_chat_sessions`) y libro de reclamaciones (`web_complaints`). Páginas, blog, comentarios, popups y testimonios viven en el CMS del sitio.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección WEB.

- **RN-WEB-01**: Si el sitio muestra un plan, un precio, un límite o un complemento → lo lee del catálogo público de la consola; no hay montos escritos en el sitio.
- **RN-WEB-02**: Si un módulo de un producto no está publicado en la consola → esa parte se muestra «Próximamente», con lista de espera y sin botón de compra.
- **RN-WEB-03**: Si un producto es Personas → no se vende suelto: se ofrece dentro de los planes, porque `core` va en todos.
- **RN-WEB-04**: Si se elige un módulo que exige otro → se agrega el requisito o se avisa antes de pagar, según `module_dependencies`.
- **RN-WEB-05**: Si alguien se registra desde el sitio → la organización se crea con el mismo servicio que la consola, con su primera empresa, el subdominio, los módulos del plan y un usuario administrador.
- **RN-WEB-06**: Si el RUC no pasa el dígito verificador o el RUC o el subdominio ya están en uso → se rechaza el registro antes de crear nada.
- **RN-WEB-07** (parámetro): Si un registro queda a medias → se guarda el paso alcanzado como lead y se borra según la política de retención (parámetro).
- **RN-WEB-08**: Si se publica un módulo con lista de espera → se avisa una sola vez a cada inscrito.
- **RN-WEB-09**: Si se envía un formulario del sitio → se guarda el consentimiento con su finalidad, la página de origen y la campaña.
- **RN-WEB-10**: Si el chatbot responde → solo usa el catálogo público, las preguntas frecuentes y los artículos publicados; nunca datos de organizaciones.
- **RN-WEB-11**: Si la persona no aceptó las cookies de medición → no se carga ninguna etiqueta de medición ni de publicidad.
- **RN-WEB-12**: Si se publica un testimonio, un logo o una cifra → debe constar que se tiene el permiso del cliente.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `web.lead_created`, `web.signup_completed`, `web.demo_booked`, `web.waitlist_joined`, `web.complaint_registered`.

**Consume:**

- `tenant.created` (cierra el registro), `module.published` (dispara la lista de espera), `plan.updated` (refresca el catálogo).

## 12. Integraciones

CMS del sitio, adaptadores de cobro y facturación de SAS-11, correo (M-NOT), capa de proveedor de IA (M-IAR) para el chatbot, medición web con consentimiento.

## 13. Casos borde

- Persona que se registra con un RUC de una organización existente: se ofrece pedir acceso al administrador en lugar de crear otra organización.
- Precio cambiado en la consola mientras alguien está en el pago: se muestra el nuevo monto y se pide confirmar.
- Producto con todos sus módulos en «Próximamente»: su página existe para SEO y solo ofrece lista de espera.
- Visitante que vuelve después de rechazar cookies: no se le vuelve a preguntar hasta el periodo configurado.

## 14. Criterios de aceptación generales

- Cada producto abre su página en la misma ventana y sus precios coinciden con la consola.
- Una empresa se registra desde el sitio y entra a su organización sin intervención del equipo.
- Las partes que no están publicadas no se pueden comprar.
