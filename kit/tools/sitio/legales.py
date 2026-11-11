# -*- coding: utf-8 -*-
"""Borradores de los textos legales del sitio GestiaHR (v1.13).

Son borradores para que el área legal los revise y complete; no son asesoría legal.
Convenciones:
  [por definir]  dato que debe completar el negocio o legal (razón social, plazos, montos, jurisdicción).
  [validar]      referencia normativa o plazo que legal debe confirmar contra la norma vigente antes de publicar.
Cada página es una lista de (título de sección, HTML). build.py las publica con legal().
"""

RS = '[Razón social por definir]'
RUC = '[RUC por definir]'
DOM = '[domicilio por definir], Lima, Perú'
CORREO_PRIV = 'privacidad@gestiahr.com'
CORREO_SOP = 'soporte@gestiahr.com'
VIGENCIA = '[fecha de entrada en vigor por definir]'


def p(*xs):
    return ''.join(f'<p>{x}</p>' for x in xs)


def ul(*xs):
    return '<ul>' + ''.join(f'<li>{x}</li>' for x in xs) + '</ul>'


# ---------------------------------------------------------------- términos y condiciones
TERMINOS = [
 ('Quiénes somos y aceptación', p(
    f'GestiaHR es un servicio de {RS}, con {RUC} y domicilio en {DOM} (en adelante, «GestiaHR» o «nosotros»).',
    'Estos términos regulan el uso del sitio gestiahr.com y del servicio GestiaHR por parte de la empresa que lo contrata (el «Cliente») y de las personas a las que el Cliente da acceso (los «Usuarios»). Quien crea una cuenta a nombre de una empresa declara que tiene facultades para obligarla.',
    'Si el Cliente firmó una orden de compra o un contrato con condiciones particulares, esas condiciones prevalecen sobre estos términos en lo que regulen de forma distinta.')),
 ('Definiciones', ul(
    '<b>Servicio:</b> el software GestiaHR en modalidad de suscripción, con los productos y módulos del plan contratado.',
    '<b>Organización:</b> el espacio del Cliente en GestiaHR, con su subdominio, sus empresas (razones sociales) y sus Usuarios.',
    '<b>Datos del Cliente:</b> toda la información que el Cliente o sus Usuarios registran en el Servicio, incluidos los datos personales de sus colaboradores.',
    '<b>Plan y complementos:</b> la combinación de productos, límites y funciones contratada, según la página de precios o la orden de compra.')),
 ('El servicio', p(
    'GestiaHR es un software en línea para la gestión de personas y planillas. Cada Organización trabaja en su propio subdominio y sus datos están separados de los de otras organizaciones.',
    'GestiaHR calcula la planilla, genera boletas y prepara los archivos para las entidades (por ejemplo, PLAME y T-Registro) con los parámetros legales vigentes que publica en el sistema. <b>La presentación ante SUNAT, AFPnet, bancos u otras entidades la hace el Cliente</b>, que revisa y aprueba cada cálculo antes de cerrarlo.',
    'Podemos mejorar, cambiar o retirar funciones. Si un cambio reduce de forma importante lo contratado, avisaremos con [plazo de aviso por definir] de anticipación y el Cliente podrá terminar el contrato sin penalidad.')),
 ('Cuentas, usuarios y seguridad', ul(
    'El Cliente crea a sus Usuarios, les asigna roles y permisos y es responsable de lo que hagan dentro de su Organización.',
    'Cada Usuario es personal: no se comparte. El Cliente debe mantener al día quién tiene acceso y retirar el de quienes dejan la empresa.',
    'La verificación en dos pasos es obligatoria para los roles con acceso a datos sensibles, como planilla, salud o denuncias.',
    f'El Cliente debe avisarnos sin demora a {CORREO_SOP} si sospecha de un acceso no autorizado.')),
 ('Prueba gratis', p(
    'La prueba gratis no requiere tarjeta y dura [días de prueba por definir]. Al terminar, el Cliente elige un plan o la Organización pasa a solo lectura durante [plazo por definir] y luego se elimina, salvo que el Cliente pida exportar sus datos antes.',
    'Durante la prueba el Servicio se ofrece «tal cual», sin los niveles de servicio de los planes pagados.')),
 ('Planes, precios, pagos y facturación', ul(
    'Los precios de cada plan y complemento son los publicados en la página de precios o en la orden de compra al momento de contratar, en soles y sin IGV, salvo que se indique otra cosa.',
    'El cobro es mensual o anual por adelantado, según lo elegido. Emitimos factura electrónica a nombre del Cliente.',
    'El límite de colaboradores cuenta a las personas únicas con al menos una relación laboral activa en el periodo. Si el Cliente lo supera, se lo avisamos y podrá pasar a un plan mayor o sumar el complemento que corresponda.',
    'Si un pago se atrasa más de [plazo por definir], podemos suspender el acceso después de avisarlo. Los datos no se borran por la suspensión.',
    'Podemos cambiar los precios con [plazo de aviso por definir] de aviso; el cambio aplica desde la siguiente renovación.',
    'Política de devoluciones: [por definir con el área legal y finanzas].')),
 ('Datos del Cliente', p(
    'El Cliente es el titular de los Datos del Cliente y el responsable de su tratamiento. GestiaHR los trata como <b>encargado</b>, solo para prestar el Servicio y según las instrucciones del Cliente, conforme al <a href="tratamiento-de-datos.html">acuerdo de tratamiento de datos</a>, que forma parte de estos términos.',
    'El Cliente garantiza que tiene una base legal para registrar los datos de sus colaboradores y que les ha informado sobre ese tratamiento.',
    'El Cliente puede exportar sus datos en cualquier momento en formatos de uso común.')),
 ('Uso aceptable', ul(
    'No usar el Servicio para fines ilícitos ni para registrar información que el Cliente no tenga derecho a tratar.',
    'No intentar acceder a datos de otras organizaciones, probar la seguridad sin autorización escrita ni sobrecargar el Servicio de forma deliberada.',
    'No copiar, revender ni hacer ingeniería inversa del software, salvo lo que la ley permita.')),
 ('Disponibilidad y soporte', p(
    'Hacemos lo razonable para que el Servicio esté disponible de forma continua, con un objetivo de disponibilidad de [porcentaje por definir] al mes, sin contar los mantenimientos avisados. El detalle está en el acuerdo de nivel de servicio [por definir].',
    f'El soporte se atiende en español por {CORREO_SOP} y por los canales de cada plan, en el horario publicado en el centro de ayuda.')),
 ('Propiedad intelectual', p(
    'El software, la marca GestiaHR, el diseño y la documentación son de GestiaHR o de sus licenciantes. El Cliente recibe una licencia de uso no exclusiva e intransferible mientras dure su suscripción.',
    'Si el Cliente nos envía sugerencias, podemos usarlas para mejorar el Servicio sin obligación de pago.')),
 ('Confidencialidad', p(
    'Cada parte guarda en reserva la información no pública que reciba de la otra y la usa solo para este contrato. La obligación sigue durante [plazo por definir] después de terminado.')),
 ('Responsabilidad', p(
    'GestiaHR responde por los daños directos que cause por incumplir estos términos, hasta un monto máximo igual a [tope por definir con el área legal, por ejemplo lo pagado en los doce meses anteriores].',
    'GestiaHR no responde por las decisiones del Cliente basadas en cálculos que el Cliente revisó y aprobó, ni por multas derivadas de información que el Cliente registró de forma incorrecta o presentó fuera de plazo. [por definir con el área legal].',
    'Nada de lo anterior limita la responsabilidad que la ley no permita limitar, como la que nace de dolo o culpa inexcusable [validar: Código Civil, art. 1328].')),
 ('Duración y terminación', ul(
    'La suscripción dura el periodo contratado y se renueva sola por periodos iguales, salvo que una de las partes avise que no renovará antes de [plazo por definir].',
    'El Cliente puede dar de baja su Organización desde la consola. Tendrá [plazo de gracia por definir] para exportar sus datos; luego los eliminamos de forma certificada, salvo lo que la ley nos obligue a conservar.',
    'Podemos terminar el contrato si el Cliente incumple de forma grave estos términos y no lo corrige en [plazo por definir] después de avisarle.')),
 ('Cambios a estos términos', p(
    'Podemos actualizar estos términos. Si el cambio es importante, avisaremos por correo y dentro del Servicio con [plazo por definir] de anticipación. Seguir usando el Servicio después de esa fecha significa aceptar los nuevos términos.')),
 ('Ley aplicable, reclamos y controversias', p(
    'Estos términos se rigen por las leyes del Perú.',
    'Antes de iniciar un proceso, las partes intentarán resolver la controversia de buena fe durante [plazo por definir]. Si no lo logran, se someterá a [por definir con el área legal: jueces de Lima Cercado o arbitraje de un centro de arbitraje de Lima].',
    'Puedes registrar un reclamo o una queja en nuestro <a href="libro-reclamaciones.html">Libro de Reclamaciones</a> [validar: Código de Protección y Defensa del Consumidor, Ley N.° 29571, y su reglamento del Libro de Reclamaciones].')),
 ('Contacto', p(f'{RS} · {RUC} · {DOM} · {CORREO_SOP}.', f'Fecha de entrada en vigor: {VIGENCIA}.')),
]

# ---------------------------------------------------------------- política de privacidad
PRIVACIDAD = [
 ('A quién se aplica esta política', p(
    'Esta política explica cómo tratamos los datos personales de las personas que visitan gestiahr.com, piden una demo, se registran, se suscriben al boletín o son usuarios de un cliente.',
    'Los datos de los colaboradores que cada empresa cliente registra en el sistema los trata la empresa como responsable; nosotros solo como encargado. Para eso se aplica el <a href="tratamiento-de-datos.html">acuerdo de tratamiento de datos</a> y la política de privacidad de esa empresa.')),
 ('Quién es el responsable', p(
    f'{RS}, con {RUC} y domicilio en {DOM}. Contacto para temas de datos personales: {CORREO_PRIV}.',
    '[por definir: nombre o cargo del oficial de datos personales, si se designa, validar: reglamento de la Ley N.° 29733].')),
 ('Qué datos tratamos', '<table class="data"><tr><th>Cuándo</th><th>Datos</th></tr>'
    '<tr><td>Visitas al sitio</td><td>Dirección IP, navegador, páginas vistas y, si lo aceptas, cookies de medición.</td></tr>'
    '<tr><td>Demo, contacto, partners o lista de espera</td><td>Nombre, correo de trabajo, celular, empresa, RUC, número de colaboradores y la página desde la que escribiste.</td></tr>'
    '<tr><td>Registro y suscripción</td><td>Los datos anteriores, más tu contraseña (guardada cifrada), el subdominio y los datos de facturación de la empresa.</td></tr>'
    '<tr><td>Uso del sistema</td><td>Registros de acceso y actividad, dispositivo y dirección IP, para la seguridad y la auditoría.</td></tr>'
    '<tr><td>Chat del sitio</td><td>Lo que escribas en el asistente y, si lo dejas, tu correo.</td></tr>'
    '<tr><td>Libro de Reclamaciones</td><td>Los datos que exige el formulario del reclamo.</td></tr></table>'
    '<p>No pedimos datos sensibles en el sitio. No tratamos datos de menores de edad en el sitio, salvo los del apoderado en el Libro de Reclamaciones.</p>'),
 ('Para qué los usamos y con qué base', ul(
    '<b>Responder tu solicitud</b> (demo, contacto, partners, lista de espera): para atender lo que pediste.',
    '<b>Prestar el servicio y facturar</b>: porque es necesario para ejecutar el contrato.',
    '<b>Seguridad, prevención de fraude y auditoría</b>: para proteger el servicio y cumplir obligaciones legales.',
    '<b>Enviarte información comercial y el boletín</b>: solo con tu consentimiento, que puedes retirar cuando quieras.',
    '<b>Medir y mejorar el sitio</b> con cookies de medición: solo si las aceptas.',
    'Atender reclamos y requerimientos de autoridades: por obligación legal.',
    '[validar: bases legales y supuestos de excepción al consentimiento del art. 14 de la Ley N.° 29733 y su reglamento vigente].')),
 ('Banco de datos', p(
    'Los datos se guardan en los bancos de datos de [razón social] inscritos ante la Autoridad Nacional de Protección de Datos Personales con los nombres y códigos [por definir: «Clientes y prospectos», «Usuarios del servicio», «Libro de Reclamaciones»] [validar: inscripción en el Registro Nacional de Protección de Datos Personales].')),
 ('Con quién los compartimos', p(
    'Con proveedores que nos ayudan a prestar el servicio, bajo contrato y solo para ese fin: alojamiento en la nube, correo y notificaciones, pagos y facturación electrónica, atención al cliente y medición del sitio. La lista actualizada de proveedores está en [enlace por definir].',
    'Con autoridades, cuando una norma o una orden lo exige. No vendemos datos personales.')),
 ('Transferencia internacional', p(
    'El servicio se aloja en [proveedor y región por definir], fuera del Perú (ADR 0007). Por eso tus datos pueden almacenarse en ese país. Lo hacemos con proveedores que dan garantías de protección adecuadas y con cláusulas contractuales [validar: flujo transfronterizo según el art. 15 de la Ley N.° 29733 y su reglamento, y si corresponde comunicarlo a la Autoridad].')),
 ('Cuánto tiempo los conservamos', ul(
    'Prospectos que no contratan: [plazo por definir] desde el último contacto.',
    'Clientes y usuarios: mientras dure el contrato y luego [plazo por definir] para atender obligaciones legales, tributarias y posibles reclamos.',
    'Registros de seguridad y auditoría: [plazo por definir].',
    'Libro de Reclamaciones: [plazo por definir, validar con su reglamento].',
    'Pasado el plazo, los eliminamos o los anonimizamos.')),
 ('Cómo los protegemos', p(
    'Cifrado en tránsito y en reposo, separación de los datos de cada organización, verificación en dos pasos para los roles sensibles, registro de accesos, copias de respaldo y revisión periódica de permisos. Si ocurre un incidente que afecte tus datos, te avisaremos y lo comunicaremos a la Autoridad según la norma [validar: plazo y forma de comunicar incidentes en el reglamento vigente].')),
 ('Tus derechos', p(
    f'Puedes pedir acceso, rectificación, cancelación y oposición (derechos ARCO), así como revocar tu consentimiento, escribiendo a {CORREO_PRIV} con una copia de tu documento de identidad o desde tu cuenta. Respondemos en los plazos de la norma [validar: plazos del reglamento de la Ley N.° 29733].',
    'Si eres colaborador de una empresa cliente, escribe primero a tu empleador, que es el responsable de tus datos; nosotros lo ayudaremos a responderte.',
    'Si no estás conforme con nuestra respuesta, puedes acudir a la Autoridad Nacional de Protección de Datos Personales del Ministerio de Justicia y Derechos Humanos.')),
 ('Cookies', p('Usamos cookies necesarias y, solo si las aceptas, cookies de medición. El detalle está en la <a href="cookies.html">política de cookies</a>.')),
 ('Cambios a esta política', p(f'Publicaremos aquí cualquier cambio con su fecha. Si el cambio es importante, te avisaremos por correo. Fecha de entrada en vigor: {VIGENCIA}.')),
]

# ---------------------------------------------------------------- política de cookies
COOKIES = [
 ('Qué son las cookies', p('Son pequeños archivos que el sitio guarda en tu navegador para recordar tus preferencias o medir cómo se usa. Usamos también tecnologías parecidas, como el almacenamiento local del navegador.')),
 ('Qué cookies usamos', '<table class="data"><tr><th>Tipo</th><th>Para qué</th><th>¿Necesita tu permiso?</th><th>Duración</th></tr>'
    '<tr><td>Necesarias</td><td>Mantener tu sesión, recordar tu elección de cookies y proteger los formularios.</td><td>No</td><td>Sesión o [por definir]</td></tr>'
    '<tr><td>De preferencias</td><td>Recordar el idioma y lo que elegiste en el comparador de planes.</td><td>No</td><td>[por definir]</td></tr>'
    '<tr><td>De medición</td><td>Contar visitas y ver qué páginas se usan, de forma agregada.</td><td>Sí</td><td>[por definir]</td></tr>'
    '<tr><td>De publicidad</td><td>Medir campañas y mostrar anuncios de GestiaHR en otros sitios.</td><td>Sí</td><td>[por definir]</td></tr></table>'
    '<p>La lista de cada cookie, con su proveedor y su duración exacta, se genera desde la consola del sitio: [enlace por definir].</p>'),
 ('Cómo elegir', p(
    'La primera vez que entras te preguntamos. Con «Solo necesarias», no cargamos ninguna etiqueta de medición ni de publicidad. Puedes cambiar tu elección en cualquier momento desde el enlace «Cookies» del pie de página.',
    'También puedes borrarlas o bloquearlas desde la configuración de tu navegador; si bloqueas las necesarias, algunas partes del sitio pueden no funcionar.')),
 ('Dentro del sistema', p('Dentro de GestiaHR (el subdominio de cada empresa) solo usamos cookies necesarias para la sesión y la seguridad. No usamos cookies de publicidad en el sistema.')),
 ('Más información', p(f'Escríbenos a {CORREO_PRIV}. Esta política complementa la <a href="privacidad.html">política de privacidad</a>. Fecha de entrada en vigor: {VIGENCIA}.')),
]

# ---------------------------------------------------------------- acuerdo de tratamiento de datos (encargo)
ACUERDO = [
 ('Partes y objeto', p(
    f'Este acuerdo se celebra entre la empresa que contrata GestiaHR (el «Cliente», como <b>titular del banco de datos y responsable del tratamiento</b>) y {RS}, con {RUC} («GestiaHR», como <b>encargado del tratamiento</b>), y forma parte de los <a href="terminos.html">términos y condiciones</a>.',
    'Regula el tratamiento que GestiaHR hace, por cuenta del Cliente, de los datos personales de los colaboradores, postulantes, derechohabientes y demás personas que el Cliente registra en el Servicio [validar: art. 2 y 38 de la Ley N.° 29733 y las disposiciones sobre encargo de su reglamento].')),
 ('Datos y personas comprendidos', ul(
    '<b>Personas:</b> colaboradores actuales y anteriores, postulantes, derechohabientes, contactos de emergencia, locadores y usuarios del Cliente.',
    '<b>Datos:</b> de identificación y contacto; laborales y de remuneración; bancarios; previsionales y tributarios; de asistencia y vacaciones; de desempeño y formación; y documentos del legajo.',
    '<b>Datos sensibles</b>, solo si el Cliente usa los módulos que los requieren: salud (descansos médicos, exámenes de seguridad y salud en el trabajo), afiliación sindical, denuncias y, si se activa, datos biométricos de marcación. El Cliente es responsable de contar con la base legal y, cuando corresponda, el consentimiento por escrito [validar].')),
 ('Finalidad e instrucciones', p(
    'GestiaHR trata los datos solo para prestar el Servicio contratado (gestión de personas, planilla, declaraciones, boletas, vacaciones y los demás módulos activos), soporte técnico, seguridad y copias de respaldo.',
    'Las instrucciones del Cliente son este acuerdo, la configuración que hace en el Servicio y las que dé por escrito. Si una instrucción nos parece contraria a la norma, se lo avisaremos antes de cumplirla.',
    'GestiaHR no usa los datos para fines propios, no los vende y no los usa para entrenar modelos de inteligencia artificial. El asistente de IA, si el Cliente lo activa, trabaja dentro de las condiciones del ADR correspondiente [por definir con el proveedor de IA, decisión A-09].')),
 ('Obligaciones de GestiaHR', ul(
    'Aplicar las medidas de seguridad técnicas y organizativas del anexo de seguridad, acordes con la norma [validar: Directiva de Seguridad de la Información de la Autoridad y el reglamento vigente].',
    'Mantener los datos de cada organización separados de los de las demás.',
    'Exigir confidencialidad a su personal y dar acceso solo a quien lo necesite, con registro de cada acceso a datos del Cliente.',
    'Ayudar al Cliente a atender los derechos ARCO de los titulares con las herramientas del Servicio (exportación, rectificación, supresión).',
    'Avisar al Cliente sin demora indebida, y en todo caso dentro de [plazo por definir, validar con el plazo legal de comunicación a la Autoridad], de cualquier incidente que afecte sus datos, con la información disponible para que el Cliente cumpla sus propias obligaciones.',
    'Permitir que el Cliente verifique el cumplimiento de este acuerdo mediante informes, certificaciones o, con aviso previo de [plazo por definir], una auditoría razonable.')),
 ('Subencargados', p(
    'El Cliente autoriza a GestiaHR a usar subencargados (por ejemplo, el proveedor de nube, correo, mensajería y firma electrónica). La lista vigente está en [enlace por definir]. GestiaHR avisará con [plazo por definir] antes de sumar uno nuevo, y el Cliente podrá oponerse por razones fundadas.',
    'Cada subencargado firma obligaciones de protección iguales o mayores a las de este acuerdo. GestiaHR responde ante el Cliente por lo que hagan.')),
 ('Transferencia internacional', p(
    'Los datos se alojan en [proveedor y región por definir], fuera del Perú (ADR 0007), con garantías contractuales de protección. El Cliente lo acepta al contratar y, si su política lo exige, lo informa a sus colaboradores [validar: flujo transfronterizo, art. 15 de la Ley N.° 29733].')),
 ('Obligaciones del Cliente', ul(
    'Contar con la base legal para cada tratamiento e informar a sus colaboradores, incluido que GestiaHR es su encargado.',
    'Inscribir sus bancos de datos ante la Autoridad cuando corresponda [validar].',
    'Configurar los roles y permisos de sus usuarios y revisarlos periódicamente.',
    'No registrar datos que no necesita para la finalidad declarada.')),
 ('Conservación y devolución', p(
    'Mientras dure el contrato, el Cliente decide cuánto conserva cada dato según los parámetros de retención del Servicio.',
    'Al terminar, el Cliente puede exportar todos sus datos durante [plazo de gracia por definir, ADR 0012]. Luego GestiaHR los elimina de forma segura, incluidas las copias de respaldo en su siguiente ciclo de [plazo por definir], y entrega una constancia de eliminación, salvo los datos que una norma obligue a conservar.')),
 ('Responsabilidad y vigencia', p(
    'Cada parte responde por sus propios incumplimientos de la norma de protección de datos. Los límites de responsabilidad de los términos y condiciones se aplican a este acuerdo [por definir con el área legal].',
    'Este acuerdo rige mientras GestiaHR trate datos del Cliente, aunque el contrato principal haya terminado.')),
 ('Anexo de seguridad', ul(
    'Aislamiento por organización en la base de datos con seguridad a nivel de fila.',
    'Cifrado en tránsito (TLS) y en reposo; cifrado adicional de campos sensibles.',
    'Verificación en dos pasos obligatoria para roles con datos sensibles; contraseñas guardadas con algoritmos de resumen seguros.',
    'Registro inalterable de auditoría y de accesos a datos sensibles.',
    'Copias de respaldo cifradas con pruebas de restauración periódicas.',
    'Gestión de vulnerabilidades, revisiones de código y pruebas de seguridad antes de cada versión.',
    'Plan de respuesta a incidentes con responsables y tiempos definidos [por definir].')),
]
