#!/usr/bin/env python3
"""Genera las imágenes de marca del sitio en img/: tarjetas Open Graph (1200 × 630), el logo en PNG para los datos
estructurados y el ícono para iPhone. Solo hace falta volver a ejecutarlo si cambia el logo, un color o un título.
Uso, desde la raíz del repositorio: python3 kit/tools/sitio/gen_og.py   (necesita Node y Playwright con Chromium)."""
import os, json, subprocess, tempfile, importlib.util
HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location('marca', os.path.join(HERE, 'marca.py'))
M = importlib.util.module_from_spec(spec); spec.loader.exec_module(M)

CARDS = [  # archivo, sigla, color de la sigla, título, bajada
    ('og-gestiahr.png', '', '', 'Planillas y gestión de personas para empresas del Perú', 'Legajo, vacaciones, planilla, boletas, PLAME y T-Registro en un solo sistema'),
    ('og-personas.png', 'HRIS', '#4F8BD6', 'Gestia Personas', 'Legajo digital, contratos y organigrama'),
    ('og-tiempo.png', 'WFM', '#2BB3A3', 'Gestia Tiempo', 'Vacaciones, permisos y récord vacacional'),
    ('og-planilla.png', 'PAY', '#8C8CF0', 'Gestia Planilla', 'Planilla, boletas firmadas, PLAME y T-Registro'),
    ('og-talento.png', 'TMS', '#B58CF0', 'Gestia Talento', 'Selección, desempeño y carrera'),
    ('og-formacion.png', 'LMS', '#F4B400', 'Gestia Formación', 'Tus propios cursos y certificados'),
    ('og-suite.png', 'HCM', '#F4B400', 'Suite GestiaHR', 'Todos los productos en una sola plataforma'),
]
FONT = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@500;700&family=IBM+Plex+Mono:wght@500&display=swap">'

def card(sig, col, t, sub):
    s = f'<span style="font-family:IBM Plex Mono;font-size:30px;color:{col};border:2px solid {col};border-radius:8px;padding:6px 14px">{sig}</span>' if sig else ''
    return f'''<html><head>{FONT}</head><body style="margin:0"><div style="width:1200px;height:630px;box-sizing:border-box;padding:72px 80px;background:#0A3A73;color:#fff;font-family:'IBM Plex Sans';display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden">
<div style="position:absolute;right:-120px;bottom:-160px;width:520px;height:520px;border-radius:50%;border:70px solid #F4B400;opacity:.14"></div>
<div style="display:flex;align-items:center;gap:18px"><div style="width:64px;height:64px">{M.g_mark(fondo="#FFFFFF", g="#0A3A73")}</div><b style="font-size:40px">Gestia<span style="color:#5FD1BE">HR</span></b></div>
<div>{s}<div style="font-size:{60 if sig else 56}px;font-weight:700;line-height:1.1;margin-top:26px;max-width:900px">{t}</div><div style="font-size:30px;margin-top:18px;color:#DCE7F5;max-width:900px">{sub}</div></div>
<div style="font-size:24px;color:#9FB6D6">gestiahr.com</div></div></body></html>'''

def icon(size, rx):
    return f'<html><body style="margin:0"><div style="width:{size}px;height:{size}px">{M.g_mark(rx=rx)}</div></body></html>'

jobs = [(os.path.join(HERE, 'img', f), card(*c), 1200, 630) for f, *c in CARDS]
jobs += [(os.path.join(HERE, 'img', 'logo-gestiahr-512.png'), icon(512, 8), 512, 512), (os.path.join(HERE, 'img', 'apple-touch-icon.png'), icon(180, 0), 180, 180)]
with tempfile.TemporaryDirectory() as d:
    lst = []
    for i, (out, h, w, hh) in enumerate(jobs):
        fp = os.path.join(d, f'{i}.html'); open(fp, 'w', encoding='utf-8').write(h); lst.append([fp, out, w, hh])
    js = """const {chromium}=require('playwright');(async()=>{const b=await chromium.launch();const p=await b.newPage();
for(const [f,o,w,h] of JSON.parse(process.argv[1])){await p.setViewportSize({width:w,height:h});await p.goto('file://'+f);await p.waitForTimeout(400);await p.screenshot({path:o});}
await b.close()})()"""
    subprocess.run(['node', '-e', js, json.dumps(lst)], check=True)
print('ok', [os.path.basename(j[0]) for j in jobs])
