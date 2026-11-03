# M-PAR · Parámetros legales con vigencia

Fase 3 · Nómina Perú · Épica `PAR` · Reglas `RN-PAR-nn` · Módulo comercial: Nómina Perú (`payroll`)

## 1. Objetivo

Mantener tasas, topes, montos de referencia y plazos legales como datos con fecha de vigencia por país y régimen, actualizables el mismo día en que cambia la norma.

## 2. Alcance

- Parámetros del paquete de país: UIT, RMV, tasas de pensiones y salud, escala y deducción de renta de quinta, sobretasas, porcentajes de utilidades, plazos y umbrales de cumplimiento.
- Tablas por entidad (comisiones y primas por AFP y esquema, remuneración máxima asegurable).
- Niveles: plataforma (publica el paquete), organización (aplica y puede mejorar donde la norma lo permite).
- Fuente y sustento por valor (norma, fecha de publicación); estado «a confirmar».
- Aviso a las organizaciones cuando cambia un parámetro; vista de impacto.

## 3. Fuera de alcance

- Fórmulas (M-CPT). Políticas internas no legales (M-CFG).

## 4. Trazabilidad con el pedido original

**Puntos del pedido (número de sección):** §28 Localización Perú.

**Correcciones que incorpora:** C-03 Historia con vigencia; C-05 Planilla = motor + reglas + país + régimen.

**Ampliaciones que incorpora:** ninguna.

## 5. Actores y permisos

- Equipo de cumplimiento de la plataforma publica. Payroll de cada organización consulta y registra valores propios (tasas de SCTR, pólizas, EPS).

| Permiso | Qué permite | Roles de sistema que lo traen |
|---|---|---|
| `platform.country_packs.view` | Ver paquetes de país | Superadmin SaaS, Administrador empresa, Payroll, Auditor |
| `platform.country_packs.activate` | Activar paquetes de país | Superadmin SaaS, Administrador empresa |
| `platform.country_packs.publish` **(+)** | Construir, versionar y publicar paquetes de país | Superadmin SaaS |
| `payroll.legal_parameters.view` | Ver parámetros legales con vigencia | Administrador empresa, RRHH, Payroll, Auditor, Contador |
| `payroll.legal_parameters.add_value` | Añadir valores con vigencia a parámetros legales con vigencia | Superadmin SaaS, Administrador empresa, Payroll |
| `payroll.legal_parameters.create` | Crear parámetros legales con vigencia | Superadmin SaaS |
| `payroll.legal_parameters.confirm` **(+)** | Confirmar un parámetro «a confirmar» antes de un cierre | Administrador empresa, Payroll |
| `payroll.wage_tables.view` | Ver tablas salariales por convenio (lotes de parámetros con vigencia) | Administrador empresa, RRHH, Payroll, Auditor, Contador |
| `payroll.wage_tables.create` | Crear tablas salariales por convenio (lotes de parámetros con vigencia) | Superadmin SaaS |
| `payroll.wage_tables.verify` | Verificar tablas salariales por convenio (lotes de parámetros con vigencia) | Administrador empresa, Payroll |

**(S)** sensible · **(+)** por añadir a `api/openapi.yaml`. Alcance, permisos por campo y matriz completa: `07-permisos-y-roles.md`.

## 6. Flujos principales

**Actualización legal.** 1) La plataforma registra el nuevo valor con fecha de vigencia, norma y sustento. 2) Lo publica. 3) Las organizaciones del país reciben el aviso con el impacto estimado. 4) Los cálculos de periodos desde esa fecha lo usan.

**Carga periódica.** 1) Cada periodo se cargan las tablas que publica el supervisor (comisiones y primas de AFP, remuneración máxima asegurable). 2) Si falta la tabla del periodo, el cálculo lo advierte.

## 7. Pantallas

Columna «Prototipo»: ruta de menú real del prototipo final (`core.html` más `mod-1` a `mod-8`; mapa completo en `17-mapa-del-prototipo.md`). El prototipo es referencia de navegación y flujo, no de implementación (`15-que-no-copiar-del-prototipo.md`). «Sin prototipo» = la pantalla no existe en el prototipo y se diseña con el sistema de diseño (M-UI).

| Pantalla | Qué muestra y qué permite | Prototipo |
|---|---|---|
| Parámetros legales | Lista por país y régimen con valor vigente, vigencias anteriores y futuras, fuente, estado | Prototipo: Nómina → Configuración de nómina → pestaña Parámetros legales |
| Tablas por entidad | Valores por AFP, aseguradora o entidad y periodo | Prototipo: Nómina → Configuración de nómina → pestaña Parámetros legales |
| Novedades normativas | Cambios publicados y su impacto en la organización | Prototipo: Nómina → Configuración de nómina → pestaña Parámetros legales |

## 8. Datos principales

Parámetros (`legal_parameters`: país, régimen, clave, valor, `valid_from`, `valid_to`, norma, estado de verificación), tablas por entidad y periodo, publicaciones del paquete.

## 9. Reglas de negocio

Detalle y marca de parámetro en `06-reglas-de-negocio.md`, sección PAR.

- **RN-PAR-01**: Si un cálculo necesita una tasa, tope o monto legal → lo obtiene del parámetro vigente en la fecha del hecho para el país y régimen de la relación; nunca de una constante.
- **RN-PAR-02**: Si se registra un nuevo valor → se inserta con su vigencia y fuente; el anterior queda cerrado, no modificado.
- **RN-PAR-03**: Si un parámetro está en estado «a confirmar» → se puede usar en simulación, pero el cierre de un periodo que lo use exige confirmación expresa del responsable de planilla.
- **RN-PAR-04**: Si falta el parámetro o la tabla del periodo (por ejemplo, comisiones de AFP del mes) → el cálculo de los afectados queda «con error» con mensaje claro.
- **RN-PAR-05** (parámetro): Si la UIT de referencia es S/ 5 500 y la RMV S/ 1 130 (valores de referencia 2026, parámetros con vigencia) → se cargan como valores iniciales del paquete Perú y todo lo que depende de ellos se deriva, no se copia.
- **RN-PAR-06**: Si la plataforma publica una actualización → se notifica a Payroll y Administrador de cada organización afectada con la norma y la fecha.
- **RN-PAR-07**: Si la organización registra un valor propio más favorable donde la norma lo permite → se usa el propio; si es menos favorable que el legal → se rechaza.
- **RN-PAR-08**: Si se publica un parámetro con vigencia retroactiva → se listan los periodos cerrados afectados y se proponen diferencias.
- **RN-PAR-09**: Si un parámetro es un plazo → su unidad distingue días calendario (`days`) de días hábiles (`business_days`); el cálculo de vencimientos usa el calendario de la sede.
- **RN-PAR-10**: Si se publica una tabla salarial por convenio (construcción civil) → se carga como un lote de parámetros con la misma vigencia y fuente legal, sin verificar hasta que el responsable la confirme contra el acta.
- **RN-PAR-11** (parámetro): Si el periodo de pago cae después del fin de vigencia de la tabla salarial y no hay tabla nueva → se calcula con la última y los afectados quedan marcados «tabla salarial vencida»; al cargarse la nueva se generan los reintegros.

## 10. Estados

Sin entidades con ciclo de vida propio; sus registros son de configuración con vigencia (activo / archivado) o de solo inserción.

## 11. Eventos

**Emite:**

- `parameter.changed`, `country_pack.published`.

**Consume:**

Ninguno.

## 12. Integraciones

Sin integraciones externas propias; usa los servicios del núcleo.

## 13. Casos borde

- Cambio de UIT o RMV a mitad de año con efecto en asignación familiar y topes.
- Norma publicada con efecto retroactivo.
- Entidad nueva (AFP, aseguradora) que aparece en el mercado.

## 14. Criterios de aceptación generales

- Cambiar una tasa legal no requiere desplegar código y no altera boletas ya cerradas.
