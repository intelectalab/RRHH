# M-PAI · Paquetes de país

Fase 9 · Ecosistema y expansión · Épica `PAI` · Reglas `RN-PAI-nn` · Módulo comercial: transversal

## 1. Objetivo

Separar todo lo específico de un país en un paquete instalable, de modo que sumar Colombia, Chile o México no toque el núcleo.

## 2. Alcance

- Contrato del paquete: regímenes laborales, conceptos de planilla y fórmulas, parámetros legales, feriados, tipos de documento de identidad, tipos de contrato y topes, políticas de vacaciones y ausencias por defecto, plantillas de documentos, declaraciones y formatos de archivo, obligaciones de cumplimiento, registros SST, validaciones de datos, textos y traducciones.
- Paquete Perú (detalle en `14-localizacion-peru.md`).
- Versionado y publicación; activación por empresa según su país; pruebas de regresión con casos de referencia por paquete.

## 3. Fuera de alcance

- Asesoría legal del país. Contenido de paquetes distintos de Perú (se construyen cuando se aborde cada país).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §28 Localización Perú; §82 Localización por país; §124 Objetivo final.

**Correcciones que incorpora:** C-05 Planilla = motor + reglas + país + régimen.

**Ampliaciones que incorpora:** E-27 Puesta en marcha guiada.

## 5. Actores y permisos

- Equipo de cumplimiento de la plataforma construye y publica. Administrador de la organización activa.

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `platform.country_packs.view` | Ver paquetes de país | Superadmin SaaS, Administrador empresa, Payroll, Auditor |
| `platform.country_packs.activate` | Activar paquetes de país | Superadmin SaaS, Administrador empresa |
| `platform.country_packs.publish` **(+)** | Construir, versionar y publicar paquetes de país | Superadmin SaaS |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Activación.** 1) Se crea una empresa con su país. 2) Se activa el paquete. 3) Se siembran catálogos, conceptos, políticas y obligaciones. 4) La empresa ajusta lo permitido.

**Actualización.** 1) La plataforma publica una versión con sus cambios y vigencias. 2) Las organizaciones reciben el aviso. 3) Lo legal se aplica solo; lo opcional (plantillas nuevas) se ofrece.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Paquete de país | Versión activa, contenido, novedades, elementos ajustados por la organización | Prototipo: Nómina → Configuración de nómina → pestaña Paquetes de país |
| Consola de paquetes | Construcción, casos de regresión, publicación | Prototipo: Nómina → Configuración de nómina → pestaña Paquetes de país |

## 8. Datos principales

Paquetes, versiones, elementos del paquete por tipo, activaciones por empresa, ajustes locales, casos de regresión.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección PAI.

- **RN-PAI-01**: Si una regla depende del país → vive en el paquete como dato (parámetro, fórmula, plantilla, obligación) o como extensión declarada del paquete; el núcleo no contiene condiciones por país.
- **RN-PAI-02**: Si una empresa tiene un país → usa el paquete de ese país; empresas de países distintos conviven en la misma organización.
- **RN-PAI-03**: Si se publica una versión del paquete → los periodos cerrados no cambian; los cambios rigen desde su vigencia.
- **RN-PAI-04**: Si la organización ajustó un elemento del paquete → una actualización no lo pisa; se muestra la diferencia y la organización decide.
- **RN-PAI-05**: Si un paquete se publica → debe pasar sus casos de regresión de cálculo.
- **RN-PAI-06**: Si no existe paquete para un país → la empresa puede usar Core HR y los módulos no legales, y la nómina queda no disponible con mensaje claro.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `country_pack.published`, `country_pack.activated`.

**Consume:**

- `company.created`.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Trabajador transferido entre empresas de países distintos: cese y alta, misma persona.
- Norma con vigencia retroactiva.

## 14. Criterios de aceptación generales

- Agregar un país consiste en construir y publicar su paquete, sin cambios en el núcleo.
