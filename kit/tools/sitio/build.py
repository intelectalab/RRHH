#!/usr/bin/env python3
"""Genera el sitio comercial de GestiaHR (diseño de referencia).
Uso, desde la raíz del repositorio: python3 kit/tools/sitio/build.py [kit/docs/web/sitio]
Fuentes: base.css y extra.css (estilos), inicio-cuerpo.html (cuerpo del inicio), capturas en img/ y el prototipo."""
import os, re, sys, shutil, html, json
HERE = os.path.dirname(os.path.abspath(__file__))
KIT = os.path.abspath(os.path.join(HERE, '..', '..'))
OUT = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else os.path.join(KIT, 'docs', 'web', 'sitio'))
os.makedirs(os.path.join(OUT, 'assets', 'img'), exist_ok=True)

# ---------------------------------------------------------------- piezas comunes
PRODUCTS = [
    # clave, nombre, sigla, color, ruta, resumen, estado
    ('personas', 'Gestia Personas', 'HRIS', 'var(--hris)', 'personas.html', 'Legajo digital, contratos y organigrama.', 'ok'),
    ('planilla', 'Gestia Planilla', 'PAY', 'var(--pay)', 'planilla.html', 'Cálculo de planilla, boletas, PLAME y T-Registro.', 'ok'),
    ('tiempo', 'Gestia Tiempo', 'WFM', 'var(--wfm)', 'tiempo.html', 'Vacaciones y ausencias. Asistencia y turnos, pronto.', 'ok'),
    ('talento', 'Gestia Talento', 'TMS', 'var(--tms)', 'talento.html', 'Selección, desempeño y carrera. Próximamente.', 'soon'),
    ('formacion', 'Gestia Formación', 'LMS', 'var(--lms)', 'formacion.html', 'Tus propios cursos y certificados. Próximamente.', 'soon'),
]

VERSION_KIT = 'v1.12'

# ---------------------------------------------------------------- marca
sys.path.insert(0, HERE)
from marca import g_mark, logo_svg   # logo de GestiaHR (marca.py)
LOGO_HTML = f'<span class="mk">{g_mark()}</span><span class="wm">Gestia<span>HR</span></span>'
OLD_LOGO = '<span class="mk">G</span>GestiaHR'

# ---------------------------------------------------------------- SEO
# Cada página indexable lleva título, descripción, canónica, Open Graph y datos estructurados propios.
# En el producto esto sale del CMS (WEB-9); aquí se fija para el diseño de referencia.
SITE = 'https://gestiahr.com'
ROUTE = {'inicio.html': '/', 'articulo.html': '/blog/calendario-laboral-2026'}
def url_of(fname):
    return SITE + ROUTE.get(fname, '/' + fname[:-5])
# Páginas que no se indexan: pasos de un flujo, cuenta, confirmaciones y herramientas internas
NOINDEX = {'bienvenida.html', 'demo-confirmada.html', 'reclamo-confirmado.html', 'verificacion.html', 'recuperar.html',
           'ingresar.html', 'consola-sitio-web.html', 'portada.html', 'sistema.html'}
OG_IMG = {'personas.html': 'og-personas.png', 'planilla.html': 'og-planilla.png', 'tiempo.html': 'og-tiempo.png',
          'talento.html': 'og-talento.png', 'formacion.html': 'og-formacion.png', 'suite.html': 'og-suite.png'}
ORG = {'@type': 'Organization', '@id': SITE + '/#org', 'name': 'GestiaHR', 'url': SITE + '/', 'logo': SITE + '/assets/img/logo-gestiahr-512.png',
       'email': 'soporte@gestiahr.com', 'areaServed': 'PE', 'address': {'@type': 'PostalAddress', 'addressLocality': 'Lima', 'addressCountry': 'PE'}}
# Título y descripción para buscadores (el título de la pestaña del navegador). Si una página no está aquí, usa los de su llamada a page().
SEO = {
 'inicio.html': ('Software de planillas y RR. HH. en Perú | GestiaHR', 'Planilla, boletas firmadas, PLAME, T-Registro, vacaciones y legajo digital en un solo sistema para empresas del Perú. Prueba gratis sin tarjeta.'),
 'personas.html': ('Legajo digital y gestión de colaboradores | Gestia Personas', 'Ficha y legajo digital, contratos con aviso de vencimiento, organigrama y portal del colaborador. Incluido en todos los planes de GestiaHR.'),
 'planilla.html': ('Software de planilla con PLAME y boletas | Gestia Planilla', 'Calcula la planilla de régimen general, MYPE y agrario con gratificaciones, CTS, liquidaciones y quinta. Boletas firmadas, PLAME y T-Registro.'),
 'tiempo.html': ('Software de vacaciones, permisos y asistencia | Gestia Tiempo', 'Saldos de vacaciones, solicitudes y aprobaciones desde el celular y récord vacacional al día. Asistencia y turnos, muy pronto.'),
 'talento.html': ('Selección y evaluación de desempeño | Gestia Talento', 'Selección de personal, incorporación, evaluaciones de desempeño y planes de carrera conectados con la ficha del colaborador. Únete a la lista de espera.'),
 'formacion.html': ('Plataforma de capacitación para empresas | Gestia Formación', 'Sube tus cursos, asígnalos por puesto y entrega certificados. LMS conectado con la ficha de cada colaborador. Únete a la lista de espera.'),
 'suite.html': ('Suite de RR. HH. (HCM) para empresas del Perú | GestiaHR', 'Personas, Tiempo, Planilla, Talento y Formación en una sola plataforma, con una ficha por colaborador y un solo contrato.'),
 'precios.html': ('Precios y planes del software de planillas | GestiaHR', 'Planes Esencial, Profesional, Corporativo y Empresas, en soles. Suma productos por colaborador y prueba gratis sin tarjeta.'),
 'software-de-planillas.html': ('Software de planillas en Perú: qué debe tener | GestiaHR', 'Qué es un software de planillas, qué calcula, qué regímenes debe soportar y cómo elegirlo. Guía para empresas peruanas.'),
 'retail.html': ('Software de RR. HH. para retail y cadenas | GestiaHR', 'Altas y ceses desde la tienda, vacaciones por sede y planilla de varias razones sociales para empresas con muchas tiendas.'),
 'partners.html': ('Programa de partners para estudios contables | GestiaHR', 'Lleva la planilla de todas las empresas que atiendes desde un solo acceso. Programa para estudios contables y consultoras de RR. HH.'),
 'calculadora.html': ('Calculadora de costo laboral en Perú | GestiaHR', 'Estima cuánto le cuesta a tu empresa un colaborador al mes según su sueldo y su régimen laboral.'),
 'blog.html': ('Blog de planillas y RR. HH. en Perú | GestiaHR', 'Guías prácticas sobre planilla, CTS, gratificaciones, vacaciones, PLAME y gestión de personas en el Perú.'),
 'demo.html': ('Agenda una demo de GestiaHR', 'Una reunión en línea con un asesor para ver GestiaHR con un caso parecido al de tu empresa.'),
 'registro.html': ('Prueba GestiaHR gratis, sin tarjeta', 'Crea tu cuenta en tres pasos con el RUC de tu empresa y empieza con datos de ejemplo o con los tuyos.'),
 'terminos.html': ('Términos y condiciones | GestiaHR', 'Condiciones de uso del servicio GestiaHR: cuentas, prueba gratis, planes, pagos, datos de la empresa y Libro de Reclamaciones.'),
 'privacidad.html': ('Política de privacidad | GestiaHR', 'Cómo GestiaHR trata los datos personales de visitantes, clientes y colaboradores, conforme a la Ley N.° 29733 y su reglamento.'),
 'cookies.html': ('Política de cookies | GestiaHR', 'Qué cookies usa el sitio de GestiaHR, para qué sirve cada tipo y cómo aceptar, rechazar o cambiar tu elección.'),
 'tratamiento-de-datos.html': ('Acuerdo de tratamiento de datos | GestiaHR', 'Cómo GestiaHR trata, como encargado, los datos de los colaboradores de sus clientes: finalidad, seguridad, subencargados y devolución.'),
 'libro-reclamaciones.html': ('Libro de Reclamaciones | GestiaHR', 'Registra un reclamo o una queja sobre el servicio de GestiaHR.'),
}
# Migas de pan: lista de (nombre, página). La última no lleva enlace.
CRUMBS = {}
_FAQ = []        # preguntas del faq() de la página en construcción, para FAQPage
INDEXABLES = []  # páginas para sitemap.xml
def ld_json(items):
    g = {'@context': 'https://schema.org', '@graph': items}
    return '<script type="application/ld+json">' + json.dumps(g, ensure_ascii=False, separators=(',', ':')).replace('</', '<\\/') + '</script>\n'
def strip_tags(t):
    return re.sub(r'<[^>]+>', '', t)

def head(title, desc='', fname='', ld=None):
    idx = fname and fname not in NOINDEX
    og = OG_IMG.get(fname, 'og-gestiahr.png')
    seo = ''
    if fname:
        seo = (f'<link rel="canonical" href="{url_of(fname)}">\n' if idx else '') + \
              f'<meta name="robots" content="{"index, follow" if idx else "noindex, nofollow"}">\n' + \
              f'<meta property="og:type" content="{"article" if fname == "articulo.html" else "website"}">\n<meta property="og:site_name" content="GestiaHR">\n<meta property="og:locale" content="es_PE">\n' + \
              f'<meta property="og:title" content="{html.escape(title)}">\n<meta property="og:description" content="{html.escape(desc)}">\n' + \
              f'<meta property="og:url" content="{url_of(fname)}">\n<meta property="og:image" content="{SITE}/assets/img/{og}">\n<meta property="og:image:width" content="1200">\n<meta property="og:image:height" content="630">\n' + \
              f'<meta name="twitter:card" content="summary_large_image">\n' + \
              '<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">\n<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">\n<meta name="theme-color" content="#0A3A73">\n'
        if idx and ld:
            seo += ld_json(ld)
    return f'''<!DOCTYPE html>
<html lang="es-PE">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{html.escape(title)}</title>
<meta name="description" content="{html.escape(desc)}">
{seo}<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,300,0..1,0&display=swap">
<link rel="stylesheet" href="assets/site.css">
</head>
<body>
'''

SOLUCIONES_MENU = [('retail.html', 'Retail y cadenas', 'Muchas tiendas y rotación alta.'), ('agroindustria.html', 'Agroindustria', 'Campañas y régimen agrario.'),
                   ('empresas-de-servicios.html', 'Empresas de servicios', 'Personal en las sedes de tus clientes.'), ('estudios-contables.html', 'Estudios contables', 'La planilla de todos tus clientes.')]
def nav(active=''):
    mega = ''.join(f'<a href="{r}" style="border-left-color:{c}"><b>{n} <span class="sig" style="color:{c}">{s}</span></b><small>{d}</small></a>' for k, n, s, c, r, d, st in PRODUCTS)
    mega += '<a href="calculadora.html" style="border-left-color:#94A3B8"><b>Calculadora de costo laboral</b><small>Cuánto cuesta un colaborador al mes.</small></a>'
    mega += '<a class="all" href="suite.html"><b>Suite GestiaHR <span class="sig">HCM</span> · todos los productos integrados</b><span class="ms">arrow_forward</span></a>'
    sol = ''.join(f'<a href="{f}" style="border-left-color:#94A3B8"><b>{t}</b><small>{x}</small></a>' for f, t, x in SOLUCIONES_MENU)
    sol += '<a class="all" href="soluciones.html"><b>Todas las soluciones por industria y proceso</b><span class="ms">arrow_forward</span></a>'
    mob = ''.join(f'<a href="{r}"><span class="sig" style="color:{c}">{s}</span> {n}{" · pronto" if st == "soon" else ""}</a>' for k, n, s, c, r, d, st in PRODUCTS)
    def a(href, txt):
        st = ' style="color:var(--navy)"' if href == active else ''
        return f'<a href="{href}"{st}>{txt}</a>'
    return f'''<div class="topbar"><span class="refnote">Diseño de referencia · <a href="portada.html">Mapa del sitio</a></span>Calendario laboral 2026: fechas de PLAME, CTS y gratificaciones. <a href="articulo.html">Ver calendario</a></div>
<header class="nav">
  <div class="wrap">
    <a class="logo" href="inicio.html"><span class="mk">G</span>GestiaHR</a>
    <nav class="menu">
      <div class="dd"><a href="suite.html">Productos <span class="ms">expand_more</span></a><div class="mega">{mega}</div></div>
      <div class="dd"><a href="soluciones.html"{' style="color:var(--navy)"' if active == 'soluciones.html' else ''}>Soluciones <span class="ms">expand_more</span></a><div class="mega mega-s">{sol}</div></div>
      {a("precios.html","Precios")}
      {a("blog.html","Recursos")}
      {a("partners.html","Partners")}
    </nav>
    <div class="nav-r">
      <a class="lnk" href="ingresar.html">Iniciar sesión</a>
      <a class="btn btn-line" href="demo.html">Agendar demo</a>
      <a class="btn btn-primary" href="registro.html">Prueba gratis</a>
      <details class="mnav"><summary aria-label="Menú"><span class="ms">menu</span></summary><div class="mnav-p">
        <b>Productos</b>{mob}
        <a href="suite.html">Suite GestiaHR · HCM</a>
        <b>Más</b><a href="precios.html">Precios y planes</a><a href="soluciones.html">Soluciones</a><a href="blog.html">Blog</a><a href="calculadora.html">Calculadora</a><a href="partners.html">Partners</a><a href="demo.html">Agendar demo</a><a href="ingresar.html">Iniciar sesión</a>
      </div></details>
    </div>
  </div>
</header>
'''

FOOTER = '''<footer>
  <div class="wrap">
    <div class="fgrid">
      <div>
        <a class="logo" href="inicio.html"><span class="mk">G</span>GestiaHR</a>
        <p class="d">Software de planillas y gestión de personas para empresas del Perú.</p>
        <a class="lr" href="libro-reclamaciones.html"><span class="ms">menu_book</span>Libro de Reclamaciones</a>
      </div>
      <div><h5>Productos</h5><ul><li><a href="personas.html">Personas · HRIS</a></li><li><a href="tiempo.html">Tiempo · WFM</a></li><li><a href="planilla.html">Planilla · PAY</a></li><li><a href="talento.html">Talento · TMS</a></li><li><a href="formacion.html">Formación · LMS</a></li><li><a href="suite.html">Suite · HCM</a></li></ul></div>
      <div><h5>Soluciones</h5><ul><li><a href="retail.html">Retail y cadenas</a></li><li><a href="agroindustria.html">Agroindustria</a></li><li><a href="empresas-de-servicios.html">Empresas de servicios</a></li><li><a href="estudios-contables.html">Estudios contables</a></li><li><a href="software-de-planillas.html">Software de planillas</a></li><li><a href="precios.html">Precios</a></li></ul></div>
      <div><h5>Recursos</h5><ul><li><a href="blog.html">Blog</a></li><li><a href="calculo-de-cts.html">Cálculo de CTS</a></li><li><a href="calculo-de-gratificaciones.html">Gratificaciones</a></li><li><a href="liquidacion-de-beneficios-sociales.html">Liquidación de beneficios</a></li><li><a href="plame-y-t-registro.html">PLAME y T-Registro</a></li><li><a href="calculadora.html">Calculadora de costo laboral</a></li><li><a href="demo.html">Agendar demo</a></li><li><a href="partners.html">Programa de partners</a></li></ul></div>
      <div><h5>Empresa</h5><ul><li><a href="terminos.html">Términos y condiciones</a></li><li><a href="privacidad.html">Política de privacidad</a></li><li><a href="cookies.html">Política de cookies</a></li><li><a href="tratamiento-de-datos.html">Tratamiento de datos</a></li><li><a href="ingresar.html">Iniciar sesión</a></li><li>soporte@gestiahr.com</li></ul></div>
    </div>
    <div class="fbot"><span>© 2026 [Razón social por definir] · RUC por definir · Lima, Perú</span><div><a href="terminos.html">Términos</a><a href="privacidad.html">Privacidad</a><a href="cookies.html">Cookies</a></div></div>
  </div>
</footer>
'''

WIDGETS = '''<details class="chatw"><summary><span class="ms">chat</span> ¿Tienes dudas?</summary>
  <div class="panel-c">
    <div class="ph-c"><b>Asistente de GestiaHR</b><small>Responde con la ayuda pública y los planes</small></div>
    <div class="bd-c">
      <div class="bubble">Hola. Te ayudo a elegir un plan, entender cómo funciona la prueba o agendar una demo.</div>
      <div class="qr"><a href="precios.html">Ver planes</a><a href="registro.html">¿Cómo funciona la prueba?</a><a href="planilla.html">¿Genera PLAME?</a><a href="demo.html">Hablar con ventas</a></div>
    </div>
    <div class="ft-c">Si no sabe la respuesta, te pasa con una persona del equipo.</div>
  </div>
</details>
<div class="cookie" id="cookie"><b>Usamos cookies</b>Las necesarias para que el sitio funcione y, si aceptas, otras para medir visitas. <a href="cookies.html" style="color:var(--navy);font-weight:600">Más información</a>
  <div class="acts"><button class="btn btn-primary" onclick="ck()">Aceptar</button><button class="btn btn-line" onclick="ck()">Solo necesarias</button></div></div>
<script>function ck(){document.getElementById('cookie').classList.add('hide');try{localStorage.setItem('gh_ck','1')}catch(e){}}try{if(localStorage.getItem('gh_ck'))ck()}catch(e){}</script>
'''

BADGE = '<div class="refbadge">Diseño de referencia · <a href="portada.html">Mapa del sitio</a></div>\n'

def page(fname, title, desc, body, active='', widgets=True, plain=False, ld=None):
    title, desc = SEO.get(fname, (title, desc))
    graph = list(ld or [])
    if fname == 'inicio.html':
        graph = [ORG, {'@type': 'WebSite', '@id': SITE + '/#web', 'url': SITE + '/', 'name': 'GestiaHR', 'inLanguage': 'es-PE', 'publisher': {'@id': SITE + '/#org'}}] + graph
    if fname in CRUMBS:
        graph.append({'@type': 'BreadcrumbList', 'itemListElement': [{'@type': 'ListItem', 'position': j + 1, 'name': n, 'item': url_of(f)} for j, (n, f) in enumerate([('Inicio', 'inicio.html')] + CRUMBS[fname])]})
    if _FAQ:
        graph.append({'@type': 'FAQPage', 'mainEntity': [{'@type': 'Question', 'name': strip_tags(q), 'acceptedAnswer': {'@type': 'Answer', 'text': strip_tags(a)}} for q, a in _FAQ]})
        _FAQ.clear()
    if fname not in NOINDEX:
        assert title and desc, f'{fname}: una página indexable necesita título y descripción'
        INDEXABLES.append(fname)
    out = head(title, desc, fname, graph)
    if not plain:
        out += nav(active)
    out += body
    if not plain:
        out += FOOTER
    if widgets:
        out += WIDGETS
    out += (BADGE if plain else '') + '</body>\n</html>\n'
    out = out.replace(OLD_LOGO, LOGO_HTML)
    open(os.path.join(OUT, fname), 'w', encoding='utf-8').write(out)

def shot(img, alt, url='miempresa.gestiahr.com', full=False):
    cls = 'shot full' if full else 'shot'
    bar = '' if full else f'<div class="bar"><i></i><i></i><i></i><span>{url}</span></div>'
    return f'<div class="{cls}">{bar}<img src="assets/img/{img}" alt="{html.escape(alt)}" width="1600" height="1002"></div>'

def cta(t='Prueba GestiaHR con datos de ejemplo o con tu propia empresa', s='Creas tu cuenta en minutos y sin tarjeta.'):
    return f'''<div class="cta"><div class="wrap"><div><h2>{t}</h2><p>{s}</p></div>
<div class="acts"><a class="btn btn-white" href="registro.html">Empezar prueba gratis</a><a class="btn btn-outw" href="demo.html">Hablar con un asesor</a></div></div></div>
'''

def faq(items, title='Preguntas frecuentes', sub='Antes de empezar'):
    _FAQ.extend(items)
    d = ''.join(f'<details{" open" if i == 0 else ""}><summary>{q}</summary><p>{a}</p></details>' for i, (q, a) in enumerate(items))
    return f'''<section><div class="wrap faq-wrap"><div><div class="kick">{title}</div><h2 class="h2">{sub}</h2>
<p class="lead" style="font-size:16px">¿No encuentras tu respuesta? <a href="demo.html" style="color:var(--navy);font-weight:600">Habla con un asesor</a>.</p></div>
<div class="faq">{d}</div></div></section>
'''

def phero(kick, h1, lead, ctas=True, crumbs='', extra=''):
    c = '<div class="ctas"><a class="btn btn-primary" href="registro.html">Empezar prueba gratis</a><a class="btn btn-line" href="demo.html">Agendar una demo</a></div>' if ctas else ''
    cr = f'<div class="crumbs">{crumbs}</div>' if crumbs else ''
    return f'<div class="phero{" with-shot" if extra else ""}"><div class="wrap">{cr}<div class="kick">{kick}</div><h1>{h1}</h1><p class="lead">{lead}</p>{c}{extra}</div></div>\n'

# ---------------------------------------------------------------- datos de venta por producto
# beneficio (titular), precio (línea corta), planes que lo incluyen, beneficios, funciones, próximamente, imagen, pasos, faq
PLANES = ['Esencial', 'Profesional', 'Corporativo']

# PRECIOS DE EJEMPLO, solo para el diseño de referencia. Se cambian aquí y se regenera el sitio.
# En el producto los montos viven en la consola y el sitio los lee de /public/catalog (ADR 0008).
# Precios mensuales en soles, sin IGV. Los módulos se suman por colaborador al mes.
PRECIO_PLAN = {'esencial': 290, 'profesional': 790, 'corporativo': 1990}
PRECIO_MODULO = {'tiempo': 3, 'planilla': 6, 'talento': 4, 'formacion': 3}
DESCUENTO_ANUAL = 'dos meses gratis'
IGV = 0.18
NOTA_PRECIOS = 'Precios de ejemplo, en soles y sin IGV.'
def soles(n, dec=False):
    t = f'{n:,.2f}' if dec else f'{n:,}'
    return 'S/ ' + t.replace(',', ' ')
def desde(key):
    i = INFO[key]
    ks = [k.lower() for k in i['incl'] if k.lower() in PRECIO_PLAN]
    return min(PRECIO_PLAN[k] for k in ks) if ks else None
INFO = {
 'personas': dict(
    h1='El legajo de cada colaborador, ordenado y a un clic',
    lead='Ficha completa, contratos con aviso de vencimiento y documentos firmados en un solo lugar. Es la base de todos los productos de GestiaHR.',
    corto='Legajo digital y contratos al día', precio='Incluido en todos los planes', incl=['Esencial', 'Profesional', 'Corporativo'], addon=False,
    img='colaboradores.jpg', alt='Listado de colaboradores en GestiaHR con filtros por empresa, unidad y estado',
    ben=[('Encuentras todo en segundos', 'Datos, contratos y documentos de cada persona en una sola ficha, sin carpetas ni archivos sueltos.'),
         ('Ningún contrato vence sin aviso', 'El sistema te avisa antes de cada vencimiento para que renueves o registres el cese a tiempo.'),
         ('Tu equipo actualiza sus datos', 'Cada colaborador revisa su información desde el portal y RR. HH. aprueba los cambios.')],
    feats=[('Ficha y legajo digital', 'Datos personales, laborales y bancarios, derechohabientes y documentos.'),
           ('Contratos y adendas', 'Fechas de término y avisos antes de que venzan.'),
           ('Organigrama', 'Empresas, sedes, unidades y puestos; quién reporta a quién.'),
           ('Portal del colaborador', 'Cada persona ve sus datos y descarga sus documentos.'),
           ('Altas y ceses', 'Ingresos y salidas con su lista de tareas.'),
           ('Permisos por rol', 'RR. HH., jefes y colaboradores ven solo lo que les corresponde.')],
    soon=[], faqs=[('¿Puedo importar a mis colaboradores desde Excel?', 'Sí. Descargas una plantilla, la completas y el sistema te muestra los errores antes de importar.'),
                   ('¿Los colaboradores pueden ver su información?', 'Sí, desde el portal del colaborador. Tú decides qué datos pueden actualizar.'),
                   ('¿Sirve para varias empresas del mismo grupo?', 'Sí. Cada razón social mantiene su información y puedes verlas juntas si tu rol lo permite.')]),
 'planilla': dict(
    h1='La planilla del mes, calculada y revisada antes de pagar',
    lead='Régimen general, MYPE y agrario, con gratificaciones, CTS, liquidaciones y quinta categoría. Al cerrar, generas boletas firmadas y los archivos para SUNAT y tu banco.',
    corto='Planilla, boletas, PLAME y T-Registro', precio='En Profesional y Corporativo, o súmalo a Esencial', incl=['Profesional', 'Corporativo'], addon=True,
    img='nomina.jpg', alt='Periodos de planilla con su estado, avance, neto y costo para la empresa',
    ben=[('Cierras sin hojas de cálculo', 'Incidencias, cálculo, revisión y aprobación en un solo flujo, con alertas antes de pagar.'),
         ('Declaras sin rehacer nada', 'Los archivos de PLAME, T-Registro y el pago al banco salen del mismo cálculo.'),
         ('Menos consultas de tu equipo', 'Cada colaborador recibe su boleta firmada en el celular, con el detalle de cada concepto.')],
    feats=[('Periodo paso a paso', 'Incidencias, cálculo, revisión, aprobación, cierre, boletas, pago y contabilización.'),
           ('Regímenes laborales', 'Régimen general, pequeña y micro empresa, y régimen agrario.'),
           ('Beneficios sociales', 'Gratificaciones, CTS, vacaciones y liquidaciones.'),
           ('Boletas firmadas', 'Boleta electrónica con firma y acuse de recepción.'),
           ('PLAME y T-Registro', 'Archivos listos para subir a la Planilla Electrónica.'),
           ('Préstamos y adelantos', 'Se descuentan en el periodo que corresponde.')],
    soon=[], faqs=[('¿Quién mantiene los parámetros legales?', 'Publicamos cada parámetro con su fecha de vigencia, revisado por especialistas laborales, y te avisamos qué cambia en tu planilla.'),
                   ('¿Puedo correr la planilla en paralelo con mi sistema actual?', 'Sí. Te recomendamos hacerlo el primer mes y comparar boleta por boleta.'),
                   ('¿Necesito Gestia Personas?', 'Sí, la planilla usa la ficha de cada colaborador. Viene incluida en todos los planes.')]),
 'tiempo': dict(
    h1='Vacaciones y permisos sin correos ni hojas de cálculo',
    lead='Cada colaborador ve su saldo y pide sus días desde el portal, su jefe aprueba en línea y RR. HH. tiene el récord vacacional al día.',
    corto='Vacaciones, permisos y récord vacacional', precio='Vacaciones incluidas en todos los planes', incl=['Esencial', 'Profesional', 'Corporativo'], addon=False,
    img='vacaciones.jpg', alt='Vacaciones con saldos, solicitudes y récord vacacional por periodo',
    ben=[('Saldos claros para todos', 'El récord vacacional se calcula solo desde la fecha de ingreso y según el régimen.'),
         ('Aprobaciones en un clic', 'El jefe aprueba desde el celular y ve quién más sale esos días.'),
         ('Directo a la planilla', 'Las vacaciones aprobadas pasan a la planilla si también usas Gestia Planilla.')],
    feats=[('Solicitudes y aprobaciones', 'Vacaciones, permisos y licencias con flujo de aprobación.'),
           ('Récord vacacional', 'Días generados, gozados, programados y vencidos por periodo.'),
           ('Calendario del equipo', 'Quién sale y cuándo, antes de aprobar.')],
    soon=[('Asistencia', 'Marcación desde el celular o kiosko, con tardanzas y faltas.'),
          ('Turnos y horarios', 'Programación de turnos por sede y equipo.'),
          ('Horas extra', 'Registro y aprobación de horas extra y bolsa de horas.')],
    faqs=[('¿Las vacaciones se calculan solas?', 'Sí, desde la fecha de ingreso y según el régimen de cada colaborador.'),
          ('¿Cuándo estará la asistencia?', 'Está en camino. Déjanos tu correo en la lista de espera y te avisamos.'),
          ('¿Mi equipo puede pedir vacaciones desde el celular?', 'Sí, desde el portal del colaborador.')]),
 'talento': dict(
    h1='Atrae, incorpora y desarrolla a tu equipo',
    lead='Selección de personal, incorporación, evaluaciones de desempeño, planes de carrera y compensación, conectados con la ficha de cada colaborador.',
    corto='Selección, desempeño y carrera', precio='Próximamente', incl=['Corporativo'], addon=True, img=None, alt='',
    ben=[('Contratas más rápido', 'Publicas la vacante, recibes postulaciones y sigues a cada candidato en un tablero.'),
         ('Evaluaciones que se completan', 'Ciclos de desempeño con recordatorios y resultados por área.'),
         ('Carrera visible', 'Cada colaborador ve su plan de desarrollo y lo conversa con su jefe.')],
    feats=[('Selección', 'Portal de empleos, postulaciones y seguimiento de candidatos.'),
           ('Incorporación', 'Tareas para el primer día y la primera semana.'),
           ('Desempeño', 'Evaluaciones por ciclo, objetivos y conversaciones.'),
           ('Carrera', 'Planes de desarrollo y sucesión.'),
           ('Compensación', 'Bandas salariales y revisiones de sueldo.'),
           ('Todo en la ficha', 'Lo que pase en Talento queda en el legajo.')],
    soon=[], faqs=[('¿Cuándo estará disponible?', 'Estamos trabajando en él. Déjanos tu correo y te avisamos.'),
                   ('¿Puedo usarlo sin Planilla?', 'Sí. Talento solo necesita Gestia Personas.')]),
 'formacion': dict(
    h1='Capacita a tu equipo con tus propios cursos',
    lead='Sube tus cursos, evalúa y entrega certificados, y arma el plan anual de capacitación de tu empresa.',
    corto='Tus cursos, evaluaciones y certificados', precio='Próximamente', incl=['Corporativo'], addon=True, img=None, alt='',
    ben=[('Tus cursos, en un solo lugar', 'Videos, documentos y presentaciones de tu empresa, organizados por tema.'),
         ('Sabes quién se capacitó', 'Avance y resultados por persona, área y curso.'),
         ('Certificados en el legajo', 'Cada certificado queda guardado en la ficha del colaborador.')],
    feats=[('Tus cursos', 'GestiaHR no trae cursos propios: la empresa sube los suyos.'),
           ('Evaluaciones', 'Preguntas al final y nota mínima para aprobar.'),
           ('Certificados', 'Al aprobar, guardado en el legajo.'),
           ('Plan anual', 'Programa las capacitaciones del año.'),
           ('Asignación', 'Por puesto, área o persona.'),
           ('Reportes', 'Quién completó qué y quién está pendiente.')],
    soon=[], faqs=[('¿GestiaHR incluye cursos?', 'No. La empresa sube sus propios cursos.'),
                   ('¿Cuándo estará disponible?', 'Estamos trabajando en él. Déjanos tu correo y te avisamos.')]),
}
ICONS = {'personas': 'badge', 'planilla': 'payments', 'tiempo': 'event_available', 'talento': 'trending_up', 'formacion': 'school'}

def prod(key):
    return next(p for p in PRODUCTS if p[0] == key)

def prod_card(key, big=False):
    k, n, s, c, r, d, st = prod(key); i = INFO[key]
    chip = '<span class="ok">Disponible</span>' if st == 'ok' else '<span class="soon">Próximamente</span>'
    if key == 'tiempo': chip = '<span class="ok">Vacaciones disponibles</span>'
    plans = ''.join(f'<span class="pchip{" on" if p in i["incl"] else ""}">{p}</span>' for p in PLANES)
    if st == 'ok':
        acts = f'<div class="pc-acts"><a class="btn btn-primary" href="{r}">Ver beneficios y precios</a><a class="btn btn-line" href="registro.html?producto={k}">Probar</a></div>'
    else:
        acts = f'<div class="pc-acts"><a class="btn btn-line" href="{r}">Ver qué incluirá</a><a class="btn btn-line" href="{r}#avisame">Avísame</a></div>'
    return f'''<div class="pcard" style="--c:{c}"><a class="pc-link" href="{r}" aria-label="{n}"></a>
<div class="pc-top"><span class="pc-ic"><span class="ms">{ICONS[key]}</span></span>{chip}</div>
<h3>{n} <span class="sig">{s}</span></h3><p class="pc-b">{i["corto"]}</p><p class="pc-d">{i["lead"]}</p>
<div class="pc-price"><small>Precio</small><b>{("Desde " + soles(desde(key)) + " <span>al mes</span>") if st == "ok" else "Próximamente"}</b><small>{i["precio"]}</small></div>
<div class="pc-plans"><small>Incluido en</small>{plans}</div>{acts}</div>'''

def suite_card():
    return '''<div class="pcard suite"><a class="pc-link" href="suite.html" aria-label="Suite GestiaHR"></a>
<div class="pc-top"><span class="pc-ic"><span class="ms">hub</span></span><span class="sig" style="color:var(--yellow)">HCM</span></div>
<h3>Suite GestiaHR</h3><p class="pc-b">Todos los productos, un solo sistema</p><p class="pc-d">Un acceso, una ficha por colaborador y un solo contrato. Activa cada producto cuando lo necesites.</p>
<div class="pc-price"><small>Precio</small><b>''' + soles(PRECIO_PLAN['corporativo']) + ''' <span>al mes</span></b><small>Con el plan Corporativo</small></div>
<div class="pc-acts"><a class="btn btn-white" href="suite.html">Ver la Suite</a><a class="btn btn-outw" href="precios.html">Comparar planes</a></div></div>'''

PLAN_DATA = [
    ('esencial', 'Esencial', 'Para ordenar tu equipo', 'Hasta 50 colaboradores', ['personas', 'tiempo'], ['Gestia Personas · HRIS', 'Vacaciones y ausencias', '!No incluye Planilla']),
    ('profesional', 'Profesional', 'Para empresas en crecimiento', 'Hasta 200 colaboradores', ['personas', 'tiempo', 'planilla'], ['Todo lo de Esencial', 'Gestia Planilla · PAY', 'Boletas firmadas', 'Asistente de ayuda con IA']),
    ('corporativo', 'Corporativo', 'Para medianas empresas', 'Hasta 1 000 colaboradores', ['personas', 'tiempo', 'planilla', 'talento', 'formacion'], ['Todos los módulos disponibles', 'Talento y Formación al lanzarse', 'Integraciones y API', 'Soporte prioritario']),
]
def plan_cards(highlight=None, compact=False):
    out = ''
    for key, n, f_, lim, prods, items in PLAN_DATA:
        pop = key == (highlight or 'profesional')
        li = ''.join(f'<li class="no">{i[1:]}</li>' if i.startswith('!') else f'<li>{i}</li>' for i in items)
        chips = ''.join(f'<a class="sigchip" href="{prod(p)[4]}" style="--c:{prod(p)[3]}">{prod(p)[2]}</a>' for p in prods)
        btn = f'<a class="btn {"btn-primary" if pop else "btn-line"}" href="registro.html?plan={key}">{"Probar " + n if pop else "Elegir " + n}</a>'
        badge = '<span class="badge">Recomendado</span>' if pop and not highlight else ('<span class="badge">Incluye este producto</span>' if highlight and pop else '')
        out += f'<div class="plan{" pop" if pop else ""}" id="plan-{key}">{badge}<h3>{n}</h3><div class="for">{f_}</div><div class="price">{soles(PRECIO_PLAN[key])} <small>al mes</small></div><div class="lim">{lim}</div><div class="chips-s">{chips}</div><ul>{li}</ul>{btn}</div>'
    out += '<div class="plan"><h3>Empresas</h3><div class="for">Para grandes organizaciones</div><div class="price" style="font-size:26px;margin-top:26px">A la medida</div><div class="lim">Más de 1 000 colaboradores</div><ul><li>Implementación dedicada</li><li>Integración con tu ERP</li><li>Acuerdos a medida</li></ul><a class="btn btn-line" href="demo.html">Hablar con ventas</a></div>'
    return f'<div class="plans">{out}</div>'

# ---------------------------------------------------------------- inicio
src = open(os.path.join(HERE, 'inicio-cuerpo.html'), encoding='utf-8').read()
def cut(a, b):
    i = src.index(a); j = src.index(b) if b else len(src)
    return src[i:j]
realshots = cut('<!-- PANTALLAS REALES -->', '<!-- SEGURIDAD -->')
seguridad = cut('<!-- SEGURIDAD -->', '<!-- PRECIOS -->')
logos = cut('<!-- CLIENTES -->', '<!-- PRODUCTOS -->')
testimonios = cut('<!-- TESTIMONIOS -->', '<!-- FAQ -->')
faqcta = cut('<!-- FAQ -->', None)
chooser = ''.join(f'<a class="need" href="{prod(k)[4]}" style="--c:{prod(k)[3]}"><span class="sig">{prod(k)[2]}</span><span><b>{q}</b><small>{prod(k)[1]}{" · próximamente" if prod(k)[6] == "soon" else ""}</small></span><span class="ms">arrow_forward</span></a>'
                  for k, q in [('personas', 'Ordenar el legajo y los contratos'), ('planilla', 'Calcular la planilla y declarar'), ('tiempo', 'Gestionar vacaciones y permisos'), ('talento', 'Contratar y evaluar'), ('formacion', 'Capacitar a mi equipo')])
chooser += '<a class="need all" href="suite.html"><span class="sig">HCM</span><span><b>Todo en un solo sistema</b><small>Suite GestiaHR</small></span><span class="ms">arrow_forward</span></a>'
body = f'''<div class="hero hero2"><div class="wrap">
<div class="txt"><div class="kick">Software de planillas y RR. HH. para el Perú</div>
<h1>Toda la gestión de tu equipo, <span>de la contratación a la boleta</span>, en un solo sistema.</h1>
<p class="sub">Elige el producto que necesitas hoy y suma los demás cuando crezcas. Todos comparten la misma ficha del colaborador.</p>
<div class="ctas"><a class="btn btn-primary" href="registro.html">Empezar prueba gratis</a><a class="btn btn-line" href="precios.html">Ver planes y precios</a></div>
<p class="fine">Prueba sin tarjeta · Te acompañamos en la migración · Soporte en español</p></div>
<div class="needs"><div class="needs-h">¿Qué quieres resolver?</div>{chooser}</div>
</div></div>
<nav class="jump"><div class="wrap"><a href="#productos">Productos</a><a href="#planes">Planes</a><a href="#por-dentro">Así se ve</a><a href="#seguridad">Seguridad</a><a href="#preguntas">Preguntas</a><a class="btn btn-primary" href="registro.html">Prueba gratis</a></div></nav>
<section id="productos" class="sec-sm"><div class="wrap">
<div class="sec-head"><div><div class="kick">Productos</div><h2 class="h2">Un producto para cada necesidad</h2><p class="lead">Contrátalos solos o dentro de un plan. Entra a cada uno para ver sus beneficios, funciones y precio.</p></div>
<a class="btn btn-line" href="precios.html">Comparar todo →</a></div>
<div class="pgrid">{"".join(prod_card(k) for k in ["personas", "planilla", "tiempo", "talento", "formacion"])}{suite_card()}</div></div></section>
<section id="planes" class="alt sec-sm"><div class="wrap">
<div class="sec-head"><div><div class="kick">Planes</div><h2 class="h2">Elige tu plan según el tamaño de tu empresa</h2><p class="lead">A cualquier plan le puedes sumar productos. Las siglas muestran qué incluye cada uno.</p></div>
<a class="btn btn-line" href="precios.html#comparar">Ver comparación completa →</a></div>
{plan_cards()}<p class="note">{NOTA_PRECIOS}</p></div></section>
<section id="por-dentro" class="sec-sm"><div class="wrap"><div class="sec-head"><div><div class="kick">Así se ve por dentro</div><h2 class="h2">Tu panel de RR. HH. desde el primer día</h2><p class="lead">Pendientes, contratos por vencer y el estado de tu equipo, apenas entras.</p></div><a class="btn btn-line" href="demo.html">Ver una demo →</a></div>
{shot("inicio.jpg", "Panel de inicio de GestiaHR con pendientes, indicadores del equipo y alertas de contratos")}<p class="cap">Pantalla del sistema con datos de demostración.</p></div></section>
{realshots}
<div id="seguridad"></div>{seguridad}{logos}{testimonios}<div id="preguntas"></div>{faqcta}'''
page('inicio.html', 'GestiaHR · Planillas y gestión de personas para empresas del Perú',
     'Software de planillas y RR. HH. para el Perú: legajo digital, vacaciones, planilla, boletas, PLAME y T-Registro.', body)

# ---------------------------------------------------------------- páginas SEO por servicio e industria (WEB-9)
# Una página por proceso que la gente busca (CTS, boletas, vacaciones…) y por industria. Todas usan la misma plantilla,
# enlazan a su producto y a las páginas hermanas, y llevan sus preguntas como FAQPage. Sin cifras legales: lo que dependa
# de la norma se explica en el sistema con su parámetro vigente.
SERVICIOS = [
 dict(f='legajo-digital.html', prod='personas', nombre='Legajo digital',
    title='Legajo digital de colaboradores | Gestia Personas', desc='Digitaliza el legajo de cada colaborador: datos, contratos, documentos firmados y vencimientos en una sola ficha, con permisos por rol.',
    h1='Legajo digital: la ficha completa de cada colaborador', lead='Datos personales y laborales, contratos, adendas y documentos firmados en un solo lugar. Sin archivadores ni carpetas compartidas.',
    img=('colaboradores.jpg', 'Listado de colaboradores con su legajo digital en GestiaHR'),
    dolor=[('Documentos dispersos', 'Cada documento queda en la ficha de la persona, con su tipo, su fecha y quién lo cargó.'),
           ('Vencimientos que se pasan', 'Contratos, exámenes y documentos con fecha de vencimiento te avisan antes de que venzan.'),
           ('Datos desactualizados', 'El colaborador revisa sus datos desde el portal y RR. HH. aprueba cada cambio.')],
    pasos=[('Importa a tu equipo', 'Con la plantilla de Excel y la revisión de errores antes de cargar.'), ('Sube los documentos', 'Uno por uno o en lote, y cada uno queda en la ficha que le corresponde.'),
           ('Firma en línea', 'Contratos y documentos con firma electrónica y constancia.'), ('Controla quién ve qué', 'Los permisos por rol y por campo protegen los datos sensibles.')],
    incluye=['Ficha con datos personales, laborales y bancarios', 'Derechohabientes y contactos de emergencia', 'Contratos, adendas y avisos de vencimiento', 'Documentos con firma electrónica', 'Historial de cambios con fecha de vigencia', 'Exportación del legajo completo'],
    faqs=[('¿Qué documentos puedo guardar en el legajo digital?', 'Contratos, adendas, constancias, certificados, exámenes y cualquier documento que definas como tipo de documento, con o sin fecha de vencimiento.'),
          ('¿Quién puede ver el legajo?', 'Solo los roles que tú autorizas. Los datos sensibles, como los bancarios o de salud, tienen permisos propios.'),
          ('¿Puedo descargar el legajo de un colaborador?', 'Sí, completo y con sus documentos, por ejemplo para una inspección o cuando la persona lo solicita.')]),
 dict(f='vacaciones-y-record-vacacional.html', prod='tiempo', nombre='Vacaciones y récord vacacional',
    title='Control de vacaciones y récord vacacional | Gestia Tiempo', desc='Saldos de vacaciones al día, solicitudes desde el celular, aprobación del jefe y récord vacacional por periodo para cada colaborador.',
    h1='Control de vacaciones y récord vacacional sin hojas de cálculo', lead='Cada colaborador ve su saldo y pide sus días desde el portal. El jefe aprueba en línea y RR. HH. tiene el récord vacacional por periodo siempre al día.',
    img=('vacaciones.jpg', 'Saldos y solicitudes de vacaciones en GestiaHR'),
    dolor=[('Saldos que nadie confía', 'El récord se calcula desde la fecha de ingreso y según el régimen de cada persona.'),
           ('Aprobaciones por correo', 'La solicitud llega al jefe, que aprueba desde el celular y ve quién más sale esos días.'),
           ('Vacaciones que no llegan a la planilla', 'Lo aprobado pasa al periodo de planilla si también usas Gestia Planilla.')],
    pasos=[('Carga los saldos iniciales', 'Desde Excel, con los días generados y gozados de cada periodo.'), ('Publica el calendario', 'Feriados y reglas de tu empresa por sede.'),
           ('Tu equipo solicita', 'Desde el portal o el celular, con el saldo a la vista.'), ('El jefe aprueba', 'Y el récord vacacional se actualiza solo.')],
    incluye=['Récord vacacional por periodo', 'Solicitudes de vacaciones, permisos y licencias', 'Flujo de aprobación por jefe y RR. HH.', 'Calendario del equipo', 'Programación anual de vacaciones', 'Alertas de periodos por vencer'],
    faqs=[('¿El récord vacacional se calcula solo?', 'Sí. Se calcula desde la fecha de ingreso y según el régimen laboral de cada colaborador, con los parámetros vigentes.'),
          ('¿Puedo cargar los saldos que ya tengo?', 'Sí, con una plantilla de Excel al empezar. Después el sistema los mantiene al día.'),
          ('¿Las vacaciones aprobadas pasan a la planilla?', 'Sí, si usas Gestia Planilla. Entran al periodo que corresponde sin volver a digitarlas.')]),
 dict(f='control-de-asistencia.html', prod='tiempo', nombre='Control de asistencia', soon=True,
    title='Control de asistencia y turnos para empresas | Gestia Tiempo', desc='Marcación desde el celular o un kiosko, turnos por sede, tardanzas, faltas y horas extra conectadas con la planilla. Únete a la lista de espera.',
    h1='Control de asistencia y turnos conectado con tu planilla', lead='Marcación desde el celular o un kiosko, turnos por sede y equipo, y tardanzas, faltas y horas extra que llegan a la planilla sin digitarlas.',
    img=None,
    dolor=[('Marcaciones en papel', 'Cada marcación queda registrada con la hora, el lugar y el dispositivo.'),
           ('Turnos difíciles de armar', 'Programa los turnos por sede y equipo, y ve quién cubre cada horario.'),
           ('Horas extra sin control', 'Las horas extra se registran, se aprueban y pasan a la planilla.')],
    pasos=[('Define horarios y turnos', 'Por sede, por equipo o por persona.'), ('Tu equipo marca', 'Desde el celular, un kiosko o un reloj compatible.'),
           ('El jefe revisa', 'Tardanzas, faltas y horas extra para aprobar.'), ('Pasa a la planilla', 'Lo aprobado entra al periodo que corresponde.')],
    incluye=['Marcación desde el celular y kiosko', 'Turnos y horarios por sede', 'Tardanzas, faltas y justificaciones', 'Horas extra y bolsa de horas', 'Reportes de asistencia por periodo', 'Conexión con Gestia Planilla'],
    faqs=[('¿Cuándo estará disponible el control de asistencia?', 'Está en camino. Déjanos tu correo en la lista de espera y te avisamos apenas se publique.'),
          ('¿Mientras tanto puedo usar las vacaciones?', 'Sí. Vacaciones y permisos ya están disponibles en Gestia Tiempo.')]),
 dict(f='boletas-de-pago-electronicas.html', prod='planilla', nombre='Boletas de pago electrónicas',
    title='Boletas de pago electrónicas con firma | Gestia Planilla', desc='Entrega las boletas de pago firmadas al celular de cada colaborador, con acuse de recepción y el detalle de cada concepto.',
    h1='Boletas de pago electrónicas, firmadas y con acuse de recepción', lead='Al cerrar la planilla, cada colaborador recibe su boleta firmada en el celular o el correo, con el detalle de cada ingreso, descuento y aporte.',
    img=('portal.jpg', 'Portal del colaborador con sus boletas de pago en GestiaHR'),
    dolor=[('Imprimir y hacer firmar', 'La boleta se firma electrónicamente y la entrega queda registrada.'),
           ('Consultas repetidas', 'Cada persona ve el detalle de cada concepto y descarga sus boletas anteriores.'),
           ('Sin constancia de entrega', 'El sistema guarda el acuse de recepción de cada boleta.')],
    pasos=[('Cierra la planilla', 'Las boletas se generan con el mismo cálculo aprobado.'), ('Firma en lote', 'El representante firma todas las boletas del periodo.'),
           ('Se entregan solas', 'Al portal, al correo o al celular de cada colaborador.'), ('Revisa los acuses', 'Quién la recibió, cuándo y quién falta.')],
    incluye=['Boleta con el detalle de cada concepto', 'Firma electrónica en lote', 'Entrega al portal, correo o celular', 'Acuse de recepción', 'Historial de boletas por colaborador', 'Boletas de gratificación, CTS y liquidación'],
    faqs=[('¿La boleta electrónica tiene validez?', 'La boleta se emite y entrega según la norma vigente para boletas de pago electrónicas. El detalle legal lo revisa el especialista laboral de GestiaHR.'),
          ('¿El colaborador necesita correo de la empresa?', 'No. Puede entrar al portal con su documento o recibirla en su correo personal o en el celular.'),
          ('¿Puedo volver a enviar una boleta?', 'Sí, desde la ficha del colaborador, y queda registrado el nuevo envío.')]),
 dict(f='calculo-de-cts.html', prod='planilla', nombre='Cálculo de CTS',
    title='Cálculo y depósito de CTS para empresas | Gestia Planilla', desc='Calcula la CTS de cada colaborador con su remuneración computable, genera la hoja de liquidación y el archivo para el depósito en el banco.',
    h1='Cálculo de CTS listo para depositar', lead='GestiaHR calcula la CTS de cada colaborador con su remuneración computable y su tiempo de servicio, genera la hoja de liquidación y el archivo para el banco.',
    img=('nomina.jpg', 'Periodo de CTS en GestiaHR con el cálculo por colaborador'),
    dolor=[('Cálculos a mano', 'La remuneración computable sale de la planilla: no se vuelve a digitar nada.'),
           ('Errores por régimen', 'El cálculo respeta el régimen laboral de cada colaborador.'),
           ('Depósitos uno por uno', 'Genera el archivo para el banco con todos los depósitos del periodo.')],
    pasos=[('Abre el periodo de CTS', 'El sistema reúne a quienes corresponde.'), ('Revisa el cálculo', 'Con el detalle de cada concepto que suma.'),
           ('Aprueba y genera', 'Hojas de liquidación y archivo para el banco.'), ('Entrega la constancia', 'Cada colaborador recibe su hoja en el portal.')],
    incluye=['Remuneración computable desde la planilla', 'Cálculo por régimen laboral', 'Hoja de liquidación de CTS por colaborador', 'Archivo de depósito para el banco', 'CTS trunca en la liquidación', 'Historial de depósitos'],
    faqs=[('¿Qué conceptos entran en la CTS?', 'Los que la norma define como remuneración computable. En GestiaHR cada concepto de planilla indica si afecta a la CTS y el especialista laboral lo valida.'),
          ('¿Funciona para pequeña empresa?', 'Sí. El cálculo sigue el régimen laboral de cada colaborador.'),
          ('¿Puedo revisar antes de depositar?', 'Sí. El periodo pasa por revisión y aprobación antes de generar el archivo.')]),
 dict(f='calculo-de-gratificaciones.html', prod='planilla', nombre='Cálculo de gratificaciones',
    title='Cálculo de gratificaciones de julio y diciembre | Gestia Planilla', desc='Calcula las gratificaciones de julio y diciembre con la bonificación extraordinaria, el prorrateo por meses y la boleta de cada colaborador.',
    h1='Gratificaciones de julio y diciembre calculadas sin errores', lead='GestiaHR calcula la gratificación de cada colaborador con su remuneración computable, los meses trabajados y la bonificación extraordinaria, y entrega la boleta firmada.',
    img=('nomina.jpg', 'Periodo de gratificación en GestiaHR'),
    dolor=[('Prorrateos a mano', 'Los meses y días trabajados salen del historial de cada colaborador.'),
           ('Bonificación olvidada', 'La bonificación extraordinaria se calcula junto con la gratificación.'),
           ('Quinta mal proyectada', 'La gratificación entra en la proyección de quinta categoría del mes.')],
    pasos=[('Abre el periodo', 'Julio o diciembre, con todos los que corresponde.'), ('Revisa el cálculo', 'Con el detalle de cada concepto y los meses que cuentan.'),
           ('Aprueba', 'Y genera las boletas firmadas.'), ('Paga y declara', 'Archivo para el banco y la información para PLAME.')],
    incluye=['Gratificación de julio y diciembre', 'Bonificación extraordinaria', 'Prorrateo por meses trabajados', 'Gratificación trunca en la liquidación', 'Boleta firmada por colaborador', 'Efecto en la quinta categoría'],
    faqs=[('¿Se calcula la gratificación trunca?', 'Sí, en la liquidación de beneficios sociales cuando un colaborador cesa.'),
          ('¿Considera el régimen laboral?', 'Sí. Cada colaborador se calcula según su régimen, con los parámetros vigentes que valida el especialista laboral.')]),
 dict(f='liquidacion-de-beneficios-sociales.html', prod='planilla', nombre='Liquidación de beneficios sociales',
    title='Liquidación de beneficios sociales al cese | Gestia Planilla', desc='Calcula la liquidación de beneficios sociales al cese: CTS, gratificación y vacaciones truncas y pendientes, con su hoja y su boleta.',
    h1='Liquidación de beneficios sociales en minutos, no en días', lead='Al registrar el cese, GestiaHR calcula la CTS, la gratificación y las vacaciones truncas y pendientes, y genera la hoja de liquidación lista para firmar.',
    img=('nomina.jpg', 'Liquidación de beneficios sociales en GestiaHR'),
    dolor=[('Reunir datos de todos lados', 'Fechas, sueldos y saldos ya están en la ficha y en la planilla.'),
           ('Errores en los truncos', 'Los meses y días se calculan desde el historial del colaborador.'),
           ('Ceses sin cerrar', 'El cese genera sus tareas: liquidación, baja en T-Registro y entrega de documentos.')],
    pasos=[('Registra el cese', 'Con la fecha y el motivo.'), ('Revisa la liquidación', 'Con cada concepto trunco y pendiente.'),
           ('Aprueba y firma', 'La hoja de liquidación y la boleta.'), ('Cierra el proceso', 'Baja en T-Registro y certificados de trabajo.')],
    incluye=['CTS trunca', 'Gratificación trunca', 'Vacaciones truncas y pendientes', 'Descuentos de préstamos pendientes', 'Hoja de liquidación y boleta', 'Lista de tareas del cese'],
    faqs=[('¿Qué incluye la liquidación?', 'La CTS, la gratificación y las vacaciones truncas, las vacaciones pendientes y los descuentos que correspondan, según el régimen del colaborador.'),
          ('¿Se puede revisar antes de pagar?', 'Sí. La liquidación pasa por revisión y aprobación como cualquier periodo de planilla.')]),
 dict(f='plame-y-t-registro.html', prod='planilla', nombre='PLAME y T-Registro',
    title='Archivos PLAME y T-Registro desde tu planilla | Gestia Planilla', desc='Genera los archivos de la PLAME y del T-Registro desde el mismo cálculo de la planilla, con validaciones antes de subirlos a SUNAT.',
    h1='PLAME y T-Registro desde el mismo cálculo de tu planilla', lead='Los archivos de la Planilla Electrónica salen del periodo aprobado, con validaciones previas para que no tengas que corregir después de subirlos.',
    img=('nomina.jpg', 'Archivos de PLAME y T-Registro generados en GestiaHR'),
    dolor=[('Digitar dos veces', 'Lo que calculaste en la planilla es lo que se declara.'),
           ('Rechazos al subir', 'El sistema valida los datos antes de generar los archivos.'),
           ('Altas y bajas olvidadas', 'Cada ingreso y cese genera su movimiento para el T-Registro.')],
    pasos=[('Cierra el periodo', 'Con el cálculo revisado y aprobado.'), ('Genera los archivos', 'PLAME y T-Registro en el formato vigente.'),
           ('Revisa las alertas', 'Datos faltantes o inconsistentes, antes de subir.'), ('Sube a SUNAT', 'Y guarda la constancia en el periodo.')],
    incluye=['Archivos de la PLAME por periodo', 'Altas, bajas y modificaciones del T-Registro', 'Validaciones antes de generar', 'Archivo para AFPnet', 'Constancias guardadas en el periodo', 'Historial de envíos'],
    faqs=[('¿GestiaHR presenta la PLAME por mí?', 'GestiaHR genera los archivos listos para subir. La presentación la hace tu empresa en SUNAT, con su clave SOL.'),
          ('¿Se actualiza cuando cambia el formato?', 'Sí. Los formatos se mantienen como parte del paquete Perú y se publican con su fecha de vigencia.')]),
 dict(f='agroindustria.html', prod='planilla', industria=True, nombre='Agroindustria',
    title='Software de planillas para agroindustria | GestiaHR', desc='Planilla del régimen agrario, campañas, altas y ceses masivos y vacaciones por fundo para empresas agroexportadoras y agroindustriales.',
    h1='Planilla y gestión de personas para la agroindustria', lead='Campañas con cientos de ingresos y ceses, varios fundos y el régimen agrario. GestiaHR lo ordena en una sola planilla por razón social.',
    img=('colaboradores.jpg', 'Colaboradores por fundo y campaña en GestiaHR'),
    dolor=[('Ingresos masivos por campaña', 'Importa a todo el personal de la campaña desde Excel, con su contrato y su alta en T-Registro.'),
           ('Régimen agrario', 'El cálculo sigue los parámetros del régimen agrario vigentes, validados por el especialista laboral.'),
           ('Varios fundos', 'Organiza al personal por fundo, sede y cuadrilla, cada uno con su jefe.')],
    pasos=[('Prepara la campaña', 'Puestos, fundos y contratos modelo.'), ('Importa al personal', 'En lote, con revisión de errores.'),
           ('Calcula la planilla', 'Con el régimen de cada colaborador.'), ('Cierra la campaña', 'Ceses y liquidaciones en lote.')],
    incluye=['Régimen agrario', 'Ingresos y ceses masivos', 'Organización por fundo y cuadrilla', 'Contratos por campaña', 'Liquidaciones en lote', 'Varias razones sociales'],
    faqs=[('¿Soporta el régimen agrario?', 'Sí. El régimen agrario está en el paquete Perú y sus parámetros se publican con su fecha de vigencia.'),
          ('¿Puedo registrar a cientos de personas a la vez?', 'Sí, con el importador de Excel, que valida los datos antes de crear a nadie.')]),
 dict(f='empresas-de-servicios.html', prod='personas', industria=True, nombre='Empresas de servicios',
    title='Software de RR. HH. para empresas de servicios | GestiaHR', desc='Personal destacado en varios clientes y sedes, rotación alta y varias razones sociales: GestiaHR para empresas de servicios y tercerización.',
    h1='Gestión de personas para empresas de servicios y tercerización', lead='Personal en las sedes de tus clientes, rotación alta y contratos que vencen cada mes. GestiaHR te muestra dónde está cada persona y qué vence.',
    img=('colaboradores.jpg', 'Colaboradores por cliente y sede en GestiaHR'),
    dolor=[('Personal en muchos clientes', 'Asigna cada colaborador a su cliente y sede, con su jefe responsable.'),
           ('Contratos que vencen', 'Avisos antes de cada vencimiento, filtrados por cliente.'),
           ('Rotación alta', 'Ingresos y ceses rápidos con su lista de tareas y sus documentos.')],
    pasos=[('Arma tu estructura', 'Clientes, sedes y unidades.'), ('Asigna a tu personal', 'Cada uno en su cliente y sede.'),
           ('Controla vencimientos', 'De contratos y documentos.'), ('Calcula la planilla', 'Con el costo por cliente.')],
    incluye=['Estructura por cliente y sede', 'Avisos de vencimiento de contratos', 'Altas y ceses con su lista de tareas', 'Costo de planilla por centro de costo', 'Portal del colaborador', 'Varias razones sociales'],
    faqs=[('¿Puedo ver el costo de planilla por cliente?', 'Sí, asignando el centro de costo del cliente a cada colaborador o a cada periodo.'),
          ('¿Los supervisores pueden ver solo a su personal?', 'Sí. Los permisos por alcance limitan a cada jefe a su equipo, sede o cliente.')]),
 dict(f='estudios-contables.html', prod='planilla', industria=True, nombre='Estudios contables',
    title='Software de planillas para estudios contables | GestiaHR', desc='Lleva la planilla de todas las empresas que atiendes desde un solo acceso, con la información de cada cliente separada y segura.',
    h1='La planilla de todos tus clientes desde un solo acceso', lead='Cambia de empresa sin cerrar sesión, cierra la planilla de cada cliente con su propio calendario y entrega boletas y archivos de SUNAT sin hojas de cálculo.',
    img=('nomina.jpg', 'Periodos de planilla de varias empresas en GestiaHR'),
    dolor=[('Un archivo por cliente', 'Cada cliente tiene su espacio, y tú entras a todos con el mismo usuario.'),
           ('Fechas que se cruzan', 'Ve los periodos de todos tus clientes y en qué paso está cada uno.'),
           ('Clientes que piden todo', 'Cada cliente puede entrar a ver sus boletas y reportes sin pedírtelos.')],
    pasos=[('Únete como partner', 'Y crea a tus clientes desde tu acceso.'), ('Carga a cada cliente', 'Con el importador de Excel.'),
           ('Calcula y revisa', 'La planilla de cada empresa, con sus regímenes.'), ('Entrega', 'Boletas firmadas, PLAME y archivos del banco.')],
    incluye=['Un acceso para todas tus empresas', 'Información separada por cliente', 'Tablero de periodos de todos los clientes', 'Boletas firmadas y archivos de SUNAT', 'Acceso de solo lectura para el cliente', 'Programa de partners'],
    faqs=[('¿Cada cliente ve la información de los otros?', 'No. Cada empresa cliente tiene su información aislada; solo tú, como partner, entras a las que atiendes.'),
          ('¿Cómo funciona el programa de partners?', 'Revisa el <a href="partners.html" style="color:var(--navy)">programa de partners</a> y déjanos tus datos.')]),
]
SERV_BY_F = {d['f']: d for d in SERVICIOS}

def servicio_page(d):
    k, n, s, c, r, desc_p, st = prod(d['prod'])
    soon = d.get('soon')
    ind = d.get('industria')
    if ind:
        CRUMBS[d['f']] = [('Soluciones', 'soluciones.html'), (d['nombre'], d['f'])]
        cr = f'<a href="inicio.html">Inicio</a> / <a href="soluciones.html">Soluciones</a> / {d["nombre"]}'
        kick = f'Soluciones · {d["nombre"]}'
    else:
        CRUMBS[d['f']] = [(n, r), (d['nombre'], d['f'])]
        cr = f'<a href="inicio.html">Inicio</a> / <a href="{r}">{n}</a> / {d["nombre"]}'
        kick = f'{n} · <span class="sig" style="color:{c}">{s}</span>'
    SEO[d['f']] = (d['title'], d['desc'])
    extra = shot(d['img'][0], d['img'][1]) if d['img'] else ''
    if soon:
        hero = phero(kick + ' · <span class="soon">Próximamente</span>', d['h1'], d['lead'], ctas=False, crumbs=cr, extra=extra)
        hero = hero.replace('<p class="lead">' + d['lead'] + '</p>', '<p class="lead">' + d['lead'] + f'</p><div class="ctas"><a class="btn btn-primary" href="{r}#avisame">Avísame cuando esté</a><a class="btn btn-line" href="{r}">Ver Gestia Tiempo</a></div>')
    else:
        hero = phero(kick, d['h1'], d['lead'], crumbs=cr, extra=extra)
    ben = ''.join(f'<div class="bencard" style="--c:{c}"><em>0{j + 1}</em><h3>{t}</h3><p>{x}</p></div>' for j, (t, x) in enumerate(d['dolor']))
    stp = ''.join(f'<div><em>0{j + 1}</em><b>{t}</b><span>{x}</span></div>' for j, (t, x) in enumerate(d['pasos']))
    inc = ''.join(f'<li><b>{x}</b></li>' for x in d['incluye'])
    rel = [x for x in SERVICIOS if x['f'] != d['f'] and (x.get('industria') == ind) and (ind or x['prod'] == d['prod'])][:4]
    if len(rel) < 3:
        rel += [x for x in SERVICIOS if x['f'] != d['f'] and x not in rel and not x.get('industria')][:3 - len(rel)]
    rel_html = ''.join(f'<a class="box" href="{x["f"]}"><h3 style="margin:0 0 6px">{x["nombre"]}</h3><p>{x["desc"]}</p></a>' for x in rel)
    body = hero + f'''<section class="sec-sm"><div class="wrap"><div class="kick">Lo que resuelve</div><h2 class="h2">{d["nombre"]} sin trabajo repetido</h2><div class="three ben3">{ben}</div></div></section>
<section class="alt sec-sm"><div class="wrap"><div class="kick">Cómo funciona</div><h2 class="h2">Así se hace en GestiaHR</h2><div class="flow flow4">{stp}</div></div></section>
<section class="sec-sm"><div class="wrap two"><div><div class="kick">Qué incluye</div><h2 class="h2">Todo lo que incluye</h2><ul class="pts">{inc}</ul></div>
<div>{prod_card(k)}</div></div></section>
<div id="preguntas"></div>''' + faq(d['faqs']) + f'''<section class="alt sec-sm"><div class="wrap"><div class="kick">También te puede servir</div><h2 class="h2">Más soluciones de GestiaHR</h2><div class="three">{rel_html}</div></div></section>''' + \
        (cta('Únete a la lista de espera', 'Te avisamos apenas el control de asistencia esté disponible.') if soon else cta())
    ld = [{'@type': 'WebPage', '@id': url_of(d['f']) + '#pagina', 'url': url_of(d['f']), 'name': d['title'], 'description': d['desc'], 'inLanguage': 'es-PE',
           'about': {'@id': url_of(r) + '#app'}, 'publisher': {'@id': SITE + '/#org'}}]
    page(d['f'], d['title'], d['desc'], body, active='soluciones.html' if ind else '', ld=ld)

for _d in SERVICIOS:
    servicio_page(_d)

# ---------------------------------------------------------------- soluciones (índice de industrias y procesos)
CRUMBS['soluciones.html'] = [('Soluciones', 'soluciones.html')]
SEO['soluciones.html'] = ('Soluciones de RR. HH. por industria y proceso | GestiaHR', 'GestiaHR para retail, agroindustria, empresas de servicios y estudios contables, y para cada proceso: legajo, vacaciones, CTS, gratificaciones, boletas y PLAME.')
_ind = [('retail.html', 'Retail y cadenas', 'Muchas tiendas, rotación alta y varias razones sociales.')] + [(x['f'], x['nombre'], x['desc']) for x in SERVICIOS if x.get('industria')]
_proc = [(x['f'], x['nombre'], x['desc'], prod(x['prod'])) for x in SERVICIOS if not x.get('industria')] + [('software-de-planillas.html', 'Software de planillas', 'Qué es, qué calcula y cómo elegirlo.', prod('planilla'))]
body = phero('Soluciones', 'GestiaHR para tu industria y para cada proceso de RR. HH.', 'Elige tu industria o el proceso que quieres ordenar. Todas las soluciones usan la misma ficha del colaborador.', crumbs='<a href="inicio.html">Inicio</a> / Soluciones')
body += '<section class="sec-sm"><div class="wrap"><div class="kick">Por industria</div><h2 class="h2">Soluciones por industria</h2><div class="three">' + \
        ''.join(f'<a class="box" href="{f}"><h3 style="margin:0 0 6px">{t}</h3><p>{x}</p></a>' for f, t, x in _ind) + '</div></div></section>'
body += '<section class="alt sec-sm"><div class="wrap"><div class="kick">Por proceso</div><h2 class="h2">Soluciones por proceso</h2><div class="three">' + \
        ''.join(f'<a class="box c" style="--c:{p[3]}" href="{f}"><h3 style="margin:0 0 6px">{t} <span class="sig" style="color:{p[3]}">{p[2]}</span></h3><p>{x}</p></a>' for f, t, x, p in _proc) + '</div></div></section>' + cta()
page('soluciones.html', 'Soluciones · GestiaHR', '', body, active='soluciones.html')

# ---------------------------------------------------------------- páginas de producto (páginas de venta)
def product_page(key):
    k, n, s, c, r, d, st = prod(key); i = INFO[key]
    plans = ''.join(f'<span class="pchip{" on" if p in i["incl"] else ""}">{p}</span>' for p in PLANES)
    if st == 'ok':
        side = f'''<div class="buy"><small>Precio</small><div class="price">{("Desde " + soles(desde(key))) if desde(key) else "Por anunciar"} <small>al mes</small></div><p>{i["precio"]}.</p>
<div class="pc-plans"><small>Incluido en</small>{plans}</div>{f'<p class="addon-l">O súmalo a tu plan: <b>{soles(PRECIO_MODULO[key])}</b> por colaborador al mes.</p>' if i["addon"] else ''}
<a class="btn btn-primary block" href="registro.html?producto={k}">Empezar prueba gratis</a><a class="btn btn-line block" href="#precios">Ver precios</a><small class="fine2">Sin tarjeta · Precios de ejemplo, sin IGV</small></div>'''
    else:
        side = f'''<div class="buy"><span class="soon" style="align-self:flex-start">Próximamente</span><p style="margin-top:12px">{n} está en camino. Anótate y te avisamos apenas esté disponible.</p>
<div class="pc-plans"><small>Se incluirá en</small>{plans}</div><a class="btn btn-primary block" href="#avisame">Avísame</a><a class="btn btn-line block" href="suite.html">Ver productos disponibles</a></div>'''
    hero = f'''<div class="phero ph2"><div class="wrap ph2-grid"><div><div class="crumbs"><a href="inicio.html">Inicio</a> / <a href="suite.html">Productos</a> / {n}</div>
<div class="kick">{n} · <span class="sig" style="color:{c}">{s}</span></div><h1>{i["h1"]}</h1><p class="lead">{i["lead"]}</p>
<ul class="mini-ben">{"".join(f"<li>{b[0]}</li>" for b in i["ben"])}</ul></div>{side}</div></div>
<nav class="jump"><div class="wrap"><b class="jump-t">{n} <span class="sig" style="color:{c}">{s}</span></b><a href="#beneficios">Beneficios</a><a href="#funciones">Funciones</a><a href="#precios">Precios</a><a href="#empezar">Cómo empezar</a><a href="#preguntas">Preguntas</a>
{'<a class="btn btn-primary" href="registro.html?producto=' + k + '">Prueba gratis</a>' if st == 'ok' else '<a class="btn btn-primary" href="#avisame">Avísame</a>'}</div></nav>'''
    ben = ''.join(f'<div class="bencard" style="--c:{c}"><em>0{j + 1}</em><h3>{t}</h3><p>{x}</p></div>' for j, (t, x) in enumerate(i['ben']))
    feats = ''.join(f'<div class="box c" style="--c:{c}"><h3>{t}</h3><p>{x}</p></div>' for t, x in i['feats'])
    soon = ''
    if i['soon']:
        soon = '<h3 class="soon-h">En camino <span class="soon">Próximamente</span></h3><div class="three">' + ''.join(f'<div class="box"><h3>{t}</h3><p>{x}</p></div>' for t, x in i['soon']) + '</div><p style="margin-top:14px"><a href="#avisame" style="color:var(--navy);font-weight:600">Avísame cuando esté listo →</a></p>'
    shot_html = (shot(i['img'], i['alt'], full=True) + '<p class="cap">Pantalla del sistema con datos de demostración.</p>') if i['img'] else ''
    hl = {'personas': 'esencial', 'tiempo': 'esencial', 'planilla': 'profesional', 'talento': 'corporativo', 'formacion': 'corporativo'}[key]
    addon_box = f'<div class="box addon-box"><div><h3>¿Ya tienes un plan?</h3><p>Suma {n} a tu plan por <b>{soles(PRECIO_MODULO[key])}</b> por colaborador al mes.</p></div><a class="btn btn-line" href="precios.html#modulos">Ver productos individuales</a></div>' if i['addon'] and st == 'ok' else ''
    wait = ''
    if st == 'soon' or i['soon']:
        que = n if st == 'soon' else 'asistencia y turnos'
        wait = f'''<section class="alt" id="avisame"><div class="wrap two"><div><div class="kick">Lista de espera</div><h2 class="h2">Te avisamos cuando {que} esté disponible</h2>
<p class="lead">Mientras tanto puedes empezar con Personas, Tiempo o Planilla. Lo que registres ahí se usará después sin volver a cargar nada.</p></div>
<form class="box form" onsubmit="this.innerHTML='<p><b>¡Listo!</b> Te escribiremos apenas esté disponible.</p>';return false">
<label class="f">Nombre<input class="in" required></label><label class="f">Correo de trabajo<input class="in" type="email" required></label>
<label class="f">Empresa<input class="in"></label><label class="f">Número de colaboradores<select class="in"><option>Hasta 50</option><option>51 a 200</option><option>201 a 1 000</option><option>Más de 1 000</option></select></label>
<label class="chk"><input type="checkbox" required> Acepto la <a href="privacidad.html" style="color:var(--navy)">política de privacidad</a>.</label>
<button class="btn btn-primary block">Avísame</button></form></div></section>'''
    otros = ''.join(prod_card(x) for x in ['personas', 'planilla', 'tiempo', 'talento', 'formacion'] if x != key)
    steps = [('Crea tu cuenta', 'Registra tu empresa con su RUC y elige tu subdominio.'), ('Carga a tu equipo', 'Importa a tus colaboradores desde una plantilla de Excel.'),
             ('Empieza a usarlo', 'Con datos de ejemplo o con los tuyos; te acompañamos en la migración.')]
    stp = ''.join(f'<div><em>0{j + 1}</em><b>{t}</b><span>{x}</span></div>' for j, (t, x) in enumerate(steps))
    body = hero + f'''<section id="beneficios" class="sec-sm"><div class="wrap"><div class="kick">Beneficios</div><h2 class="h2">Lo que gana tu empresa con {n}</h2><div class="three ben3">{ben}</div></div></section>
<section id="funciones" class="alt sec-sm"><div class="wrap"><div class="kick">Funciones</div><h2 class="h2">Todo lo que incluye</h2>{shot_html}<div class="three" style="margin-top:28px">{feats}</div>{soon}</div></section>
{wait}
<section id="precios" class="sec-sm"><div class="wrap"><div class="sec-head"><div><div class="kick">Precios</div><h2 class="h2">Planes que incluyen {n}</h2><p class="lead">El plan resaltado es el más económico que lo incluye.</p></div><a class="btn btn-line" href="precios.html#comparar">Comparación completa →</a></div>
{plan_cards(highlight=hl)}{addon_box}<p class="note">{NOTA_PRECIOS}</p></div></section>
<section id="empezar" class="alt sec-sm"><div class="wrap"><div class="kick">Cómo empezar</div><h2 class="h2">En marcha en tres pasos</h2><div class="flow" style="grid-template-columns:repeat(3,1fr)">{stp}</div>
<div class="ctas" style="display:flex;gap:12px;margin-top:28px;flex-wrap:wrap"><a class="btn btn-primary" href="registro.html?producto={k}">Empezar prueba gratis</a><a class="btn btn-line" href="demo.html">Prefiero una demo</a></div></div></section>
<div id="preguntas"></div>''' + faq(i['faqs']) + f'''<section class="alt sec-sm"><div class="wrap"><div class="sec-head"><div><div class="kick">Combínalo con</div><h2 class="h2">Otros productos de GestiaHR</h2></div><a class="btn btn-line" href="suite.html">Ver la Suite →</a></div>
<div class="pgrid">{otros}</div></div></section>''' + cta()
    guias = [x for x in SERVICIOS if x['prod'] == key and not x.get('industria')]
    if guias:
        g = ''.join(f'<a class="box c" style="--c:{c}" href="{x["f"]}"><h3 style="margin:0 0 6px">{x["nombre"]}</h3><p>{x["desc"]}</p></a>' for x in guias)
        body = body.replace('<div id="preguntas"></div>', f'<section class="sec-sm"><div class="wrap"><div class="kick">Procesos</div><h2 class="h2">Lo que puedes resolver con {n}</h2><div class="three">{g}</div></div></section>\n<div id="preguntas"></div>', 1)
    CRUMBS[r] = [('Productos', 'suite.html'), (n, r)]
    app = {'@type': 'SoftwareApplication', '@id': url_of(r) + '#app', 'name': n, 'alternateName': f'{n} ({s})', 'applicationCategory': 'BusinessApplication',
           'applicationSubCategory': s, 'operatingSystem': 'Web', 'url': url_of(r), 'description': SEO[r][1], 'inLanguage': 'es-PE', 'publisher': {'@id': SITE + '/#org'}}
    if st == 'ok' and desde(key):
        app['offers'] = {'@type': 'AggregateOffer', 'priceCurrency': 'PEN', 'lowPrice': desde(key), 'offerCount': len([p for p in i['incl'] if p.lower() in PRECIO_PLAN]), 'url': url_of('precios.html')}
    page(r, f'{n} ({s}) · GestiaHR', i['lead'], body, ld=[app])

for _k in ['personas', 'planilla', 'tiempo', 'talento', 'formacion']:
    product_page(_k)


# ---------------------------------------------------------------- suite
body = phero('Suite GestiaHR · HCM', 'Todos los productos de GestiaHR en una sola plataforma',
             'Un acceso, una ficha por colaborador y un solo contrato. Empieza con lo que necesitas y activa el resto cuando tu empresa crezca.',
             crumbs='<a href="inicio.html">Inicio</a> / Suite', extra=shot('inicio.jpg', 'Panel de inicio de GestiaHR'))
body += f'''<nav class="jump"><div class="wrap"><b class="jump-t">Suite <span class="sig" style="color:var(--navy)">HCM</span></b><a href="#productos">Productos</a><a href="#por-que">Por qué una sola plataforma</a><a href="#planes">Planes</a><a class="btn btn-primary" href="registro.html">Prueba gratis</a></div></nav>
<section id="productos" class="sec-sm"><div class="wrap"><div class="kick">Productos</div><h2 class="h2">Lo que incluye la Suite</h2><div class="pgrid">{"".join(prod_card(k) for k in ["personas", "planilla", "tiempo", "talento", "formacion"])}</div></div></section>
<section id="por-que" class="alt"><div class="wrap two"><div><div class="kick">Por qué una sola plataforma</div><h2 class="h2">La información se registra una vez</h2>
<p class="lead">Cuando contratas a alguien, sus datos pasan a la planilla, sus vacaciones empiezan a contarse y queda listo para sus capacitaciones y evaluaciones.</p></div>
<ul class="pts"><li><b>Una ficha por persona</b><span>Todos los productos leen y escriben en el mismo legajo.</span></li>
<li><b>Un solo acceso</b><span>Cada usuario entra una vez y ve lo que su rol permite.</span></li>
<li><b>Un solo contrato</b><span>Sumas o quitas productos sin cambiar de proveedor.</span></li></ul></div></section>
<section id="planes" class="sec-sm"><div class="wrap"><div class="kick">Planes</div><h2 class="h2">La Suite completa está en el plan Corporativo</h2>{plan_cards(highlight="corporativo")}<p class="note">{NOTA_PRECIOS}</p></div></section>
''' + cta()
CRUMBS['suite.html'] = [('Suite GestiaHR', 'suite.html')]
page('suite.html', 'Suite GestiaHR (HCM) · todos los productos', 'Todos los productos de GestiaHR integrados.', body)

# ---------------------------------------------------------------- precios
def plan_col(name, forw, lim, items, btn, pop=False, price='S/ —'):
    li = ''.join(f'<li class="no">{i[1:]}</li>' if i.startswith('!') else f'<li>{i}</li>' for i in items)
    badge = '<span class="badge">Recomendado</span>' if pop else ''
    cls = ' pop' if pop else ''
    pr = f'<div class="price">{price} <small>al mes</small></div>' if price == 'S/ —' else f'<div class="price" style="font-size:26px;margin-top:26px">{price}</div>'
    return f'<div class="plan{cls}">{badge}<h3>{name}</h3><div class="for">{forw}</div>{pr}<div class="lim">{lim}</div><ul>{li}</ul>{btn}</div>'
plans = plan_col('Esencial', 'Para ordenar tu equipo', 'Hasta 50 colaboradores', ['Gestia Personas · HRIS', 'Vacaciones y ausencias', '!No incluye Planilla'], '<a class="btn btn-line" href="registro.html?plan=esencial">Elegir Esencial</a>') + \
        plan_col('Profesional', 'Para empresas en crecimiento', 'Hasta 200 colaboradores', ['Todo lo de Esencial', 'Gestia Planilla · PAY', 'Boletas firmadas', 'Asistente de ayuda con IA'], '<a class="btn btn-primary" href="registro.html?plan=profesional">Probar Profesional</a>', pop=True) + \
        plan_col('Corporativo', 'Para medianas empresas', 'Hasta 1 000 colaboradores', ['Todos los módulos disponibles', 'Integraciones y API', 'Soporte prioritario'], '<a class="btn btn-line" href="registro.html?plan=corporativo">Elegir Corporativo</a>') + \
        plan_col('Empresas', 'Para grandes organizaciones', 'Más de 1 000 colaboradores', ['Implementación dedicada', 'Integración con tu ERP', 'Acuerdos a medida'], '<a class="btn btn-line" href="demo.html">Hablar con ventas</a>', price='A la medida')
Y, N, P = '<span class="yes">✓</span>', '<span class="no-c">—</span>', '<span class="soon">Próximamente</span>'
rows = [('grp', '<a href="personas.html">Gestia Personas · HRIS →</a>'), ('Ficha y legajo digital', Y, Y, Y), ('Contratos y avisos de vencimiento', Y, Y, Y), ('Portal del colaborador', Y, Y, Y),
        ('grp', '<a href="tiempo.html">Gestia Tiempo · WFM →</a>'), ('Vacaciones, permisos y ausencias', Y, Y, Y), ('Asistencia y turnos', N, N, P),
        ('grp', '<a href="planilla.html">Gestia Planilla · PAY →</a>'), ('Cálculo de planilla y beneficios', N, Y, Y), ('Boletas firmadas', N, Y, Y), ('PLAME, T-Registro y pago al banco', N, Y, Y),
        ('grp', '<a href="talento.html">Gestia Talento · TMS</a> y <a href="formacion.html">Formación · LMS</a>'), ('Selección, desempeño y carrera', N, N, P), ('Cursos propios y certificados', N, N, P),
        ('grp', 'Plataforma'), ('Asistente de ayuda con IA', N, Y, Y), ('Seguridad y salud en el trabajo', N, N, P), ('Integraciones y API', N, N, Y), ('Soporte', 'Correo', 'Correo y chat', 'Prioritario')]
tr = ''
for r in rows:
    if r[0] == 'grp':
        tr += f'<tr class="grp"><td colspan="4">{r[1]}</td></tr>'
    else:
        tr += f'<tr><td>{r[0]}</td><td class="c">{r[1]}</td><td class="c">{r[2]}</td><td class="c">{r[3]}</td></tr>'
addons = ''.join(f'<div class="addon"><span><a href="{r}"><b>{n}</b></a> <span class="sig" style="color:{c}">{s}</span><br><small style="color:var(--mut)">{d}</small></span>' +
                 ((f'<span><b>{soles(PRECIO_MODULO[k])}</b> <small style="color:var(--mut)">por colaborador</small></span></div>' if k in PRECIO_MODULO else '<span><b>Incluido</b> <small style="color:var(--mut)">en todos los planes</small></span></div>') if st == 'ok' else f'<a class="btn btn-line" style="padding:7px 12px;font-size:14px" href="{r}#avisame">Avísame</a></div>')
                 for k, n, s, c, r, d, st in PRODUCTS)
body = phero('Precios', 'Un plan para cada etapa de tu empresa', 'Precios en soles y sin costos ocultos. Elige tu plan o arma el tuyo sumando productos.', ctas=False)
body += f'''<nav class="jump"><div class="wrap"><a href="#planes">Planes</a><a href="#elegir">Ayuda para elegir</a><a href="#comparar">Comparar</a><a href="#modulos">Productos individuales</a><a href="#preguntas">Preguntas</a><a class="btn btn-primary" href="registro.html">Prueba gratis</a></div></nav>
<section class="sec-sm" id="planes"><div class="wrap">
<div class="toggle" style="display:inline-flex;background:#fff;border:1px solid var(--line);border-radius:8px;padding:4px"><a class="btn btn-primary" style="padding:7px 16px;font-size:14px">Mensual</a><a class="btn" style="padding:7px 16px;font-size:14px;color:var(--mut)">Anual</a></div> <small style="color:var(--mut);margin-left:8px">Pago anual: {DESCUENTO_ANUAL}</small>
{plan_cards()}
<p class="note">{NOTA_PRECIOS} Las siglas llevan a la página de cada producto.</p></div></section>
<section class="alt sec-sm" id="elegir"><div class="wrap two"><div><div class="kick">Ayuda para elegir</div><h2 class="h2">¿No sabes qué plan te conviene?</h2><p class="lead">Responde dos preguntas y te recomendamos uno.</p></div>
<div class="box form finder"><label class="f">¿Cuántos colaboradores tienes?<select class="in" id="fz"><option value="1">Hasta 50</option><option value="2">51 a 200</option><option value="3">201 a 1 000</option><option value="4">Más de 1 000</option></select></label>
<label class="f">¿Necesitas calcular la planilla en GestiaHR?<select class="in" id="fp"><option value="1">Sí</option><option value="0">No, por ahora solo legajo y vacaciones</option></select></label>
<div class="finder-r" id="fr"></div></div></div></section>
<script>
(function(){{const P={{1:['Esencial','esencial'],2:['Profesional','profesional'],3:['Corporativo','corporativo']}};
function r(){{const z=+document.getElementById('fz').value,pl=+document.getElementById('fp').value;let k;
if(z===4){{document.getElementById('fr').innerHTML='<b>Te recomendamos el plan Empresas.</b><p>Conversemos sobre tu operación.</p><a class="btn btn-primary" href="demo.html">Hablar con ventas</a>';return}}
k=z===1?(pl?2:1):z===2?2:3;const x=P[k];document.querySelectorAll('.plan').forEach(e=>e.classList.remove('rec'));const el=document.getElementById('plan-'+x[1]);if(el)el.classList.add('rec');
document.getElementById('fr').innerHTML='<b>Te recomendamos el plan '+x[0]+'.</b><p>'+(z===1&&pl?'Esencial no incluye Planilla; Profesional sí. También puedes sumar Planilla a Esencial.':'Lo marcamos en la lista de planes.')+'</p><a class="btn btn-primary" href="registro.html?plan='+x[1]+'">Probar '+x[0]+'</a> <a class="btn btn-line" href="#planes">Ver el plan</a>'}}
document.getElementById('fz').addEventListener('change',r);document.getElementById('fp').addEventListener('change',r);r()}})();
</script>
<section class="sec-sm" id="comparar"><div class="wrap"><div class="kick">Comparación</div><h2 class="h2">Qué incluye cada plan</h2>
<div class="tablewrap" style="margin-top:28px"><table class="data"><tr><th>Función</th><th class="c">Esencial</th><th class="c">Profesional</th><th class="c">Corporativo</th></tr>{tr}</table></div></div></section>
<section class="alt sec-sm" id="modulos"><div class="wrap two"><div><div class="kick">Productos individuales</div><h2 class="h2">Añade productos a tu plan</h2>
<p class="lead">Si tu plan no incluye un producto, súmalo por colaborador. Los que están en camino tienen lista de espera.</p></div>
<div class="box">{addons}</div></div></section>
<div id="preguntas"></div>''' + faq([('¿Necesito tarjeta para la prueba?', 'No. Pruebas con datos de ejemplo o con tu empresa y decides después.'),
          ('¿Puedo cambiar de plan?', 'Sí, cuando quieras. El cambio se refleja en tu siguiente factura.'),
          ('¿Cómo se cuentan los colaboradores?', 'Cuenta cada persona con al menos un contrato activo, aunque trabaje en dos empresas de tu grupo. Locadores, pre-ingresos y cesados no cuentan.'),
          ('¿Emiten factura?', 'Sí, factura electrónica a nombre de tu empresa.')]) + cta()
CRUMBS['precios.html'] = [('Precios', 'precios.html')]
_offers = [{'@type': 'Offer', 'name': f'Plan {n}', 'price': PRECIO_PLAN[k], 'priceCurrency': 'PEN', 'url': url_of('precios.html') + f'#plan-{k}',
            'priceSpecification': {'@type': 'UnitPriceSpecification', 'price': PRECIO_PLAN[k], 'priceCurrency': 'PEN', 'unitText': 'MES', 'valueAddedTaxIncluded': False}} for k, n, *_ in PLAN_DATA]
page('precios.html', 'Precios y planes · GestiaHR', 'Planes Esencial, Profesional, Corporativo y Empresas.', body, active='precios.html',
     ld=[{'@type': 'Product', 'name': 'GestiaHR', 'brand': {'@type': 'Brand', 'name': 'GestiaHR'}, 'description': SEO['precios.html'][1], 'url': url_of('precios.html'),
          'offers': {'@type': 'AggregateOffer', 'priceCurrency': 'PEN', 'lowPrice': min(PRECIO_PLAN.values()), 'highPrice': max(PRECIO_PLAN.values()), 'offerCount': len(_offers), 'offers': _offers}}])

# ---------------------------------------------------------------- SEO: software de planillas
body = phero('Software de planillas en Perú', 'Software de planillas para empresas peruanas',
             'Calcula la planilla de régimen general, MYPE y agrario, entrega boletas firmadas y genera PLAME y T-Registro desde un solo sistema.',
             crumbs='<a href="inicio.html">Inicio</a> / Software de planillas', extra=shot('nomina.jpg', 'Periodos de planilla en GestiaHR'))
body += '''<section><div class="wrap article"><nav class="toc"><b>En esta página</b><a href="#que-es">Qué es</a><a href="#que-calcula">Qué calcula</a><a href="#regimenes">Regímenes</a><a href="#elegir">Cómo elegir</a></nav>
<div class="prose">
<h2 id="que-es">Qué es un software de planillas</h2><p>Es el sistema que calcula lo que cada colaborador recibe al mes y lo que la empresa paga por él: remuneraciones, descuentos, aportes y beneficios. En el Perú, además, debe preparar la información para la Planilla Electrónica (PLAME y T-Registro) y la boleta de pago.</p>
<h2 id="que-calcula">Qué calcula GestiaHR</h2><ul><li>Remuneración, horas extra, asignación familiar y conceptos variables.</li><li>Aportes al sistema de pensiones y a EsSalud.</li><li>Retenciones de quinta categoría.</li><li>Gratificaciones, CTS, vacaciones y liquidaciones.</li><li>Préstamos y adelantos.</li></ul>
<div class="callout info">Cada parámetro legal se publica con su fecha de vigencia y lo revisan especialistas laborales.</div>
<h2 id="regimenes">Regímenes que soporta</h2>
<table class="data"><tr><th>Régimen</th><th>Vacaciones</th><th>Gratificaciones y CTS</th></tr><tr><td>General</td><td>30 días</td><td>Sí</td></tr><tr><td>Pequeña empresa</td><td>15 días</td><td>Mitad</td></tr><tr><td>Microempresa</td><td>15 días</td><td>No</td></tr><tr><td>Agrario</td><td>Según su ley</td><td>Según su ley</td></tr></table>
<p style="margin-top:10px;font-size:14px;color:var(--mut)">Tabla referencial, por validar con el especialista laboral.</p>
<h2 id="elegir">Cómo elegir un software de planillas</h2><ol><li>Que soporte tus regímenes laborales.</li><li>Que genere los archivos que pide SUNAT.</li><li>Que puedas revisar el cálculo antes de cerrar.</li><li>Que te acompañen en la migración.</li><li>Que tus datos estén protegidos y separados de otras empresas.</li></ol>
</div></div></section>
''' + faq([('¿Cuánto cuesta?', 'Gestia Planilla está en el plan Profesional y en el Corporativo. Revisa los <a href="precios.html" style="color:var(--navy)">precios</a>.'),
          ('¿Necesito instalar algo?', 'No. Funciona en el navegador, en tu propio subdominio.')]) + cta()
CRUMBS['software-de-planillas.html'] = [('Software de planillas', 'software-de-planillas.html')]
page('software-de-planillas.html', 'Software de planillas en Perú · GestiaHR', 'Software de planillas para empresas del Perú: régimen general, MYPE y agrario.', body)

# ---------------------------------------------------------------- industria: retail
body = phero('Soluciones · Retail y cadenas', 'Gestión de personas para tiendas, sedes y equipos grandes',
             'Muchas sedes, rotación alta y horarios distintos. GestiaHR ordena los ingresos y ceses, las vacaciones por tienda y la planilla de todas tus razones sociales.',
             crumbs='<a href="inicio.html">Inicio</a> / <a href="soluciones.html">Soluciones</a> / Retail')
body += '''<section><div class="wrap"><div class="three">
<div class="box c" style="--c:var(--hris)"><h3>Altas y ceses rápidos</h3><p>Contrata y da de baja desde la tienda, con los documentos y el registro para T-Registro.</p></div>
<div class="box c" style="--c:var(--wfm)"><h3>Vacaciones por sede</h3><p>Cada jefe de tienda aprueba a su equipo y ve quién sale antes de aprobar.</p></div>
<div class="box c" style="--c:var(--pay)"><h3>Varias razones sociales</h3><p>Una planilla por empresa y una vista consolidada para el grupo.</p></div></div></section>
<section class="alt"><div class="wrap split"><div class="txt"><div class="kick">Para los jefes de tienda</div><h2 class="h2">Cada jefe gestiona a su equipo</h2>
<p class="lead">Los permisos por rol permiten que cada jefe vea solo a su gente, y que RR. HH. tenga la vista completa.</p></div>
<div>''' + shot('colaboradores.jpg', 'Listado de colaboradores por sede', full=True) + '''<p class="cap">Pantalla del sistema con datos de demostración.</p></div></div></section>
''' + cta('Conversemos sobre tu operación', 'Te mostramos GestiaHR con un caso parecido al tuyo.')
CRUMBS['retail.html'] = [('Soluciones', 'soluciones.html'), ('Retail y cadenas', 'retail.html')]
page('retail.html', 'GestiaHR para retail y cadenas', 'Gestión de personas y planilla para empresas con muchas sedes.', body, active='soluciones.html')

# ---------------------------------------------------------------- partners
body = phero('Programa de partners', 'Para estudios contables y consultoras de RR. HH.',
             'Lleva la planilla de todas las empresas que atiendes desde un solo acceso, y cada una mantiene su información separada.', ctas=False)
body += '''<section><div class="wrap two"><div>
<div class="three" style="grid-template-columns:1fr">
<div class="box c"><h3>Un acceso para todas tus empresas</h3><p>Cambias de empresa sin cerrar sesión; cada cliente ve solo lo suyo.</p></div>
<div class="box c"><h3>Tus clientes, tu relación</h3><p>Tú acompañas a tus clientes y nosotros te damos soporte a ti.</p></div>
<div class="box c"><h3>Condiciones del programa</h3><p>Comisión y precios por volumen por definir.</p></div></div></div>
<form class="box form" action="demo-confirmada.html"><h3 style="font-size:20px">Quiero ser partner</h3>
<div class="row2"><label class="f">Nombre<input class="in" required></label><label class="f">Celular<input class="in"></label></div>
<label class="f">Correo de trabajo<input class="in" type="email" required></label>
<div class="row2"><label class="f">Estudio o empresa<input class="in"></label><label class="f">RUC<input class="in" inputmode="numeric" maxlength="11"></label></div>
<label class="f">¿Cuántas empresas atiendes?<select class="in"><option>1 a 5</option><option>6 a 20</option><option>21 a 50</option><option>Más de 50</option></select></label>
<label class="chk"><input type="checkbox" required> Acepto la <a href="privacidad.html" style="color:var(--navy)">política de privacidad</a>.</label>
<button class="btn btn-primary block">Enviar solicitud</button></form></div></section>
'''
CRUMBS['partners.html'] = [('Partners', 'partners.html')]
page('partners.html', 'Programa de partners · GestiaHR', 'Programa para estudios contables y consultoras.', body, active='partners.html')

# ---------------------------------------------------------------- calculadora
body = phero('Calculadora', 'Calculadora de costo laboral', 'Estima cuánto le cuesta a tu empresa un colaborador al mes, según su sueldo y el régimen laboral.', ctas=False)
body += '''<section class="sec-sm"><div class="wrap calc">
<div class="box form">
<label class="f">Sueldo bruto mensual (S/)<input class="in" id="s" type="number" value="3000" min="0" step="50"></label>
<label class="f">Régimen laboral<select class="in" id="r"><option value="g">Régimen general</option><option value="p">Pequeña empresa</option><option value="m">Microempresa</option></select></label>
<label class="f">Remuneración mínima vital de referencia (S/)<input class="in" id="rmv" type="number" value="1130"><small>Verifica el monto vigente.</small></label>
<label class="chk"><input type="checkbox" id="af"> Tiene asignación familiar</label>
</div>
<div class="box"><h3>Costo mensual estimado</h3>
<div class="res-row"><span>Sueldo</span><b id="o1"></b></div>
<div class="res-row"><span>Asignación familiar</span><b id="o2"></b></div>
<div class="res-row"><span>EsSalud (9 %)</span><b id="o3"></b></div>
<div class="res-row"><span>Gratificaciones y bonificación (provisión)</span><b id="o4"></b></div>
<div class="res-row"><span>CTS (provisión)</span><b id="o5"></b></div>
<div class="res-row"><span>Vacaciones (provisión)</span><b id="o6"></b></div>
<div class="res-total"><span>Costo total al mes</span><b id="ot"></b></div>
<div class="callout">Cálculo referencial y simplificado; por validar con el especialista laboral. No considera seguros, horas extra ni conceptos variables.</div>
<a class="btn btn-primary" href="registro.html">Calcular la planilla completa en GestiaHR</a></div>
</div></section>
<script>
function f(n){return 'S/ '+n.toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2})}
function calc(){const s=+document.getElementById('s').value||0,r=document.getElementById('r').value,rmv=+document.getElementById('rmv').value||0,af=document.getElementById('af').checked&&r!=='m'?rmv*0.10:0;
const base=s+af,ess=base*0.09,gf=r==='g'?1:r==='p'?0.5:0,grat=base*2*gf/12*1.09,cts=r==='m'?0:(base+base*2*gf/12/6)*(r==='g'?1:0.5)/12,vac=base*(r==='g'?30:15)/30/12;
const v=[s,af,ess,grat,cts,vac];v.forEach((x,i)=>document.getElementById('o'+(i+1)).textContent=f(x));document.getElementById('ot').textContent=f(v.reduce((a,b)=>a+b,0))}
document.querySelectorAll('#s,#r,#rmv,#af').forEach(e=>e.addEventListener('input',calc));calc();
</script>
'''
CRUMBS['calculadora.html'] = [('Calculadora de costo laboral', 'calculadora.html')]
page('calculadora.html', 'Calculadora de costo laboral · GestiaHR', 'Calcula el costo mensual de un colaborador en el Perú.', body)

# ---------------------------------------------------------------- blog y artículo
import blog as B   # artículos (blog.py)
POSTS = B.POSTS
for _p in POSTS:
    ROUTE[_p['f']] = '/blog/' + _p['slug']
def post_card(x, feat=False):
    cls = 'post feat' if feat else 'post'
    cat = x['cat'] + (' · Destacado' if feat else '')
    return f'<a class="{cls}" href="{x["f"]}"><div class="ph-img"><span class="ms">{x["icon"]}</span></div><div class="body"><span class="cat">{cat}</span><h3>{x["t"]}</h3><p>{x["d"]}</p><span class="meta">Equipo GestiaHR · {x["min"]} min de lectura</span></div></a>'
f0 = (POSTS[0]['f'], POSTS[0]['t'], POSTS[0]['d'])
grid = post_card(POSTS[0], True) + ''.join(post_card(x) for x in POSTS[1:])
cats = ''.join(f'<a>{c}</a>' for c in dict.fromkeys(x['cat'] for x in POSTS))
body = phero('Recursos', 'Blog de GestiaHR', 'Guías prácticas sobre planilla, beneficios sociales y gestión de personas en el Perú.', ctas=False)
body += f'''<section class="sec-sm"><div class="wrap"><div class="chips"><a class="on">Todos</a>{cats}</div>
<div class="posts">{grid}</div>
<div class="box" style="margin-top:40px;display:flex;justify-content:space-between;gap:20px;align-items:center;flex-wrap:wrap"><div><h3>Recibe las guías en tu correo</h3><p>Una vez al mes, con las fechas importantes.</p></div>
<form style="display:flex;gap:8px;flex-wrap:wrap" onsubmit="this.innerHTML='<b>¡Gracias! Revisa tu correo para confirmar.</b>';return false"><input class="in" type="email" placeholder="tu@empresa.com" required style="min-width:240px"><button class="btn btn-primary">Suscribirme</button></form></div>
</div></section>
'''
CRUMBS['blog.html'] = [('Blog', 'blog.html')]
page('blog.html', 'Blog · GestiaHR', 'Guías sobre planilla y gestión de personas en el Perú.', body, active='blog.html',
     ld=[{'@type': 'Blog', 'name': 'Blog de GestiaHR', 'url': url_of('blog.html'), 'inLanguage': 'es-PE', 'publisher': {'@id': SITE + '/#org'},
          'blogPost': [{'@type': 'BlogPosting', 'headline': x['t'], 'url': url_of(x['f'])} for x in POSTS]}])

COMENTARIOS = '''<h2 id="comentarios">Comentarios (2)</h2>
<div class="comment"><b>María L.</b><small>hace 2 días</small><p>¿El calendario incluye las fechas de diciembre?</p></div>
<div class="comment reply"><b>Equipo GestiaHR</b><small>hace 1 día</small><p>Sí, en la sección de beneficios del año.</p></div>
<form class="form" style="margin-top:24px" onsubmit="this.innerHTML='<p><b>Gracias.</b> Tu comentario se publicará cuando lo revisemos.</p>';return false">
<label class="f">Tu comentario<textarea class="in" required></textarea></label><div class="row2"><label class="f">Nombre<input class="in" required></label><label class="f">Correo <small>(no se publica)</small><input class="in" type="email" required></label></div>
<button class="btn btn-primary" style="justify-self:start">Publicar comentario</button><small style="color:var(--mut)">Los comentarios se moderan antes de publicarse.</small></form>
'''
def post_page(x):
    toc = ''.join(f'<a href="#{i}">{t}</a>' for i, t, h in x['s'])
    secs = ''.join(f'<h2 id="{i}">{t}</h2>{h}' for i, t, h in x['s'])
    rel_f, rel_t = x['rel']
    otros = ''.join(post_card(y) for y in [y for y in POSTS if y['f'] != x['f']][:3])
    com = COMENTARIOS if x['f'] == 'articulo.html' else ''
    toc += '<a href="#comentarios">Comentarios</a>' if com else ''
    body = f'''<div class="phero"><div class="wrap" style="max-width:1000px"><div class="crumbs"><a href="blog.html">Blog</a> / {x["cat"]}</div>
<h1>{x["t"]}</h1><p class="lead">{x["d"]} Actualizado el 05/11/2026.</p>
<div class="byline"><span class="av">GH</span><div><b style="color:var(--ink)">Equipo GestiaHR</b><br>Revisado por especialista laboral (por definir) · {x["min"]} min de lectura</div></div></div></div>
<section class="sec-sm"><div class="wrap article"><nav class="toc"><b>Contenido</b>{toc}</nav>
<div class="prose">
<div class="callout">Borrador para revisión. Cada dato marcado [validar] lo confirma el especialista laboral contra la norma y el cronograma vigentes antes de publicar.</div>
{secs}
<div class="box" style="margin:36px 0;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap"><div><h3>Hazlo en GestiaHR</h3><p>Mira cómo se resuelve en <a href="{rel_f}" style="color:var(--navy);font-weight:600">{rel_t}</a>.</p></div><a class="btn btn-primary" href="registro.html">Probar gratis</a></div>
{com}
</div></div></section>
<section class="alt sec-sm"><div class="wrap"><div class="kick">Sigue leyendo</div><h2 class="h2">Más guías del blog</h2><div class="posts">{otros}</div></div></section>
''' + cta()
    CRUMBS[x['f']] = [('Blog', 'blog.html'), (x['t'], x['f'])]
    SEO[x['f']] = ((x['t'] if len(x['t']) <= 58 else x['t'][:x['t'].rfind(' ', 0, 58)]) + ' | GestiaHR', x['d'])
    page(x['f'], x['t'], x['d'], body, active='blog.html',
         ld=[{'@type': 'BlogPosting', 'headline': x['t'], 'description': x['d'], 'inLanguage': 'es-PE', 'dateModified': '2026-11-05', 'datePublished': '2026-11-05',
              'author': {'@id': SITE + '/#org'}, 'publisher': {'@id': SITE + '/#org'}, 'mainEntityOfPage': url_of(x['f']), 'image': SITE + '/assets/img/og-gestiahr.png'}])
for _p in POSTS:
    post_page(_p)

# ---------------------------------------------------------------- demo
slots = ''.join(f'<label><input type="radio" name="h"{" checked" if i == 1 else ""}><div><b>{h}</b><span>45 minutos</span></div></label>' for i, h in enumerate(['Lunes 9, 10:00', 'Lunes 9, 15:00', 'Martes 10, 11:00', 'Miércoles 11, 16:00']))
body = phero('Agendar demo', 'Te mostramos GestiaHR con un caso como el tuyo', 'Una reunión en línea de 45 minutos con un asesor. Revisamos tu proceso actual y cómo sería en GestiaHR.', ctas=False)
body += f'''<section class="sec-sm"><div class="wrap two"><div><h3 style="font-size:20px;font-weight:600;margin-bottom:12px">En la demo verás</h3>
<ul class="pts"><li><b>Tu planilla en GestiaHR</b><span>Con un ejemplo de tu régimen y tu número de colaboradores.</span></li><li><b>Vacaciones y portal del colaborador</b><span>Lo que verá tu equipo desde el celular.</span></li><li><b>Migración</b><span>Cómo pasar tu información y correr en paralelo.</span></li><li><b>Planes</b><span>Qué plan te conviene y qué módulos sumar.</span></li></ul></div>
<form class="box form" action="demo-confirmada.html"><div class="row2"><label class="f">Nombre<input class="in" required></label><label class="f">Celular<input class="in" required></label></div>
<label class="f">Correo de trabajo<input class="in" type="email" required></label><div class="row2"><label class="f">Empresa<input class="in" required></label><label class="f">Colaboradores<select class="in"><option>Hasta 50</option><option>51 a 200</option><option>201 a 1 000</option><option>Más de 1 000</option></select></label></div>
<label class="f">Elige un horario</label><div class="radio-cards">{slots}</div>
<label class="chk"><input type="checkbox" required> Acepto la <a href="privacidad.html" style="color:var(--navy)">política de privacidad</a>.</label>
<button class="btn btn-primary block">Confirmar mi demo</button></form></div></section>
'''
CRUMBS['demo.html'] = [('Agendar demo', 'demo.html')]
page('demo.html', 'Agendar demo · GestiaHR', 'Agenda una demo de GestiaHR.', body)
body = '''<section><div class="wrap done-box"><div class="ic"><span class="ms">event_available</span></div><h1>Tu demo está agendada</h1><p>Lunes 9 de noviembre de 2026, 15:00 (hora de Lima). Te enviamos la invitación con el enlace a tu correo.</p>
<ul class="steps-list"><li><em>01</em><div><b>Revisa tu correo</b><span>Agrega la reunión a tu calendario.</span></div></li><li><em>02</em><div><b>Ten a mano tu planilla actual</b><span>Así te mostramos un ejemplo parecido.</span></div></li><li><em>03</em><div><b>¿Quieres adelantarte?</b><span>Crea tu cuenta de prueba con datos de ejemplo.</span></div></li></ul>
<div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap"><a class="btn btn-primary" href="registro.html">Crear cuenta de prueba</a><a class="btn btn-line" href="demo.html">Cambiar horario</a></div></div></section>
'''
page('demo-confirmada.html', 'Demo agendada · GestiaHR', '', body)

# ---------------------------------------------------------------- registro (3 pasos) y bienvenida
addons_r = ''.join(f'<label class="addon{"" if st == "ok" else " dis"}"><span class="chk"><input type="checkbox"{"" if st == "ok" else " disabled"}> {n} <span class="sig" style="color:{c}">{s}</span></span>' +
                   (f'<span>{soles(PRECIO_MODULO[k])} por colaborador</span></label>' if st == 'ok' else f'<a href="{r}#avisame" style="color:var(--navy);font-weight:600;font-size:14px">Avísame</a></label>')
                   for k, n, s, c, r, d, st in PRODUCTS if k != 'personas')
body = '''<div class="phero" style="padding:36px 0 0;border:0;background:var(--bg-2)"><div class="wrap"><h1 style="font-size:32px;margin:0 0 6px">Crea tu cuenta de GestiaHR</h1><p class="lead" style="font-size:16px">Prueba gratis, sin tarjeta. Al terminar tendrás tu propio subdominio.</p>
<div class="stepper" id="stp" style="margin-bottom:0"><div class="on"><em>1</em><span>Tu cuenta</span></div><div><em>2</em><span>Tu empresa</span></div><div><em>3</em><span>Tu plan</span></div></div></div></div>
<section style="padding:28px 0 80px;background:var(--bg-2)"><div class="wrap checkout"><div class="box">
<div class="step on" data-s="1"><h3 style="font-size:20px">Tu cuenta</h3><div class="form" style="margin-top:16px">
<a class="btn btn-google block" onclick="go(2)"><span class="g-mark"></span> Continuar con Google</a><div class="divider">o con tu correo</div>
<div class="row2"><label class="f">Nombre y apellido<input class="in" value=""></label><label class="f">Celular<input class="in" placeholder="9XX XXX XXX"></label></div>
<label class="f">Correo de trabajo<input class="in" type="email" placeholder="tu@empresa.com"></label><label class="f">Contraseña<input class="in" type="password"><small>Mínimo 10 caracteres.</small></label>
<label class="chk"><input type="checkbox"> Acepto los <a href="terminos.html" style="color:var(--navy)">términos</a> y la <a href="privacidad.html" style="color:var(--navy)">política de privacidad</a>.</label></div>
<div class="step-actions"><span></span><button class="btn btn-primary" onclick="go(2)">Continuar</button></div></div>
<div class="step" data-s="2"><h3 style="font-size:20px">Tu empresa</h3><div class="form" style="margin-top:16px">
<label class="f">RUC<input class="in" value="20601234567" inputmode="numeric" maxlength="11"><span class="hint-ok"><span class="ms">check_circle</span> RUC válido · EMPRESA DE EJEMPLO S.A.C.</span></label>
<div class="row2"><label class="f">Razón social<input class="in" value="EMPRESA DE EJEMPLO S.A.C." readonly></label><label class="f">Nombre comercial<input class="in" value="Ejemplo"></label></div>
<div class="row2"><label class="f">Número de colaboradores<select class="in"><option>Hasta 50</option><option selected>51 a 200</option><option>201 a 1 000</option><option>Más de 1 000</option></select></label>
<label class="f">Régimen laboral<select class="in"><option>General</option><option>MYPE pequeña empresa</option><option>MYPE microempresa</option><option>Agrario</option></select></label></div>
<label class="f">Tu subdominio<div class="subdomain"><input class="in" value="miempresa"><span>.gestiahr.com</span></div><span class="hint-ok"><span class="ms">check_circle</span> Disponible</span></label>
<div class="radio-cards"><label><input type="radio" name="d" checked><div><b>Empezar con datos de ejemplo</b><span>Para explorar sin cargar nada.</span></div></label><label><input type="radio" name="d"><div><b>Empezar en blanco</b><span>Para cargar tu propia información.</span></div></label></div></div>
<div class="step-actions"><button class="btn btn-line" onclick="go(1)">Atrás</button><button class="btn btn-primary" onclick="go(3)">Continuar</button></div></div>
<div class="step" data-s="3"><h3 style="font-size:20px">Tu plan</h3><div class="form" style="margin-top:16px">
<div class="radio-cards"><label><input type="radio" name="p"><div><b>Esencial</b><span>Hasta 50 · Personas y vacaciones</span></div></label><label><input type="radio" name="p" checked><div><b>Profesional</b><span>Hasta 200 · suma Planilla</span></div></label><label><input type="radio" name="p"><div><b>Corporativo</b><span>Hasta 1 000 · todos los módulos</span></div></label><label><input type="radio" name="b" checked><div><b>Pago mensual</b><span>O anual con ''' + DESCUENTO_ANUAL + '''</span></div></label></div>
<div><b style="font-size:15px">Añade módulos</b>''' + addons_r + '''</div>
<label class="f">Cupón<input class="in" placeholder="Opcional"></label>
<div class="radio-cards"><label><input type="radio" name="m" checked><div><b>Empezar prueba gratis</b><span>Sin tarjeta. Decides al final de la prueba.</span></div></label><label><input type="radio" name="m"><div><b>Contratar ahora</b><span>Tarjeta o Yape; factura a nombre de tu empresa.</span></div></label></div></div>
<div class="step-actions"><button class="btn btn-line" onclick="go(2)">Atrás</button><a class="btn btn-primary" href="bienvenida.html">Crear mi empresa</a></div></div>
</div>
<aside class="box summary"><h3>Resumen</h3><p style="margin-bottom:10px">Plan Profesional · mensual</p>
<div class="line"><span>Plan</span><span>''' + soles(PRECIO_PLAN['profesional'], True) + '''</span></div><div class="line"><span>Módulos adicionales</span><span>''' + soles(0, True) + '''</span></div><div class="line"><span>Subtotal</span><span>''' + soles(PRECIO_PLAN['profesional'], True) + '''</span></div><div class="line"><span>IGV (''' + str(round(IGV * 100)) + ''' %)</span><span>''' + soles(PRECIO_PLAN['profesional'] * IGV, True) + '''</span></div><div class="line total"><span>Total al mes</span><span>''' + soles(PRECIO_PLAN['profesional'] * (1 + IGV), True) + '''</span></div>
<p style="font-size:13.5px;margin-top:14px;color:var(--mut)">Durante la prueba no se cobra nada. Precios de ejemplo.</p>
<p style="font-size:13.5px;margin-top:10px;color:var(--mut);display:flex;gap:6px;align-items:center"><span class="ms" style="font-size:18px">lock</span>Pago procesado por la pasarela; no guardamos los datos de tu tarjeta.</p></aside></div></section>
<script>function go(n){document.querySelectorAll('.step').forEach(s=>s.classList.toggle('on',+s.dataset.s===n));document.querySelectorAll('#stp div').forEach((d,i)=>{d.classList.toggle('on',i+1===n);d.classList.toggle('done',i+1<n)});window.scrollTo({top:0,behavior:'smooth'})}</script>
'''
CRUMBS['registro.html'] = [('Crear cuenta', 'registro.html')]
page('registro.html', 'Crear cuenta · GestiaHR', 'Prueba gratis de GestiaHR.', body, widgets=False)
body = '''<section style="background:var(--bg-2)"><div class="wrap done-box"><div class="ic"><span class="ms">check</span></div><h1>¡Tu empresa está lista!</h1><p>Ya puedes entrar a <b>miempresa.gestiahr.com</b>. Te enviamos los datos de acceso a tu correo.</p>
<ul class="steps-list"><li><em>01</em><div><b>Importa a tus colaboradores</b><span>Desde la plantilla de Excel.</span></div></li><li><em>02</em><div><b>Configura tu planilla</b><span>Régimen, conceptos y cuentas bancarias.</span></div></li><li><em>03</em><div><b>Invita a tu equipo</b><span>RR. HH., jefes y colaboradores.</span></div></li><li><em>04</em><div><b>Activa el asistente de ayuda</b><span>Publica tus políticas para que responda con ellas.</span></div></li></ul>
<a class="btn btn-primary" href="sistema.html">Ir a mi panel</a></div></section>
'''
page('bienvenida.html', 'Tu empresa está lista · GestiaHR', '', body, widgets=False)

# ---------------------------------------------------------------- acceso
def auth(fname, title, inner):
    body = f'''<div class="auth"><div class="side-a"><div><h2>Toda la gestión de tu equipo en un solo sistema</h2><p>Legajo, vacaciones y planilla de tu empresa, en tu propio subdominio.</p></div>
{shot("inicio.jpg", "Panel de GestiaHR", full=True)}</div><div class="main-a"><div class="card-a">{inner}</div></div></div>'''
    page(fname, title + ' · GestiaHR', '', body, widgets=False)
auth('ingresar.html', 'Iniciar sesión', '''<h1>Inicia sesión</h1><p class="sub">Entra a tu empresa en GestiaHR.</p><form class="form" action="verificacion.html">
<a class="btn btn-google block" href="verificacion.html"><span class="g-mark"></span> Continuar con Google</a><div class="divider">o con tu correo</div>
<label class="f">Subdominio de tu empresa<div class="subdomain"><input class="in" value="miempresa"><span>.gestiahr.com</span></div></label>
<label class="f">Correo<input class="in" type="email" placeholder="tu@empresa.com"></label><label class="f">Contraseña<input class="in" type="password"></label>
<div style="display:flex;justify-content:space-between;font-size:14px"><label class="chk"><input type="checkbox"> Recordarme</label><a href="recuperar.html" style="color:var(--navy);font-weight:600">¿Olvidaste tu contraseña?</a></div>
<button class="btn btn-primary block">Ingresar</button><p style="font-size:14px;color:var(--mut);text-align:center">¿Aún no tienes cuenta? <a href="registro.html" style="color:var(--navy);font-weight:600">Prueba gratis</a></p></form>''')
auth('verificacion.html', 'Verificación en dos pasos', '''<h1>Verificación en dos pasos</h1><p class="sub">Ingresa el código de 6 dígitos de tu aplicación de autenticación.</p><form class="form" action="sistema.html">
<div class="code"><input maxlength="1" value="4"><input maxlength="1" value="8"><input maxlength="1" value="2"><input maxlength="1"><input maxlength="1"><input maxlength="1"></div>
<label class="chk"><input type="checkbox"> Confiar en este equipo por 30 días</label><button class="btn btn-primary block">Verificar y continuar</button>
<p style="font-size:14px;color:var(--mut)">¿No tienes acceso a tu aplicación? <a href="recuperar.html" style="color:var(--navy);font-weight:600">Usa un código de respaldo</a></p><a href="ingresar.html" style="font-size:14px;color:var(--navy)">← Volver</a></form>''')
auth('recuperar.html', 'Recuperar contraseña', '''<h1>Recupera tu contraseña</h1><p class="sub">Te enviaremos un enlace para crear una nueva.</p><form class="form" onsubmit="this.innerHTML='<div class=callout>Si el correo está registrado, recibirás un enlace en unos minutos. Revisa también tu carpeta de spam.</div><a class=\\'btn btn-line\\' href=ingresar.html>Volver a iniciar sesión</a>';return false">
<label class="f">Correo<input class="in" type="email" required></label><button class="btn btn-primary block">Enviar enlace</button><a href="ingresar.html" style="font-size:14px;color:var(--navy)">← Volver</a></form>''')

# ---------------------------------------------------------------- legales
def legal(fname, title, sections):
    toc = ''.join(f'<a href="#s{i}">{t}</a>' for i, (t, x) in enumerate(sections))
    sec = ''.join(f'<h2 id="s{i}">{i + 1}. {t}</h2>{x}' for i, (t, x) in enumerate(sections))
    body = f'''<div class="phero"><div class="wrap"><div class="kick">Legal</div><h1>{title}</h1><p class="lead">Última actualización: noviembre de 2026.</p></div></div>
<section class="sec-sm"><div class="wrap article"><nav class="toc"><b>Contenido</b>{toc}</nav><div class="prose"><div class="callout">Borrador para revisión del área legal. No publicar sin su aprobación. Lo marcado [por definir] lo completa el negocio o legal; lo marcado [validar] es una referencia normativa que legal confirma contra la norma vigente.</div>{sec}</div></div></section>'''
    CRUMBS[fname] = [(title, fname)]
    page(fname, title + ' · GestiaHR', '', body)
import legales as L   # borradores para revisión legal (legales.py)
legal('terminos.html', 'Términos y condiciones', L.TERMINOS)
legal('privacidad.html', 'Política de privacidad', L.PRIVACIDAD)
legal('cookies.html', 'Política de cookies', L.COOKIES)
legal('tratamiento-de-datos.html', 'Acuerdo de tratamiento de datos', L.ACUERDO)

# ---------------------------------------------------------------- libro de reclamaciones
body = '''<div class="phero"><div class="wrap"><div class="kick">Atención al cliente</div><h1>Libro de Reclamaciones</h1><p class="lead">Conforme al Código de Protección y Defensa del Consumidor. Te responderemos en el plazo que establece la ley.</p></div></div>
<section class="sec-sm"><div class="wrap" style="max-width:860px"><form class="box form" action="reclamo-confirmado.html">
<div class="row2"><label class="f">Fecha<input class="in" value="05/11/2026" readonly></label><label class="f">Proveedor<input class="in" value="[Razón social por definir] · RUC por definir" readonly></label></div>
<h3 style="margin-top:8px">1. Tus datos</h3>
<div class="row2"><label class="f">Nombres y apellidos<input class="in" required></label><label class="f">Tipo y número de documento<input class="in" required placeholder="DNI 12345678"></label></div>
<div class="row2"><label class="f">Correo<input class="in" type="email" required></label><label class="f">Teléfono<input class="in"></label></div>
<label class="f">Domicilio<input class="in"></label>
<label class="chk"><input type="checkbox"> Soy menor de edad (se pedirán los datos del padre, madre o apoderado)</label>
<h3 style="margin-top:8px">2. Lo contratado</h3>
<div class="radio-cards"><label><input type="radio" name="t" checked><div><b>Servicio</b><span>Suscripción a GestiaHR</span></div></label><label><input type="radio" name="t"><div><b>Producto</b><span>Otro</span></div></label></div>
<div class="row2"><label class="f">Monto reclamado (S/)<input class="in" type="number"></label><label class="f">Descripción<input class="in"></label></div>
<h3 style="margin-top:8px">3. Tu reclamo o queja</h3>
<div class="radio-cards"><label><input type="radio" name="r" checked><div><b>Reclamo</b><span>Disconformidad con el servicio contratado.</span></div></label><label><input type="radio" name="r"><div><b>Queja</b><span>Malestar con la atención recibida.</span></div></label></div>
<label class="f">Detalle<textarea class="in" required></textarea></label><label class="f">Pedido<textarea class="in" required></textarea></label>
<label class="chk"><input type="checkbox" required> Declaro que los datos son correctos y acepto la <a href="privacidad.html" style="color:var(--navy)">política de privacidad</a>.</label>
<button class="btn btn-primary block">Enviar reclamo</button></form></div></section>
'''
CRUMBS['libro-reclamaciones.html'] = [('Libro de Reclamaciones', 'libro-reclamaciones.html')]
page('libro-reclamaciones.html', 'Libro de Reclamaciones · GestiaHR', '', body)
body = '''<section><div class="wrap done-box"><div class="ic"><span class="ms">task_alt</span></div><h1>Registramos tu reclamo</h1><p>Tu código es <b style="font-family:var(--f-m)">LR-2026-000123</b>. Te enviamos una copia a tu correo y te responderemos dentro del plazo legal.</p>
<div style="display:flex;gap:12px;justify-content:center;margin-top:28px;flex-wrap:wrap"><a class="btn btn-primary" href="inicio.html">Volver al inicio</a><a class="btn btn-line" onclick="print()">Descargar copia</a></div></div></section>'''
page('reclamo-confirmado.html', 'Reclamo registrado · GestiaHR', '', body)

# ---------------------------------------------------------------- consola de la plataforma (estilo del sistema)
menu = [('dashboard', 'Tablero'), ('apartment', 'Organizaciones'), ('sell', 'Planes y complementos'), ('receipt_long', 'Suscripciones'), ('toggle_on', 'Funciones (flags)'),
        ('language', 'Sitio web'), ('help', 'Ayuda del producto'), ('monitor_heart', 'Salud'), ('backup', 'Copias de seguridad'), ('history', 'Auditoría')]
side = ''.join(f'<a{" class=\"on\"" if t == "Sitio web" else ""}><span class="ms">{i}</span>{t}</a>' for i, t in menu)
tabs = ''.join(f'<a{" class=\"on\"" if i == 0 else ""}>{t}</a>' for i, t in enumerate(['Resumen', 'Leads', 'Planes publicados', 'Blog y contenidos', 'Comentarios', 'Popups y banners', 'Chatbot', 'SEO', 'Prueba social', 'Reclamos']))
leads = [('Ana Torres', 'Demo', 'Retail', '/precios', 'Nuevo', ''), ('Jorge Ríos', 'Prueba', 'Estudio contable', '/partners', 'Contactado', 'g'), ('Lucía Vega', 'Avísame · TMS', 'Agro', '/talento', 'En espera', 'w'), ('Pedro Salas', 'Contacto', 'Servicios', '/inicio', 'Nuevo', '')]
lr = ''.join(f'<tr><td><b style="color:var(--ink)">{a}</b></td><td>{b}</td><td>{c}</td><td style="font-family:var(--f-m);font-size:13px">{d}</td><td><span class="pill-s {g}">{e}</span></td><td><a style="color:var(--navy);font-weight:600">Abrir</a></td></tr>' for a, b, c, d, e, g in leads)
body = f'''<div class="console"><aside class="c-side"><div class="brand"><span class="mk">G</span>GestiaHR</div><div class="lbl">Consola de la plataforma</div>{side}
<div style="margin-top:auto;padding:12px 10px;font-size:12.5px;color:#9DB4D3;border-top:1px solid rgba(255,255,255,.12)">La consola no muestra colaboradores ni planillas de las empresas cliente.</div></aside>
<div><div class="c-top"><div><small>Consola · Superadministrador</small><b>Sitio web</b></div><a class="btn btn-line" href="inicio.html" style="padding:8px 14px;font-size:14px">Ver sitio</a></div>
<div class="c-main"><div class="tabs">{tabs}</div>
<div class="callout" style="margin:0 0 18px">Datos de demostración.</div>
<div class="kpis4"><div class="kpi4"><b>3 240</b><span>Visitas en noviembre</span></div><div class="kpi4" style="--c:var(--green)"><b>42</b><span>Pruebas iniciadas</span></div><div class="kpi4" style="--c:var(--pay)"><b>18</b><span>Demos agendadas</span></div><div class="kpi4" style="--c:var(--yellow)"><b>27</b><span>En lista de espera</span></div></div>
<div class="two" style="gap:18px;grid-template-columns:1fr 1.4fr"><div class="box"><h3>Embudo del mes</h3><div class="funnel" style="margin-top:14px">
<div><span>Visitas</span><i style="width:100%"></i><b>3 240</b></div><div><span>Formularios</span><i style="width:36%"></i><b>118</b></div><div><span>Pruebas</span><i style="width:16%"></i><b>42</b></div><div><span>Pagos</span><i style="width:5%"></i><b>9</b></div></div></div>
<div class="box" style="padding:0;overflow:hidden"><div style="padding:16px 18px;display:flex;justify-content:space-between;align-items:center"><h3>Últimos leads</h3><a class="btn btn-line" style="padding:6px 12px;font-size:13.5px">Exportar</a></div>
<table class="data" style="border:0;border-radius:0"><tr><th>Nombre</th><th>Origen</th><th>Rubro</th><th>Página</th><th>Estado</th><th></th></tr>{lr}</table></div></div>
</div></div></div>'''
page('consola-sitio-web.html', 'Consola · Sitio web · GestiaHR', '', body, widgets=False, plain=True)

# ---------------------------------------------------------------- portada (mapa del sitio para el equipo)
groups = [('Vender', [('inicio.html', 'Inicio'), ('suite.html', 'Suite GestiaHR · HCM'), ('personas.html', 'Gestia Personas · HRIS'), ('planilla.html', 'Gestia Planilla · PAY'), ('tiempo.html', 'Gestia Tiempo · WFM'), ('talento.html', 'Gestia Talento · TMS (lista de espera)'), ('formacion.html', 'Gestia Formación · LMS (lista de espera)'), ('precios.html', 'Precios y planes')]),
          ('Convertir', [('registro.html', 'Registro en 3 pasos'), ('bienvenida.html', 'Tu empresa está lista'), ('demo.html', 'Agendar demo'), ('demo-confirmada.html', 'Demo agendada'), ('partners.html', 'Programa de partners')]),
          ('Contenido y SEO', [('soluciones.html', 'Soluciones por industria y proceso'), ('software-de-planillas.html', 'Página SEO · software de planillas'), ('retail.html', 'Página por industria · retail')] +
                              [(x['f'], ('Página por industria · ' if x.get('industria') else 'Página SEO · ') + x['nombre'].lower()) for x in SERVICIOS] + [ ('blog.html', 'Blog'), ('articulo.html', 'Artículo con comentarios'), ('calculadora.html', 'Calculadora de costo laboral')]),
          ('Cuenta y legales', [('ingresar.html', 'Iniciar sesión'), ('verificacion.html', 'Verificación en dos pasos'), ('recuperar.html', 'Recuperar contraseña'), ('terminos.html', 'Términos y condiciones'), ('privacidad.html', 'Política de privacidad'), ('cookies.html', 'Política de cookies'), ('tratamiento-de-datos.html', 'Acuerdo de tratamiento de datos'), ('libro-reclamaciones.html', 'Libro de Reclamaciones'), ('reclamo-confirmado.html', 'Reclamo registrado')]),
          ('Sistema y consola', [('sistema.html', 'Sistema GestiaHR (prototipo navegable)'), ('consola-sitio-web.html', 'Consola de la plataforma · Sitio web')])]
gh = ''.join(f'<h2 style="font-size:20px;font-weight:600;margin:36px 0 14px">{g}</h2><div class="three">' + ''.join(f'<a class="box" href="{h}"><h3 style="margin:0">{t}</h3><p style="font-family:var(--f-m);font-size:13px;color:var(--mut)">{h}</p></a>' for h, t in items) + '</div>' for g, items in groups)
portada = f'''<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sitio GestiaHR</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="assets/site.css"><link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml"><meta name="robots" content="noindex, nofollow">
<style>body{{background:var(--bg-2)}}a.box:hover{{border-color:var(--navy)}}</style>
<div class="wrap" style="padding-top:48px;padding-bottom:64px"><a class="logo" href="inicio.html">{LOGO_HTML}</a>
<div class="kick" style="margin-top:28px">Kit {VERSION_KIT} · épica WEB · diseño de referencia</div><h1 style="font-size:36px;font-weight:600;margin:10px 0 10px">Sitio comercial gestiahr.com</h1>
<p class="lead">Todas las pantallas del sitio con el mismo estilo del sistema. Abre el inicio y navega como un visitante; «Iniciar sesión» e «Ir a mi panel» llevan al prototipo del sistema.</p>
<div class="callout">Las cifras de las capturas y de la consola son de demostración. Los precios son de ejemplo y se cambian en PRECIO_PLAN y PRECIO_MODULO de build.py. Logos y testimonios son de ejemplo. Legales en borrador.</div>
{gh}</div>
</html>'''
open(os.path.join(OUT, 'portada.html'), 'w', encoding='utf-8').write(portada)

# ---------------------------------------------------------------- recursos
css = open(os.path.join(HERE, 'base.css'), encoding='utf-8').read() + open(os.path.join(HERE, 'extra.css'), encoding='utf-8').read()
open(os.path.join(OUT, 'assets', 'site.css'), 'w', encoding='utf-8').write(css)
for f in os.listdir(os.path.join(HERE, 'img')):
    shutil.copy(os.path.join(HERE, 'img', f), os.path.join(OUT, 'assets', 'img', f))
open(os.path.join(OUT, 'assets', 'img', 'favicon.svg'), 'w', encoding='utf-8').write(g_mark())
open(os.path.join(OUT, 'assets', 'img', 'logo-gestiahr.svg'), 'w', encoding='utf-8').write(logo_svg())
open(os.path.join(OUT, 'assets', 'img', 'logo-gestiahr-blanco.svg'), 'w', encoding='utf-8').write(logo_svg(oscuro=True))
# sitemap.xml y robots.txt: solo páginas indexables; en el producto los publica el CMS (WEB-9)
_sm = ''.join(f'  <url><loc>{url_of(f)}</loc><lastmod>2026-11-05</lastmod></url>\n' for f in sorted(set(INDEXABLES), key=lambda f: (f != 'inicio.html', f)))
open(os.path.join(OUT, 'sitemap.xml'), 'w', encoding='utf-8').write('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' + _sm + '</urlset>\n')
open(os.path.join(OUT, 'robots.txt'), 'w', encoding='utf-8').write('User-agent: *\nAllow: /\n' + ''.join(f'Disallow: /{f[:-5]}\n' for f in ('consola-sitio-web.html', 'portada.html', 'sistema.html')) + f'\nSitemap: {SITE}/sitemap.xml\n')
# verificador de SEO: títulos y descripciones únicos y de largo razonable en las páginas indexables
_seen = {}
for _f in INDEXABLES:
    _src = open(os.path.join(OUT, _f), encoding='utf-8').read()
    _t = html.unescape(re.search(r'<title>(.*?)</title>', _src).group(1)); _d = html.unescape(re.search(r'<meta name="description" content="(.*?)">', _src).group(1))
    assert 15 <= len(_t) <= 70, f'{_f}: título de {len(_t)} caracteres: {_t}'
    assert 50 <= len(_d) <= 170, f'{_f}: descripción de {len(_d)} caracteres'
    assert _t not in _seen, f'{_f}: título repetido con {_seen[_t]}'
    assert '<link rel="canonical"' in _src and 'application/ld+json' in _src, f'{_f}: falta canónica o datos estructurados'
    for _m in re.findall(r'<script type="application/ld\+json">(.*?)</script>', _src): json.loads(_m)
    _seen[_t] = _f
proto = open(os.path.join(KIT, 'prototipo', 'GestiaHR_prototipo.html'), encoding='utf-8').read()
open(os.path.join(OUT, 'sistema.html'), 'w', encoding='utf-8').write('<!DOCTYPE html>\n<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">\n' + proto + '\n</html>')
print('ok', sorted(os.listdir(OUT)))
