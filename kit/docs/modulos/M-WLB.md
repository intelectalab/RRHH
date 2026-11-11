# M-WLB · Marca blanca

Fase 9 · Ecosistema y expansión · Épica `WLB` · Reglas `RN-WLB-nn` · Módulo comercial: Marca blanca (`white_label`)

## 1. Objetivo

Permitir que cada organización presente la plataforma con su identidad, según su plan.

## 2. Alcance

- Logo, nombre visible, color principal, favicon, pantalla de ingreso, correos y documentos con marca.
- Dominio personalizado y remitente de correo propio.
- Portal de empleos y canal de denuncias con la marca de la empresa.
- Marca por empresa dentro de un grupo.

## 3. Fuera de alcance

- Cambios de estructura de la interfaz. Aplicaciones móviles con nombre propio en tiendas.

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §83 Personalización / white label.

**Correcciones que incorpora:** ninguna específica (aplican las reglas transversales de docs/00-especificacion-maestra.md).

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Administrador empresa. Superadmin habilita según plan.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `settings.branding.view` | Ver la marca de la organización | Administrador empresa |
| `settings.branding.update` | Editar la marca de la organización | Administrador empresa |
| `settings.branding.manage_domain` **(+)** | Configurar dominio y remitente propios | Administrador empresa |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Configuración.** 1) Se suben logo y favicon y se elige el color. 2) El sistema valida contraste y genera las variantes clara y oscura. 3) Vista previa. 4) Se publica. 5) Para dominio propio se muestran los registros a crear y se verifica.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Marca | Logo, nombre, color, favicon, vista previa en claro y oscuro, correos | Prototipo: Configuración → Centro de configuración → sección Marca de la organización |
| Dominio | Dominio y remitente con estado de verificación | Prototipo: Configuración → Marca · Dominio |

## 8. Datos principales

Configuración de marca por organización y empresa, dominios y su verificación, plantillas de correo con marca.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección WLB.

- **RN-WLB-01**: Si el plan no incluye marca blanca → solo se permite logo y nombre.
- **RN-WLB-02** (parámetro): Si el color elegido no cumple el contraste mínimo de accesibilidad (parámetro) → el sistema lo ajusta o lo rechaza con explicación.
- **RN-WLB-03**: Si el dominio propio no está verificado → no se activa y se sigue usando el dominio de la plataforma.
- **RN-WLB-04**: Si se cambia la marca → los documentos ya emitidos conservan la marca con la que se generaron.
- **RN-WLB-05**: Si hay varias empresas con marca propia → cada documento y correo usa la marca de la empresa de la relación.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `branding.updated`, `domain.verified`.

**Consume:**

Ninguno.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Logo solo apto para fondo claro.
- Caducidad del certificado del dominio.

## 14. Criterios de aceptación generales

- Logo, nombre, color, favicon, dominio y correos se personalizan según el plan, en tema claro y oscuro.
