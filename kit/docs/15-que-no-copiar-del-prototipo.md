# 15 · Qué no copiar del prototipo

El prototipo (`core.html` + módulos) es un solo archivo HTML con JavaScript puro que guarda todo en el navegador. Sirve para **validar navegación, flujos, jerarquía visual y vocabulario**. No es una base de código ni una referencia de implementación.

## 1. Qué sí se toma

| Del prototipo | Cómo se usa |
|---|---|
| Mapa de navegación y agrupación del menú por rol | `10-pantallas-y-ux.md` §4 |
| Flujo de cada pantalla: qué se ve primero, qué acciones hay, en qué orden | Guía para construir la pantalla con `/pantalla` |
| Tokens de color, tipografía y forma (`:root`) | `10` §1, tal cual |
| Patrones: tabla con vistas, ficha con pestañas, línea de tiempo, estados vacíos, alertas con explicación, bandeja de pendientes | Componentes de `src/ui` |
| Vocabulario de la interfaz en español de Perú | Textos base de `messages/es.json` |
| Organización demo «Grupo Andino» (empresas, sedes, unidades, personas y sus relaciones) | Semilla coherente del seeder demo |
| Reglas de producto que el prototipo ya respeta: ningún botón falso, lo simulado se rotula, la IA no decide | Se mantienen |
| Nombres de eventos (`employee.hired`, `employee.updated`, `employee.terminated`, …) | Catálogo de `02` §9 |

## 2. Qué no se lleva a producción

### 2.1 Datos y persistencia

| En el prototipo | Por qué no | En producción |
|---|---|---|
| Toda la base (`db`) en `localStorage` del navegador | Sin servidor, sin concurrencia, sin respaldo, cualquiera la edita | PostgreSQL tras la API; el navegador no guarda datos de negocio |
| Todas las organizaciones en un mismo objeto; `T()` elige una | No hay aislamiento real | `tenant_id` + RLS + FK compuestas |
| Identificadores cortos y secuenciales (`rl1`, `pe3`, `us2`) generados en el cliente | Predecibles, colisionan | Identificadores opacos del servidor (A-03) |
| Colecciones como arreglos con todo cargado en memoria; filtros, orden y paginación en el cliente | No escala más allá de una demo | Paginación, filtro y orden en servidor; índices |
| Relación laboral con campos planos (`puestoId`, `jefeId`, `salario`, `sedeId`) | No hay historia con vigencia real; el cambio pisa el valor | Asignaciones, compensaciones y contratos con `valid_from`/`valid_to` |
| Jefe como referencia directa a otra relación | Se rompe con vacantes y reorganizaciones | Jefe por posición, con excepción por relación |
| Estado como texto en español (`'Activo'`, `'Aprobada'`) | Acopla datos a idioma | Enumeraciones en inglés + traducción en la interfaz |
| Nombres de campo y colección en español (`personaId`, `vacSolicitudes`, `nomBoletas`) | El modelo real está en inglés | Nombres de `database/schema.sql` |
| Subdocumentos embebidos (`tareas:[…]`, `lineas:[…]`) | Sin integridad ni consulta | Tablas relacionadas |
| Montos como números de JavaScript | Errores de coma flotante | Decimal exacto + moneda |
| Fechas como texto local y `TODAY` del navegador | Sin zona horaria; manipulable | UTC en base, zona de la empresa, reloj del servidor |
| Semilla que se regenera relativa a hoy | Solo sirve para que la demo se vea vigente | Seeder demo determinista con reloj controlado |
| Botón «restablecer datos» | Es de demo | No existe |

### 2.2 Lógica en el cliente

| En el prototipo | En producción |
|---|---|
| Cálculo de planilla, aportes, descuentos y neto en JavaScript | Motor de nómina en servidor con conceptos y parámetros con vigencia; el cliente solo muestra |
| Tasas, topes y montos legales escritos en el código del prototipo | Parámetros legales con vigencia y fuente; **ningún valor del prototipo es una referencia legal** |
| Saldos de vacaciones, antigüedad, tardanzas, horas extra y vencimientos calculados al pintar | Calculados y guardados en servidor; consultables con `as_of` |
| Alertas y anomalías calculadas al abrir el inicio | Reglas de alerta en servidor, con explicación, programadas y por evento |
| Indicadores y gráficos recorriendo arreglos | Agregados en servidor |
| Validaciones solo en el formulario | Validación en servidor (obligatoria) y en cliente (comodidad) |
| Transiciones de estado asignando el campo (`x.estado='Aprobada'`) | Acciones `POST /recurso/{id}/accion` validadas por la máquina de estados |
| Efectos en cadena ejecutados en línea (aprobar vacaciones actualiza saldo, calendario y actividad en el mismo clic) | Un caso de uso transaccional + eventos de dominio y consumidores idempotentes |
| Bus de eventos en memoria (`on`/`emit`) | Outbox en base + despachador + reintentos |
| Motor de aprobaciones resuelto por cada módulo con `pend()` | Motor de aprobaciones transversal; cada módulo implementa `PendingSource` |
| Generación de «PDF» como HTML dentro de un modal | PDF generado en servidor desde plantilla versionada, guardado con hash |
| «Exportar» como texto en un cuadro para copiar | Exportación asíncrona a archivo, con permisos por columna y auditoría |
| Importador que procesa el texto pegado en el navegador | Importación en cola con mapeo, validación, vista previa y resultado por fila |
| Búsqueda recorriendo todo en memoria | Búsqueda en servidor filtrada por permisos y alcance |

### 2.3 Seguridad

| En el prototipo | En producción |
|---|---|
| Acceso eligiendo un usuario de una lista, sin clave | Autenticación real: correo o documento + clave, OTP, MFA, SSO |
| Sesión como objeto guardado en el navegador | Sesión en servidor con cookie `HttpOnly` |
| Permisos evaluados con `can()` en el cliente; los datos sensibles están en memoria aunque no se pinten | Policy + alcance + permisos por campo en servidor; el campo sin permiso **no viaja** |
| Alcance con tres valores (`todos`, `equipo`, `propio`) | Seis alcances y varias asignaciones de rol por usuario |
| Auditoría como arreglo editable en el navegador | Tabla de solo inserción con privilegios revocados |
| Denuncias en la misma estructura que el resto | Módulo aislado, clave propia, acceso por asignación |
| Encuestas «anónimas» con `relId: null` en la misma colección | Sin vínculo persona–respuesta por diseño y umbral mínimo por segmento |
| Consola SaaS en la misma aplicación y los mismos datos | Dominio, guard y rol de base distintos; sesión de soporte auditada |
| Fuentes cargadas desde un tercero | Servidas desde el propio dominio |

### 2.4 Código e interfaz

| En el prototipo | En producción |
|---|---|
| Un solo archivo; HTML generado concatenando cadenas; `render()` repinta todo | Componentes React de `src/ui` y de cada módulo |
| Estado global mutable (`ui`, `db`) | Estado de servidor en caché de consultas; estado de interfaz en la URL |
| Eventos delegados por `data-a` | Manejadores de componentes |
| Textos en español escritos en el código | Claves i18n en es, en y pt |
| `registerModule` como contrato entre módulos | Módulos de dominio del backend con interfaz pública; en el frontend, registro de rutas y menú a partir de `GET /me` |
| Enrutado por `location.hash` | App Router con rutas reales y enlaces compartibles |
| Tablas que desbordan dentro de un contenedor | Tabla avanzada con primera columna fija y vista de tarjetas en el portal |
| Simulaciones rotuladas (correo, firma, WhatsApp, banco) | Adaptadores reales tras puertos; las versiones falsas quedan para desarrollo y pruebas |
| Sin pruebas automáticas más allá del recorrido de humo | Definición de terminado de `11` |

## 3. Diferencias de modelo que hay que recordar

El prototipo simplifica el modelo. Al leerlo, traducir así:

| Prototipo | Producción |
|---|---|
| `tenant` | Organización (`tenants`) |
| `empresas` | Razones sociales (`legal_entities`) |
| `sedes` | `sites` |
| `unidades` | Árbol único `org_units` |
| `puestos` (uno por silla, con vacante) | **Dos cosas**: `job_profiles` (catálogo) y `positions` (plaza). El `puesto` del núcleo mezcla ambas |
| `personas` | `people` |
| «relación laboral» (`rlN`) con todo dentro | `employments` + asignaciones + compensaciones + contratos, todos con vigencia |
| `usuarios` ligados a una persona | `users`, que pueden existir sin persona (auditor externo, usuario de servicio) |
| `roles` con lista de permisos y un alcance | Roles con permisos, permisos por campo y alcance por asignación |
| Permisos en español (`tiempo.asistencia.ver`) | Permisos en inglés (`time.attendance_days.view`) |
| `posiciones` del módulo de talento | `positions` del núcleo (fase 1), no de talento |

## 4. Regla para Claude Code

Al construir una pantalla desde el prototipo:

1. Lee la pantalla para entender **qué hace el usuario** y **qué ve**.
2. No abras el JavaScript del prototipo para copiar lógica. Si necesitas una regla de negocio, está en la especificación del módulo o en el backlog; si no está, pregunta.
3. Cada dato que el prototipo calcula en el cliente es un endpoint que hay que pedir.
4. Cada acción que el prototipo ejecuta mutando un objeto es una acción de la API.
5. Si el prototipo y la especificación se contradicen, manda la especificación; anota la diferencia en la historia.
