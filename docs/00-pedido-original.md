# DESARROLLO DE SaaS INTEGRAL DE RECURSOS HUMANOS — HRIS / HCM

Quiero que diseñes y desarrolles un **SaaS integral de Recursos Humanos (HRIS/HCM)** de nivel empresarial.

No quiero un simple sistema para registrar empleados.

Quiero una plataforma completa capaz de administrar todo el ciclo de vida del colaborador:

ATRACCIÓN
→ RECLUTAMIENTO
→ SELECCIÓN
→ CONTRATACIÓN
→ PRE-ONBOARDING
→ ONBOARDING
→ ADMINISTRACIÓN LABORAL
→ ASISTENCIA
→ PLANILLAS
→ BENEFICIOS
→ DESEMPEÑO
→ OBJETIVOS
→ CAPACITACIÓN
→ DESARROLLO
→ COMPENSACIONES
→ CLIMA Y EXPERIENCIA
→ SEGURIDAD Y SALUD
→ OFFBOARDING
→ ANALÍTICA

Debe diseñarse como un verdadero producto SaaS comercial, multiempresa, modular, escalable, configurable y preparado para diferentes países.

Las soluciones de referencia conceptual pueden ser:

- Buk
- Factorial
- BambooHR
- Workday
- Rippling
- Deel
- Sesame HR

Estas plataformas son únicamente referencias funcionales.

NO debes copiar literalmente:

- interfaces
- código
- branding
- textos
- iconografía propietaria
- estructura visual exacta
- nombres comerciales propios

Quiero construir una solución con IDENTIDAD PROPIA.

---

# 1. ARQUITECTURA SaaS MULTITENANT

La arquitectura debe estar preparada desde el principio para SaaS.

Estructura conceptual:

PLATAFORMA
→ TENANT / ORGANIZACIÓN
→ EMPRESAS / RAZONES SOCIALES
→ SEDES
→ ÁREAS
→ DEPARTAMENTOS
→ EQUIPOS
→ PUESTOS
→ COLABORADORES

Una organización puede administrar múltiples empresas.

Ejemplo:

Grupo Empresarial ABC

→ Empresa Perú SAC
→ Empresa Servicios SAC
→ Empresa Colombia SAS

La información entre tenants debe estar completamente aislada.

Nunca un usuario de una organización debe acceder accidentalmente a información de otra.

---

# 2. SUPERADMINISTRADOR DEL SaaS

Crear panel independiente para el propietario del SaaS.

Debe permitir administrar:

Organizaciones
Empresas
Usuarios
Planes
Suscripciones
Módulos contratados
Límites
Almacenamiento
Consumo
Facturación SaaS
Pruebas gratuitas
Estado de cuentas
Bloqueos
Licencias
Soporte
Logs
Auditoría
Backups
Configuraciones globales

Dashboard SaaS:

- organizaciones activas
- nuevas organizaciones
- usuarios totales
- colaboradores administrados
- MRR
- ARR
- churn
- trials
- conversiones
- almacenamiento
- organizaciones suspendidas
- módulos más utilizados
- incidencias

Separar completamente:

SUPERADMINISTRACIÓN SaaS

de

ADMINISTRACIÓN RRHH DEL CLIENTE.

---

# 3. PLANES Y SUSCRIPCIONES

Permitir crear planes comerciales.

Ejemplo:

Starter
Business
Professional
Enterprise

Pero NO dejar los nombres rígidos.

El superadministrador debe poder:

Crear plan
Editar plan
Duplicar plan
Activar/desactivar
Definir precio
Definir moneda
Definir ciclo mensual/anual
Definir límites
Seleccionar módulos incluidos
Seleccionar funcionalidades
Crear add-ons

Ejemplo:

Plan Professional

Hasta 200 empleados
5 administradores
20 GB
ATS ✓
Asistencia ✓
Desempeño ✓
Planillas ✓
LMS ✓

Los permisos funcionales deben depender de la suscripción.

---

# 4. ESTRUCTURA ORGANIZACIONAL

Crear administrador de estructura empresarial.

Permitir configurar:

Razones sociales
Sedes
Sucursales
Ubicaciones
Áreas
Departamentos
Subdepartamentos
Unidades
Equipos
Centros de costo
Puestos
Cargos
Niveles
Categorías laborales

Crear organigrama visual dinámico.

Ejemplo:

Gerencia General
│
├── Administración
│   ├── Contabilidad
│   └── Recursos Humanos
│
├── Comercial
│   ├── Marketing
│   └── Ventas
│
└── Operaciones

Debe actualizarse automáticamente cuando cambian cargos o responsables.

---

# 5. HEADCOUNT Y PLANIFICACIÓN DE PLANTILLA

Crear módulo profesional de Workforce Planning / Headcount.

Permitir:

Presupuesto de posiciones
Posiciones aprobadas
Posiciones ocupadas
Vacantes
Nuevas posiciones
Posiciones congeladas
Posiciones eliminadas
Costos proyectados
Desviaciones

Flujo:

Solicitud de posición
→ aprobación
→ presupuesto
→ reclutamiento
→ contratación
→ posición ocupada

Evitar contratar a alguien contra una posición inexistente cuando la empresa active esta política.

Crear escenarios:

Actual
Conservador
Crecimiento
Personalizado

---

# 6. FICHA 360° DEL COLABORADOR

Esta debe ser una de las funcionalidades centrales.

Cada trabajador debe disponer de una ficha completa.

DATOS PERSONALES

Foto
Nombres
Apellidos
Documento
Fecha nacimiento
Sexo cuando legalmente corresponda
Nacionalidad
Estado civil
Dirección
Teléfono
Correo
Contacto emergencia

DATOS LABORALES

Código
Empresa
Sede
Área
Departamento
Equipo
Cargo
Puesto
Jefe
Fecha ingreso
Antigüedad
Tipo trabajador
Modalidad
Horario
Centro costo
Estado

CONTRATO

Tipo
Inicio
Fin
Renovaciones
Periodo prueba
Jornada
Documentos

COMPENSACIÓN

Sueldo
Moneda
Bonos
Comisiones
Asignaciones
Historial salarial

Además pestañas:

Resumen
Información
Contrato
Asistencia
Vacaciones
Planilla
Documentos
Activos
Desempeño
Objetivos
Competencias
Capacitaciones
Beneficios
Encuestas
Reconocimientos
Historial
Actividad

Esta ficha debe ser la FUENTE ÚNICA DE INFORMACIÓN DEL COLABORADOR.

---

# 7. HISTORIAL LABORAL

Nunca sobrescribir silenciosamente información histórica crítica.

Registrar cambios de:

Cargo
Área
Sede
Jefe
Salario
Contrato
Horario
Centro costo
Estado

Ejemplo:

01/01/2026
Analista Junior

01/07/2026
Analista Senior

01/01/2027
Coordinador

Mostrar línea de tiempo.

---

# 8. PORTAL DEL COLABORADOR

Crear autoservicio ESS.

El colaborador debe poder:

Ver perfil
Actualizar información permitida
Ver contrato
Ver documentos
Descargar boletas
Solicitar vacaciones
Solicitar permisos
Ver asistencia
Ver horarios
Ver beneficios
Registrar gastos
Responder encuestas
Realizar evaluaciones
Consultar objetivos
Realizar capacitaciones
Firmar documentos
Solicitar certificados
Crear solicitudes a RRHH

Dashboard personalizado:

Hola, Carlos.

Vacaciones disponibles: 14 días

Próximo pago: XX/XX

Pendientes:
• Firmar documento
• Completar evaluación
• Curso obligatorio
• Solicitud pendiente

---

# 9. PORTAL DEL MANAGER

Crear experiencia diferente para responsables.

Un jefe debe visualizar:

Mi equipo
Asistencia
Vacaciones
Solicitudes
Objetivos
Evaluaciones
Capacitaciones
Carga
Cumpleaños
Aniversarios
Alertas

Debe poder aprobar procesos según permisos.

---

# 10. RECLUTAMIENTO — ATS

Crear Applicant Tracking System completo.

Permitir:

Crear vacante
Aprobar vacante
Publicar
Recibir candidatos
Evaluar
Entrevistar
Calificar
Contratar

Pipeline configurable:

Nuevo
→ Preselección
→ Entrevista RRHH
→ Entrevista técnica
→ Evaluación
→ Referencias
→ Oferta
→ Contratado

Drag & drop.

Cada candidato:

Datos
CV
Experiencia
Educación
Pretensión salarial
Fuente
Vacante
Notas
Documentos
Entrevistas
Evaluaciones
Actividad

---

# 11. PORTAL DE EMPLEOS

Crear portal público configurable:

empresa.com/trabaja-con-nosotros

Mostrar:

Vacantes
Ubicación
Modalidad
Descripción
Requisitos
Beneficios

Formulario configurable.

Preparar SEO.

---

# 12. TALENT POOL

Crear base de talento.

Permitir guardar candidatos aunque no hayan sido contratados.

Etiquetas:

Desarrolladores
Ventas
Administración
Senior
Referidos

Permitir búsquedas y filtros avanzados.

---

# 13. ENTREVISTAS

Crear agenda de entrevistas.

Configurar:

Fecha
Hora
Entrevistadores
Tipo
Lugar/enlace
Guía
Scorecard

Los entrevistadores deben poder evaluar candidatos mediante formularios estructurados.

---

# 14. OFERTAS LABORALES

Crear generador de oferta.

Plantillas variables:

{{nombre}}
{{cargo}}
{{salario}}
{{fecha_ingreso}}

Flujo:

Borrador
→ Revisión
→ Aprobación
→ Enviada
→ Aceptada/Rechazada

---

# 15. PRE-ONBOARDING

Cuando un candidato acepta:

Candidato
→ futuro colaborador

Solicitar antes del ingreso:

Datos
Documentos
Cuenta bancaria
Contacto emergencia
Información requerida

Preparar tareas previas.

---

# 16. ONBOARDING

Crear diseñador de procesos.

Plantillas según:

Empresa
Sede
Cargo
Área

Ejemplo:

7 días antes:
Crear correo
Preparar laptop
Crear accesos

Día 1:
Bienvenida
Documentos
Inducción

Semana 1:
Cursos
Reunión jefe

30 días:
Seguimiento

60 días:
Evaluación

90 días:
Cierre onboarding

Mostrar porcentaje de avance.

---

# 17. OFFBOARDING

Proceso inverso profesional.

Renuncia
Despido
Fin contrato
Jubilación
Otros

Checklist:

Carta
Liquidación
Entrega activos
Desactivar accesos
Entregar documentos
Encuesta salida
Entrevista salida

Registrar motivo de salida.

---

# 18. CONTRATOS

Administrar:

Tipos de contrato
Inicio
Fin
Renovaciones
Adendas
Cambios
Alertas de vencimiento

Mostrar contratos próximos a vencer.

---

# 19. GESTIÓN DOCUMENTAL

Repositorio documental.

Carpetas:

Personal
Contratos
Boletas
Certificados
Vacaciones
SST
Evaluaciones
Capacitaciones

Permisos por documento.

Permitir:

Subir
Descargar
Previsualizar
Versionar
Archivar
Solicitar
Aprobar

---

# 20. GENERADOR DE DOCUMENTOS

Crear plantillas dinámicas.

Ejemplo:

CERTIFICADO DE TRABAJO

Se certifica que {{empleado.nombre}} labora como {{empleado.cargo}} desde {{empleado.fecha_ingreso}}.

Generar PDF.

---

# 21. FIRMA ELECTRÓNICA

Preparar flujo de firma:

Documento
→ firmantes
→ orden
→ enviado
→ visto
→ firmado
→ completado

Mantener evidencia y trazabilidad.

Diseñar una capa de proveedor para poder integrar posteriormente servicios externos de firma sin acoplar el sistema a uno específico.

---

# 22. ASISTENCIA

Módulo completo.

Registrar:

Entrada
Salida
Descansos
Horas trabajadas
Tardanzas
Faltas
Horas extras
Trabajo nocturno
Incidencias

Métodos preparados:

Web
App
QR
Kiosko
PIN
Integraciones biométricas

---

# 23. TURNOS

Crear administrador visual.

Turnos:

Mañana
Tarde
Noche
Partido
Rotativo

Calendario drag & drop.

Permitir asignación masiva.

---

# 24. HORARIOS Y JORNADAS

Configurar:

Jornada semanal
Horas diarias
Descansos
Tolerancia
Horas extras
Feriados
Calendarios

Políticas diferentes por empresa/sede/grupo.

---

# 25. VACACIONES

Administrar saldo.

Mostrar:

Generadas
Tomadas
Programadas
Pendientes
Disponibles

Flujo:

Empleado solicita
→ responsable aprueba
→ RRHH valida si corresponde
→ saldo actualizado

Calendario de ausencias del equipo.

---

# 26. AUSENCIAS Y PERMISOS

Tipos configurables:

Vacaciones
Descanso médico
Licencia
Permiso personal
Maternidad
Paternidad
Compensación
Trabajo remoto
Otros

Cada tipo puede tener reglas propias.

---

# 27. PLANILLAS / PAYROLL

Este módulo debe diseñarse como un motor independiente y parametrizable por país.

NO hardcodear reglas laborales en componentes frontend.

Arquitectura:

Motor de nómina
+
Motor de reglas
+
Localización por país

Conceptos:

Ingresos
Descuentos
Aportes
Impuestos
Beneficios
Bonificaciones
Comisiones
Horas extras
Otros

Proceso:

Periodo
→ incidencias
→ cálculo
→ revisión
→ aprobación
→ cierre
→ boletas
→ pago
→ contabilización

---

# 28. LOCALIZACIÓN PERÚ

Crear paquete específico Perú.

Preparar administración de conceptos relacionados con:

Remuneración básica
Asignación familiar
Horas extras
Comisiones
Bonificaciones
Gratificaciones
CTS
Vacaciones
AFP
ONP
EsSalud
Quinta categoría
Descuentos
Liquidación

Preparar generación/gestión de archivos e información requerida para procesos como:

PLAME
AFPnet
archivos bancarios
archivos contables

IMPORTANTE:

Las reglas, porcentajes, topes y cálculos legales deben ser PARAMETRIZABLES y versionados por vigencia.

Nunca asumir que una tasa legal será permanente.

---

# 29. MOTOR DE CONCEPTOS DE NÓMINA

Permitir crear conceptos personalizados.

Ejemplo:

Comisión ventas
Bono productividad
Movilidad
Descuento préstamo

Definir:

Código
Nombre
Tipo
Fórmula
Condiciones
Vigencia
Afectaciones
Contabilización

---

# 30. SIMULADOR DE PLANILLA

Antes de cerrar:

Simular.

Mostrar diferencias:

Mes anterior | Mes actual | Variación

Detectar anomalías.

Ejemplo:

Carlos Pérez
Sueldo neto cayó 32%.

Mostrar alerta para revisión.

---

# 31. BOLETAS DE PAGO

Generar PDF profesional.

Publicar automáticamente en portal.

Historial por trabajador.

Descarga individual y masiva.

---

# 32. PRÉSTAMOS Y ADELANTOS

Administrar:

Préstamos
Adelantos
Cuotas
Saldo
Descuento automático

Cronograma.

---

# 33. COMPENSACIONES

Administrar:

Salario base
Variable
Bonos
Comisiones
Beneficios

Crear bandas salariales.

Ejemplo:

Analista I
S/ X – S/ Y

Analista II
S/ Y – S/ Z

Comparar posición del trabajador dentro de banda.

---

# 34. CICLOS DE REVISIÓN SALARIAL

Crear campañas:

Revisión anual 2027

Manager propone
→ RRHH revisa
→ Finanzas aprueba
→ Dirección aprueba
→ actualización salarial

Controlar presupuesto.

---

# 35. BENEFICIOS

Catálogo:

Seguro
Alimentación
Movilidad
Educación
Descuentos
Wellness
Otros

Definir elegibilidad.

Empleado puede consultar sus beneficios.

---

# 36. GASTOS Y REEMBOLSOS

Permitir:

Registrar gasto
Adjuntar comprobante
Categorizar
Asignar centro costo
Enviar aprobación

Flujo:

Empleado
→ manager
→ finanzas
→ aprobado
→ pagado

---

# 37. ACTIVOS

Administrar:

Laptop
Celular
Monitor
Uniforme
Tarjeta
Equipo
Licencia de software
Otros

Estados:

Disponible
Asignado
Mantenimiento
Perdido
Baja

Historial completo.

---

# 38. EVALUACIÓN DE DESEMPEÑO

Crear campañas:

90°
180°
270°
360°

Participantes:

Autoevaluación
Jefe
Pares
Subordinados
Otros

Configurar competencias, preguntas y escalas.

---

# 39. COMPETENCIAS

Catálogo:

Liderazgo
Comunicación
Trabajo equipo
Orientación resultados
Innovación
Organización

Niveles configurables.

Relacionar competencias con cargos.

---

# 40. OBJETIVOS / OKR

Permitir:

Objetivos corporativos
Objetivos de área
Objetivos individuales

Objetivo
→ Key Results
→ avance

Relacionarlos jerárquicamente.

---

# 41. 1:1

Crear módulo de reuniones individuales.

Manager ↔ colaborador.

Agenda
Temas
Notas
Compromisos
Seguimiento

Permitir definir información privada cuando corresponda.

---

# 42. FEEDBACK CONTINUO

Permitir:

Solicitar feedback
Dar feedback
Reconocer
Crear seguimiento

Integrarlo con desempeño.

---

# 43. PLANES DE DESARROLLO

Después de una evaluación:

Crear plan.

Ejemplo:

Competencia: Liderazgo
Nivel actual: 2
Objetivo: 4

Acciones:
Curso
Mentoría
Proyecto
Seguimiento

---

# 44. PLAN DE CARRERA

Crear rutas:

Junior
→ Semi Senior
→ Senior
→ Líder
→ Manager

Mostrar competencias requeridas y brechas.

---

# 45. SUCESIÓN

Identificar puestos críticos.

Definir posibles sucesores.

Evaluar:

Preparado ahora
1 año
2 años
En desarrollo

Crear matriz de sucesión.

---

# 46. MATRIZ 9-BOX

Crear matriz visual:

Desempeño × Potencial.

Permitir análisis de talento.

No tomar decisiones automáticas sobre personas basándose únicamente en esta matriz.

---

# 47. LMS / CAPACITACIÓN

Crear sistema interno de aprendizaje.

Cursos
Categorías
Lecciones
Videos
PDF
Evaluaciones
Certificados

Asignaciones:

Individual
Equipo
Cargo
Empresa

Seguimiento:

No iniciado
En progreso
Completado
Vencido

---

# 48. PLAN ANUAL DE CAPACITACIÓN

Permitir planificar:

Cursos
Presupuesto
Participantes
Horas
Proveedores
Resultados

Comparar:

Planificado vs ejecutado.

---

# 49. ENCUESTAS

Constructor.

Tipos:

Clima
Engagement
Onboarding
Salida
Pulso
eNPS
Personalizada

Permitir anonimato configurable.

---

# 50. eNPS

Calcular:

Promotores
Pasivos
Detractores

Mostrar tendencias.

Evitar revelar identidad cuando el anonimato esté activado.

---

# 51. RECONOCIMIENTOS

Sistema interno:

Reconocer compañero
Valor empresarial
Mensaje
Insignia

Feed opcional.

---

# 52. COMUNICACIÓN INTERNA

Publicar:

Noticias
Comunicados
Eventos
Cumpleaños
Aniversarios

Segmentar por:

Empresa
Sede
Área
Equipo

Registrar lectura cuando sea necesario.

---

# 53. MESA DE AYUDA RRHH

Crear HR Service Desk.

Empleado crea solicitud:

Certificado
Planilla
Vacaciones
Beneficios
Documentos
Consulta
Otro

Ticket:

Nuevo
En atención
Esperando información
Resuelto
Cerrado

SLA configurable.

---

# 54. BASE DE CONOCIMIENTO RRHH

Preguntas frecuentes.

Categorías.

Buscador.

Relacionar artículos con solicitudes.

---

# 55. WORKFLOWS

Crear diseñador NO-CODE.

CUANDO
→ SI
→ ENTONCES

Ejemplo:

CUANDO
se crea un trabajador

SI
Área = Tecnología

ENTONCES
Asignar onboarding TI
Crear tareas
Solicitar laptop
Notificar manager

---

# 56. APROBACIONES

Motor transversal.

Vacaciones
Gastos
Horas extra
Documentos
Contrataciones
Incrementos
Headcount
Cursos
Solicitudes

Permitir:

1 nivel
2 niveles
N niveles

Aprobaciones secuenciales o paralelas.

---

# 57. SEGURIDAD Y SALUD EN EL TRABAJO — SST

Crear módulo SST configurable.

Administrar:

Accidentes
Incidentes
Inspecciones
Riesgos
Observaciones
Medidas correctivas
Exámenes ocupacionales
Capacitaciones
Equipos de protección
Comités
Documentos
Vencimientos

Dashboard SST.

Prepararlo para requisitos particulares de cada país.

---

# 58. CANAL DE DENUNCIAS

Canal independiente y confidencial.

Permitir denuncia:

Identificada
Anónima cuando legalmente sea permitido

Generar código seguro de seguimiento.

Adjuntar evidencia.

Estados.

Asignar investigadores autorizados.

Acceso altamente restringido.

Mantener trazabilidad sin comprometer anonimato.

---

# 59. CASOS DISCIPLINARIOS

Administrar con permisos especiales:

Incidentes
Descargos
Documentos
Medidas
Seguimiento

Información altamente confidencial.

---

# 60. SEGURIDAD DE INFORMACIÓN RRHH

Los datos de RRHH son especialmente sensibles.

Implementar:

RBAC
Permisos granulares
MFA-ready
Logs
Auditoría
Cifrado
Sesiones
Políticas de contraseña
Bloqueo
Rate limiting
Protección de archivos
Control de descargas

Aplicar principio de mínimo privilegio.

---

# 61. ROLES

Roles iniciales:

Superadmin SaaS
Administrador empresa
RRHH
Payroll
Recruiter
Manager
Empleado
Auditor

Permitir crear personalizados.

---

# 62. PERMISOS GRANULARES

Ejemplo:

Empleado.salario.ver
Empleado.salario.editar
Empleado.documentos.ver
Planilla.procesar
Planilla.aprobar
Planilla.cerrar
Reclutamiento.crear
SST.administrar

No depender únicamente de roles rígidos.

---

# 63. AUDITORÍA

Registrar:

Quién
Qué hizo
Cuándo
Desde dónde cuando corresponda
Registro
Valor anterior
Valor nuevo

Ejemplo:

01/10/2026 14:32
Usuario: Administrador

Salario:
S/ 3,500 → S/ 3,900

La auditoría no debe poder modificarse desde una interfaz normal.

---

# 64. PEOPLE ANALYTICS

Crear un verdadero centro analítico.

Dashboard ejecutivo.

Indicadores:

Headcount
Altas
Bajas
Rotación
Ausentismo
Tardanzas
Horas extras
Costo laboral
Vacaciones
Desempeño
Capacitación
Engagement
eNPS
Time-to-hire
Cost-per-hire
Vacantes
Diversidad cuando legalmente corresponda
Antigüedad
Distribución salarial
Promociones
Movilidad
SST

Filtros:

Empresa
Sede
Área
Cargo
Fecha
Manager

---

# 65. DASHBOARDS PERSONALIZABLES

Widgets drag & drop.

Cada rol puede tener dashboard diferente.

RRHH ≠ CEO ≠ Manager ≠ Payroll.

---

# 66. REPORTES

Centro de reportes.

Crear reportes predefinidos y personalizados.

Exportar:

Excel
CSV
PDF

Programar reportes para entrega automática posteriormente.

---

# 67. CONSTRUCTOR DE REPORTES

Usuario selecciona:

Fuente de datos
→ campos
→ filtros
→ agrupaciones
→ métricas
→ visualización

Guardar como reporte.

Controlar permisos sobre datos sensibles.

---

# 68. ALERTAS INTELIGENTES

Ejemplos:

15 contratos vencen pronto.

8 trabajadores tienen vacaciones acumuladas.

5 personas no completaron curso SST.

Rotación de un área aumentó.

10 documentos requieren firma.

3 evaluaciones están vencidas.

Nunca mostrar alertas sin explicación.

---

# 69. IA PARA RRHH

Preparar una capa de IA opcional.

Casos de uso:

Redactar descripción de puesto
Resumir CV
Preparar preguntas de entrevista
Resumir encuestas
Generar borradores de comunicados
Explicar métricas
Consultar información mediante lenguaje natural
Generar reportes
Detectar anomalías operativas

Ejemplo:

"¿Cuántas personas ingresaron este trimestre?"

La IA consulta datos permitidos y responde.

IMPORTANTE:

No permitir que IA tome automáticamente decisiones laborales de alto impacto como:

Contratar
Despedir
Reducir sueldo
Promover
Sancionar

La IA puede asistir; la decisión debe permanecer bajo control humano.

---

# 70. BÚSQUEDA GLOBAL

Buscar:

Personas
Documentos
Vacantes
Candidatos
Cursos
Solicitudes
Reportes

Respetando permisos.

---

# 71. IMPORTADOR MASIVO

Importar:

Empleados
Contratos
Saldos vacaciones
Salarios
Asistencia
Conceptos
Históricos

CSV / Excel.

Proceso:

Subir
→ mapear columnas
→ validar
→ previsualizar
→ importar

Nunca importar directamente sin validación.

---

# 72. EXPORTACIÓN

Exportación masiva respetando permisos.

CSV
Excel
PDF

---

# 73. API

Preparar API REST o equivalente.

Versionada.

Ejemplo:

/api/v1/employees
/api/v1/attendance
/api/v1/time-off
/api/v1/recruiting

Tokens y permisos por scopes.

---

# 74. WEBHOOKS

Preparar eventos:

employee.created
employee.updated
employee.terminated
leave.approved
payroll.closed
candidate.hired

Permitir integraciones externas.

---

# 75. INTEGRACIONES

Arquitectura desacoplada para:

ERP
Contabilidad
Bancos
Biométricos
Email
Calendarios
SSO
Firma
ATS externos
LMS externos
APIs

No acoplar el core a proveedores específicos.

---

# 76. CENTRO DE AUTOMATIZACIONES

Mostrar:

Reglas activas
Ejecuciones
Errores
Última ejecución
Próxima ejecución

Logs detallados.

---

# 77. TAREAS RRHH

Crear tareas internas.

Ejemplo:

Preparar contrato
Solicitar laptop
Registrar trabajador
Validar documentos

Responsable
Fecha
Estado
Prioridad

Relacionarlas con onboarding/offboarding/etc.

---

# 78. CALENDARIO RRHH

Calendario central:

Vacaciones
Ausencias
Cumpleaños
Aniversarios
Contratos
Capacitaciones
Evaluaciones
Entrevistas
Eventos

Filtros.

---

# 79. CONFIGURACIÓN POR EMPRESA

Cada empresa debe configurar:

Datos fiscales
Logo
Moneda
Zona horaria
Idioma
Calendario
Feriados
Políticas
Vacaciones
Horarios
Planilla
Documentos
Notificaciones

---

# 80. MULTIIDIOMA

Arquitectura i18n desde el inicio.

Inicialmente español.

Preparar:

English
Português

No escribir textos críticos directamente dentro de componentes si puede evitarse.

---

# 81. MULTIMONEDA

Especialmente para compensaciones y grupos multinacionales.

PEN
USD
EUR
etc.

---

# 82. LOCALIZACIÓN POR PAÍS

Arquitectura:

CORE HR
+
Country Packs

Ejemplo:

Core HR
├── Perú
├── Colombia
├── Chile
└── México

Cada paquete puede incorporar:

Normativa
Nómina
Feriados
Documentos
Declaraciones
Reglas

---

# 83. PERSONALIZACIÓN / WHITE LABEL

Permitir:

Logo
Nombre
Color principal
Favicon
Dominio personalizado posteriormente
Emails personalizados

Según plan.

---

# 84. MODO CLARO / OSCURO

Implementar ambos correctamente.

No limitarse a invertir colores.

---

# 85. EXPERIENCIA MÓVIL

Responsive real.

El colaborador desde móvil debe poder fácilmente:

Marcar asistencia
Solicitar vacaciones
Ver boletas
Firmar
Responder encuestas
Ver horario
Realizar solicitudes

---

# 86. NOTIFICACIONES

Centro de notificaciones.

Canales preparados:

In-app
Email
Push
WhatsApp mediante integración futura

Preferencias configurables.

---

# 87. BANDEJA "PENDIENTES"

Crear un Inbox central.

Ejemplo:

Tienes 8 pendientes.

2 vacaciones por aprobar
1 contrato por firmar
3 evaluaciones
1 gasto
1 onboarding

Esta funcionalidad debe simplificar mucho el trabajo diario.

---

# 88. COMANDO / CREACIÓN RÁPIDA

Botón:

+ Crear

Empleado
Vacante
Solicitud
Documento
Curso
Encuesta
Tarea

Dependiendo del permiso.

---

# 89. COPIAS DE SEGURIDAD

Administración SaaS:

Backups automáticos
Backups manuales
Historial
Restauración
Retención
Estado

Diseñar restauración segura.

---

# 90. PAPELERA

Soft delete cuando corresponda.

Permitir restauración.

No eliminar inmediatamente información laboral crítica.

---

# 91. RETENCIÓN DE DATOS

Preparar políticas de:

Retención
Anonimización
Eliminación
Exportación

Por organización y jurisdicción.

---

# 92. PRIVACIDAD

Diseñar controles para información personal y laboral.

Permitir gestionar:

Consentimientos cuando correspondan
Accesos
Descargas
Solicitudes de información
Anonimización

Mantener registro.

---

# 93. CENTRO DE CONFIGURACIÓN

Organizar:

Organización
Empresas
Sedes
Estructura
Usuarios
Roles
Permisos
Campos
Workflows
Asistencia
Vacaciones
Planilla
Desempeño
Reclutamiento
LMS
SST
Documentos
Integraciones
Notificaciones
Seguridad
API
Auditoría

---

# 94. CAMPOS PERSONALIZADOS

Permitir crear:

Texto
Número
Fecha
Selector
Multiselect
Checkbox
Usuario
Archivo
URL
Moneda
Porcentaje

Asignarlos a módulos.

---

# 95. WORKFLOW BUILDER

Crear constructor visual.

Nodos:

Inicio
Condición
Aprobación
Acción
Notificación
Espera
Fin

Debe ser reutilizable transversalmente.

---

# 96. DISEÑO DEL SISTEMA

No quiero una interfaz genérica generada rápidamente.

Debe verse como un SaaS empresarial moderno.

Utilizar:

Sidebar
Topbar
Breadcrumbs
Command palette
Cards
Tablas avanzadas
Drawers
Modales
Tabs
Timelines
Calendarios
Kanban
Dashboards
Gráficos
Filtros
Estados vacíos
Skeletons
Tooltips

Mantener consistencia visual.

---

# 97. NO SOBRECARGAR LA INTERFAZ

Aunque el sistema tenga muchas funcionalidades, debe sentirse sencillo.

Aplicar progressive disclosure.

Mostrar inicialmente lo importante y dejar configuraciones avanzadas en niveles secundarios.

---

# 98. TABLAS PROFESIONALES

Permitir:

Buscar
Filtrar
Ordenar
Seleccionar columnas
Ocultar columnas
Redimensionar
Paginar
Acciones masivas
Exportar
Guardar vista

---

# 99. ESTADOS VACÍOS

No mostrar simplemente:

"No hay registros."

Crear estados útiles:

"Aún no tienes procesos de onboarding."

[Crear plantilla]

---

# 100. VALIDACIONES

Frontend + backend.

Nunca confiar únicamente en frontend.

Mensajes claros.

---

# 101. PERFORMANCE

Preparar:

Paginación server-side
Lazy loading
Índices
Caching
Queues
Jobs
Optimización de consultas

Pensar en organizaciones con:

50
500
5,000
50,000 trabajadores.

---

# 102. ARQUITECTURA DE DATOS

Diseñar entidades relacionadas.

Ejemplo conceptual:

tenants
companies
locations
departments
positions
employees
employment_history
contracts
compensations
attendance
shifts
time_off_requests
payroll_periods
payroll_items
payslips
candidates
jobs
applications
interviews
onboarding_processes
documents
assets
performance_cycles
reviews
competencies
goals
courses
enrollments
surveys
benefits
expenses
workflows
approvals
notifications
audit_logs
roles
permissions

No es una lista rígida.

Diseña correctamente normalización y relaciones.

---

# 103. EVENT-DRIVEN

Eventos importantes deben poder generar acciones.

Ejemplo:

employee.hired

puede generar:

Crear onboarding
Solicitar documentos
Asignar cursos
Crear tareas
Notificar manager

---

# 104. INTEGRIDAD REFERENCIAL

Nunca eliminar un empleado y destruir automáticamente su historial.

Diferenciar:

Usuario
Persona
Empleado
Relación laboral

Esto es importante.

Una persona puede tener más de una relación laboral histórica.

---

# 105. UX DE INFORMACIÓN SENSIBLE

Salarios, salud ocupacional, denuncias, sanciones y documentos sensibles NO deben aparecer indiscriminadamente.

Aplicar permisos por campo/módulo.

---

# 106. HOME RRHH

Dashboard:

Headcount
Ingresos
Salidas
Ausencias hoy
Vacaciones
Contratos por vencer
Cumpleaños
Onboarding
Vacantes
Pendientes
Alertas
Rotación
Costo laboral

---

# 107. HOME MANAGER

Mi equipo
Quién está ausente
Solicitudes
Objetivos
Evaluaciones
Cumpleaños
Pendientes

---

# 108. HOME EMPLEADO

Mi perfil
Mi horario
Mis vacaciones
Mis documentos
Mis boletas
Mis objetivos
Mis cursos
Mis solicitudes

---

# 109. CENTRO DE APROBACIONES

Crear una bandeja única.

Ejemplo:

VACACIONES — 4
GASTOS — 2
HEADCOUNT — 1
HORAS EXTRA — 3

Aprobar/Rechazar.

Permitir acciones masivas cuando sea seguro.

---

# 110. LOGS TÉCNICOS

Separar:

Actividad usuario
Auditoría negocio
Logs técnicos

No mezclarlos.

---

# 111. MONITOREO SaaS

Superadmin:

Errores
Jobs fallidos
Uso API
Webhooks fallidos
Storage
Colas
Integraciones

---

# 112. FEATURE FLAGS

Permitir activar nuevas funciones por:

Tenant
Plan
Beta
País

Esto facilitará evolución del SaaS.

---

# 113. DEMO

Crear organización demo coherente.

Ejemplo:

Empresa Demo SAC

Gerencia General
RRHH
Ventas
Tecnología
Operaciones

Crear empleados relacionados.

No utilizar registros aleatorios sin sentido.

Los datos deben permitir probar todo el ciclo:

Vacante
→ candidato
→ contratación
→ onboarding
→ empleado
→ asistencia
→ planilla
→ evaluación
→ capacitación
→ vacaciones
→ offboarding.

---

# 114. NO CREAR BOTONES FALSOS

REGLA CRÍTICA.

Si aparece:

Crear
Editar
Eliminar
Aprobar
Rechazar
Descargar
Exportar
Filtrar
Buscar
Firmar
Duplicar
Restaurar

DEBE FUNCIONAR.

No quiero una maqueta llena de botones decorativos.

---

# 115. PERSISTENCIA

Los cambios deben persistir.

Si creo un empleado y cambio de pantalla, el empleado debe seguir existiendo.

Si apruebo vacaciones:

Actualizar solicitud
Actualizar saldo
Actualizar calendario
Actualizar dashboard
Generar actividad

Los módulos deben estar conectados.

---

# 116. REGLA DE "UNA SOLA FUENTE DE VERDAD"

No duplicar información innecesariamente.

Ejemplo:

El salario debe provenir de la relación laboral/compensación correspondiente y alimentar los procesos autorizados que lo necesiten.

El mismo dato no debe editarse independientemente en cinco módulos.

---

# 117. DISEÑO API-FIRST

La lógica importante debe estar disponible mediante servicios/APIs y no depender exclusivamente de la interfaz.

Esto facilitará:

App móvil
Integraciones
Automatizaciones
IA
Partners

---

# 118. MODULARIDAD COMERCIAL

Cada tenant puede contratar módulos diferentes.

Ejemplo:

CORE HR ✓
Asistencia ✓
Planillas ✓
ATS ✕
LMS ✕
Desempeño ✓

La arquitectura debe soportarlo sin crear versiones diferentes del código.

---

# 119. MENÚ PROPUESTO

Inicio

Personas
- Colaboradores
- Directorio
- Organigrama
- Equipos
- Posiciones

Tiempo
- Asistencia
- Horarios
- Turnos
- Vacaciones
- Ausencias
- Horas extras

Nómina
- Periodos
- Incidencias
- Cálculo
- Boletas
- Conceptos
- Préstamos

Talento
- Reclutamiento
- Onboarding
- Desempeño
- Objetivos
- Competencias
- Desarrollo
- Sucesión

Aprendizaje
- Cursos
- Planes
- Inscripciones
- Certificados

Experiencia
- Encuestas
- eNPS
- Reconocimientos
- Comunicaciones

Compensación
- Salarios
- Bandas
- Revisiones
- Beneficios

Operaciones
- Documentos
- Firma
- Activos
- Gastos
- Solicitudes

SST
- Riesgos
- Incidentes
- Inspecciones
- Salud ocupacional
- Capacitaciones

Analytics
- Dashboard
- People Analytics
- Reportes

Automatización
- Workflows
- Aprobaciones
- Reglas

Configuración

Adapta el menú según permisos y módulos contratados.

---

# 120. ESTRATEGIA DE IMPLEMENTACIÓN

NO intentes implementar todo superficialmente de una sola vez.

Construye por fases.

FASE 1 — FUNDACIÓN

Multitenancy
Autenticación
Usuarios
Roles
Permisos
Empresas
Estructura organizacional
Core HR
Ficha empleado
Auditoría

FASE 2 — OPERACIÓN RRHH

Contratos
Documentos
Vacaciones
Ausencias
Asistencia
Turnos
Portal empleado
Portal manager

FASE 3 — TALENTO

ATS
Onboarding
Offboarding
Desempeño
Objetivos
Competencias

FASE 4 — PAYROLL

Motor
Conceptos
Periodos
Incidencias
Simulación
Cálculo
Boletas
Perú

FASE 5 — DESARROLLO

LMS
Planes carrera
Sucesión
9-box
Compensaciones

FASE 6 — EXPERIENCIA

Encuestas
eNPS
Reconocimiento
Comunicaciones
HR Help Desk

FASE 7 — OPERACIÓN AVANZADA

SST
Activos
Gastos
Beneficios
Headcount

FASE 8 — INTELIGENCIA

People Analytics
Dashboards
Report builder
Alertas
IA

FASE 9 — PLATAFORMA SaaS

Planes
Billing
Feature flags
API
Webhooks
White label
Administración SaaS

---

# 121. PROCESO DE DESARROLLO

Para cada fase:

1. Analiza arquitectura.
2. Define entidades.
3. Define relaciones.
4. Define permisos.
5. Define servicios.
6. Implementa backend.
7. Implementa frontend.
8. Conecta datos reales.
9. Valida CRUD.
10. Valida permisos.
11. Valida integridad.
12. Prueba responsive.
13. Corrige errores.
14. Continúa.

---

# 122. SI YA EXISTE CÓDIGO

Si estamos continuando un desarrollo previo:

NO BORRES AUTOMÁTICAMENTE LO EXISTENTE.

Primero analiza:

EXISTE Y FUNCIONA
EXISTE PERO MEJORABLE
PARCIAL
NO EXISTE
DUPLICADO
OBSOLETO

Conserva funcionalidades útiles aunque no aparezcan expresamente en este documento.

Si una funcionalidad existente aporta valor, mantenla.

Si necesita cambios, refactorízala.

Solo elimina algo cuando exista una justificación técnica clara.

---

# 123. CONTINUIDAD AUTOMÁTICA

No quiero que después de cada pequeña modificación preguntes:

"¿Deseas que continúe?"

Continúa automáticamente con el siguiente paso lógico.

Solo solicita mi intervención cuando exista una decisión verdaderamente crítica que no puedas determinar técnicamente.

---

# 124. OBJETIVO FINAL

El producto final debe sentirse como una verdadera plataforma comercial de Recursos Humanos y no como un panel administrativo genérico.

Debe combinar:

Profundidad empresarial
+
Facilidad de uso
+
Automatización
+
People Analytics
+
Arquitectura SaaS
+
Localización por país
+
Seguridad
+
Escalabilidad
+
Experiencia moderna.

Quiero que una empresa pueda empezar utilizando solamente:

Core HR + Vacaciones

y posteriormente activar:

Asistencia
Planillas
Reclutamiento
Desempeño
LMS
SST
People Analytics
Compensaciones

SIN migrar a otro sistema.

El objetivo es crear una plataforma donde toda la información del ciclo de vida laboral se encuentre conectada.

La arquitectura debe ser suficientemente sólida para competir funcionalmente en el futuro con plataformas especializadas de RRHH, pero manteniendo una experiencia más sencilla, configurable y adaptada inicialmente al mercado peruano y posteriormente a Latinoamérica.

COMIENZA analizando la arquitectura necesaria y desarrolla la FASE 1.

No te limites a explicarme qué código debo hacer.

IMPLEMENTA el sistema.

No generes únicamente mockups.

No generes botones sin funcionalidad.

No avances dejando errores críticos detrás.

Construye una base sólida sobre la cual podamos continuar agregando módulos.