# -*- coding: utf-8 -*-
"""Artículos del blog de GestiaHR (v1.13). build.py los publica con la misma plantilla.

Reglas de contenido:
  - Tono claro y práctico, en español de Perú.
  - Ninguna cifra, plazo o tasa legal sin fuente: todo dato que dependa de la norma lleva [validar] y lo
    confirma el especialista laboral antes de publicar (WEB-8: «autor verificado»).
  - Cada artículo enlaza a la página del proceso o del producto que lo resuelve.
Campos: archivo, slug de producción, categoría, título, bajada, ícono, minutos, página relacionada, secciones [(id, título, html)].
"""

V = '<mark class="val">[validar]</mark>'


def p(*xs):
    return ''.join(f'<p>{x}</p>' for x in xs)


def ul(*xs):
    return '<ul>' + ''.join(f'<li>{x}</li>' for x in xs) + '</ul>'


def ol(*xs):
    return '<ol>' + ''.join(f'<li>{x}</li>' for x in xs) + '</ol>'


POSTS = [
 dict(f='articulo.html', slug='calendario-laboral-2026', cat='Calendario', icon='calendar_month', min=6, rel=('plame-y-t-registro.html', 'PLAME y T-Registro'),
  t='Calendario laboral 2026: fechas de PLAME, CTS y gratificaciones',
  d='Las obligaciones laborales del año en una sola página, mes por mes, para que no se te pase ninguna.',
  s=[('mensual', 'Lo que se repite cada mes', p(
        'Cada mes la empresa paga la planilla, entrega las boletas y declara en la PLAME. El día exacto de la declaración depende del último dígito del RUC y del cronograma que SUNAT publica para el año ' + V + '.',
        'También cada mes se mantiene al día el T-Registro: los ingresos se registran antes de que la persona empiece a trabajar o en el plazo que fija la norma, y los ceses dentro del plazo posterior al último día de labor ' + V + '.') +
        ul('<b>Pago de remuneraciones</b>: en la fecha pactada con tus colaboradores.',
           '<b>Boletas de pago</b>: se entregan dentro del plazo que fija la norma después del pago ' + V + '.',
           '<b>PLAME</b>: según el cronograma de SUNAT por último dígito de RUC ' + V + '.',
           '<b>Aportes a las AFP</b>: se declaran y pagan en AFPnet dentro de los primeros días hábiles del mes siguiente ' + V + '.')),
     ('beneficios', 'Los beneficios del año', '<table class="data"><tr><th>Mes</th><th>Obligación</th><th>Qué revisar</th></tr>'
        '<tr><td>Marzo o abril</td><td>Utilidades, si tu empresa está obligada ' + V + '</td><td>Días trabajados y remuneraciones del ejercicio.</td></tr>'
        '<tr><td>Mayo</td><td>Depósito de CTS del periodo noviembre-abril ' + V + '</td><td>Remuneración computable de abril y sexto de gratificación.</td></tr>'
        '<tr><td>Julio</td><td>Gratificación de Fiestas Patrias ' + V + '</td><td>Meses completos trabajados en el semestre y bonificación extraordinaria.</td></tr>'
        '<tr><td>Noviembre</td><td>Depósito de CTS del periodo mayo-octubre ' + V + '</td><td>Remuneración computable de octubre.</td></tr>'
        '<tr><td>Diciembre</td><td>Gratificación de Navidad y regularización de quinta categoría ' + V + '</td><td>Proyección anual de renta y retenciones del año.</td></tr></table>'),
     ('feriados', 'Feriados y días no laborables', p(
        'Los feriados nacionales son de descanso remunerado. Los días no laborables que declara el Gobierno para el sector público pueden aplicarse al sector privado si la empresa y el colaborador lo acuerdan, y se compensan según lo pactado ' + V + '.',
        'Carga el calendario de feriados del año al inicio, para que las vacaciones y la asistencia se calculen bien.')),
     ('consejos', 'Consejos para no atrasarte', ol(
        'Cierra las incidencias del mes (faltas, horas extra, descansos médicos) con anticipación.',
        'Revisa el cálculo antes de aprobar y compáralo con el mes anterior.',
        'Genera los archivos de PLAME y T-Registro desde el mismo cálculo, sin digitar dos veces.',
        'Guarda las constancias de presentación y de pago en el periodo.',
        'Programa recordatorios para cada fecha del cronograma de SUNAT.'))]),
 dict(f='blog-gratificacion-de-julio.html', slug='como-calcular-la-gratificacion', cat='Planilla', icon='payments', min=5, rel=('calculo-de-gratificaciones.html', 'Cálculo de gratificaciones'),
  t='Cómo calcular la gratificación de julio paso a paso',
  d='Qué entra en el cálculo, cómo se cuentan los meses trabajados y qué pasa con la bonificación extraordinaria.',
  s=[('quien', 'Quién tiene derecho', p(
        'Tienen derecho a gratificación los trabajadores del régimen general que estén en planilla, sin importar el tipo de contrato. En la pequeña empresa el beneficio es menor y en la microempresa no corresponde ' + V + '.',
        'Si el colaborador cesó antes de la fecha de pago, recibe la gratificación trunca en su liquidación.')),
     ('pasos', 'El cálculo en cuatro pasos', ol(
        '<b>Remuneración computable.</b> Suma la remuneración básica y los conceptos que se pagan de forma regular en dinero o en especie. Los conceptos variables entran si se recibieron con la regularidad que pide la norma ' + V + '.',
        '<b>Meses completos trabajados.</b> Cuenta los meses calendario completos del semestre (enero a junio para julio). Los días sueltos no suman un mes ' + V + '.',
        '<b>Gratificación.</b> Divide la remuneración computable entre seis y multiplícala por los meses completos.',
        '<b>Bonificación extraordinaria.</b> Suma el porcentaje que corresponde al aporte de salud que el empleador no paga sobre la gratificación: uno si el colaborador está en EsSalud y otro si está en una EPS ' + V + '.')),
     ('ejemplo', 'Un ejemplo sin cifras legales', p(
        'Ana gana un sueldo fijo y recibe asignación familiar. Ingresó el 10 de febrero. Para julio cuenta los meses completos de marzo a junio: cuatro. Su gratificación es la sexta parte de su remuneración computable por cuatro, más la bonificación extraordinaria sobre ese monto.',
        'En GestiaHR el cálculo muestra cada paso, para que puedas revisar por qué cada colaborador recibe lo que recibe.')),
     ('errores', 'Errores comunes', ul(
        'Contar meses incompletos como completos.',
        'Olvidar los conceptos regulares, como la asignación familiar.',
        'No considerar la gratificación en la proyección de quinta categoría del mes.',
        'Pagar fuera de la fecha límite ' + V + '.'))]),
 dict(f='blog-cts.html', slug='cts-quien-la-recibe-y-como-se-calcula', cat='CTS', icon='savings', min=5, rel=('calculo-de-cts.html', 'Cálculo de CTS'),
  t='CTS: quién la recibe y cómo se calcula',
  d='Los conceptos que suman, el sexto de gratificación, los periodos y lo que debes entregar a cada colaborador.',
  s=[('que-es', 'Qué es la CTS', p(
        'La compensación por tiempo de servicios es un beneficio que funciona como un seguro ante el cese. La empresa la deposita en la cuenta que elige cada colaborador, dos veces al año ' + V + '.')),
     ('quien', 'Quién la recibe', p(
        'Los trabajadores del régimen general con la jornada mínima que fija la norma ' + V + '. En la pequeña empresa corresponde un monto menor y en la microempresa no corresponde ' + V + '.')),
     ('calculo', 'Cómo se calcula', ol(
        '<b>Remuneración computable</b> del mes anterior al depósito: remuneración básica y conceptos regulares.',
        '<b>Sexto de gratificación</b>: se suma la sexta parte de la gratificación recibida en el semestre ' + V + '.',
        '<b>Tiempo de servicio</b> en el periodo: meses y días.',
        '<b>Monto</b>: la remuneración computable se divide entre doce por cada mes y entre trescientos sesenta por cada día ' + V + '.')),
     ('entrega', 'Lo que debes entregar', ul(
        'La hoja de liquidación de CTS a cada colaborador, dentro del plazo que fija la norma ' + V + '.',
        'La constancia del depósito.',
        'Si el colaborador cesa, la CTS trunca en su liquidación.')),
     ('consejo', 'Un consejo', p('Revisa que cada concepto de tu planilla tenga bien marcado si afecta a la CTS. Es el error que más se repite al migrar de una hoja de cálculo.'))]),
 dict(f='blog-record-vacacional.html', slug='record-vacacional', cat='Vacaciones', icon='event_available', min=4, rel=('vacaciones-y-record-vacacional.html', 'Vacaciones y récord vacacional'),
  t='Récord vacacional: lo que debes registrar',
  d='Cómo llevar los días generados, gozados, programados y pendientes de cada colaborador sin perder el control.',
  s=[('que-es', 'Qué es el récord vacacional', p(
        'Es el registro, por colaborador y por periodo, de los días de vacaciones que generó, los que gozó y los que tiene pendientes. La norma pide llevarlo y conservarlo ' + V + '.')),
     ('que-registrar', 'Qué debes registrar', ul(
        'La fecha de ingreso y el régimen laboral, porque de ellos depende cuántos días genera.',
        'Cada periodo vacacional: fecha de inicio, de fin y días que corresponden.',
        'Los días gozados, con la fecha y la constancia de la solicitud aprobada.',
        'Las ventas o adelantos de vacaciones, si los hay ' + V + '.',
        'Los días pendientes y la fecha en que vencen.')),
     ('ojo', 'Cuidado con los periodos vencidos', p(
        'Si un colaborador no goza sus vacaciones dentro del plazo, puede corresponder una indemnización vacacional ' + V + '. Por eso conviene revisar cada mes quién tiene periodos por vencer.')),
     ('como', 'Cómo llevarlo sin hojas de cálculo', ol(
        'Carga los saldos iniciales de cada colaborador.',
        'Deja que el sistema genere los días según la fecha de ingreso y el régimen.',
        'Pide que las solicitudes pasen por el portal, con aprobación del jefe.',
        'Revisa el aviso de periodos por vencer cada mes.'))]),
 dict(f='blog-errores-plame-t-registro.html', slug='errores-comunes-plame-t-registro', cat='SUNAT', icon='upload_file', min=5, rel=('plame-y-t-registro.html', 'PLAME y T-Registro'),
  t='PLAME y T-Registro: errores comunes al subir los archivos',
  d='Lo que más se repite cuando SUNAT rechaza un archivo, y cómo revisarlo antes de declarar.',
  s=[('t-registro', 'Errores en el T-Registro', ul(
        'Registrar el ingreso después del inicio de labores o fuera del plazo ' + V + '.',
        'Datos del documento de identidad que no coinciden con RENIEC o Migraciones.',
        'Olvidar la baja al cesar o registrarla con otra fecha que la de la liquidación.',
        'No actualizar el régimen pensionario o de salud cuando cambia.')),
     ('plame', 'Errores en la PLAME', ul(
        'Conceptos de planilla mal asociados a su código de la tabla de SUNAT, por ejemplo un ingreso marcado como no afecto.',
        'Días laborados y no laborados que no cuadran con el periodo.',
        'Diferencias entre lo declarado en la PLAME y lo pagado en AFPnet.',
        'Colaboradores en la planilla que no figuran en el T-Registro.')),
     ('revisar', 'Revisión antes de declarar', ol(
        'Compara el número de colaboradores de la planilla con el T-Registro.',
        'Revisa que cada concepto tenga su código y sus afectaciones validadas.',
        'Cuadra el total de aportes con AFPnet.',
        'Genera los archivos desde el mismo cálculo aprobado, nunca a mano.',
        'Guarda la constancia en el periodo.')),
     ('en-gestiahr', 'Cómo ayuda GestiaHR', p('GestiaHR valida los datos antes de generar los archivos y te muestra las alertas con el colaborador y el campo que hay que corregir.'))]),
 dict(f='blog-migrar-planilla-desde-excel.html', slug='migrar-la-planilla-desde-excel', cat='Gestión', icon='swap_horiz', min=6, rel=('software-de-planillas.html', 'Software de planillas'),
  t='Cómo pasar tu planilla de Excel a un sistema',
  d='Un plan de migración en cuatro pasos, con la prueba en paralelo que evita sorpresas en el primer mes.',
  s=[('antes', 'Antes de empezar', p('Elige un mes de inicio tranquilo, sin gratificación ni CTS. Reúne la planilla de los últimos meses, los saldos de vacaciones y la lista de conceptos que usas.')),
     ('pasos', 'La migración en cuatro pasos', ol(
        '<b>Ordena los datos maestros.</b> Colaboradores con su documento, fecha de ingreso, régimen, cargo, sueldo, sistema de pensiones y cuenta bancaria.',
        '<b>Configura tus conceptos.</b> Revisa cada concepto de tu Excel y define en el sistema si es ingreso, descuento o aporte, y a qué afecta.',
        '<b>Carga los saldos.</b> Vacaciones pendientes, préstamos en curso y acumulados del año para la quinta categoría.',
        '<b>Corre en paralelo.</b> Calcula el primer mes en los dos lados y compara boleta por boleta antes de pagar.')),
     ('paralelo', 'Por qué el paralelo importa', p('Las diferencias del primer mes casi siempre vienen de un concepto mal configurado o de un saldo inicial incompleto. Encontrarlas antes de pagar evita reprocesos y reclamos.')),
     ('checklist', 'Lista de verificación', ul(
        'Todos los colaboradores activos cargados y cuadrados con el T-Registro.',
        'Conceptos con sus afectaciones revisadas por el especialista.',
        'Saldos de vacaciones aprobados por RR. HH.',
        'Acumulados de quinta del año cargados.',
        'Primer mes en paralelo sin diferencias.'))]),
]
