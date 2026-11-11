# -*- coding: utf-8 -*-
"""Genera docs/19-parametros-legales-por-validar.md desde database/catalogos/parametros_legales_pe.json.

Uso:  python3 kit/tools/esquema/gen_pendientes.py   (después de gen.py)
"""
import json, os

HERE = os.path.dirname(os.path.abspath(__file__))
KIT = os.path.abspath(os.path.join(HERE, '..', '..'))
MVP_REGIMENES = {None, 'pe_general', 'pe_micro', 'pe_pequena'}

d = json.load(open(os.path.join(KIT, 'database', 'catalogos', 'parametros_legales_pe.json'), encoding='utf-8'))
reg = {r['codigo']: r['nombre'] for r in d['regimenes']}
pend = [p for p in d['parametros'] if not p['verificado']]
fecha = [p for p in d['parametros'] if p['verificado'] and not p['inicio_vigencia_verificado']]
mvp = [p for p in pend if p['regimen'] in MVP_REGIMENES]

def fila(p):
    r = reg.get(p['regimen'], 'Todos') if p['regimen'] else 'Todos'
    m = 'Sí' if p['regimen'] in MVP_REGIMENES else 'No'
    nota = (p['nota'] or '').replace('|', '/')
    return f"| `{p['codigo']}` | {p['nombre']} | {r} | {p['unidad']} | {p['base_legal']} | {m} | {nota} | |"

L = ['# 19 · Parámetros legales por validar', '',
     f'Lista de trabajo para el especialista laboral (decisiones D-15 y D-36). Se genera con `kit/tools/esquema/gen_pendientes.py` desde `database/catalogos/parametros_legales_pe.json`; no se edita a mano.', '',
     f'Hay **{len(pend)} parámetros sin valor verificado**; **{len(mvp)}** afectan al primer corte comercial (régimen general, micro y pequeña empresa) y bloquean su salida (QA-17). '
     'Mientras un parámetro no tenga valor, la regla RN-PAR-03 impide cerrar una planilla que lo use.', '',
     'Para cada fila, el especialista anota el valor, la fecha de inicio de vigencia y la norma oficial (no una fuente secundaria). Luego se carga en `catalogos.py` con `verified=True` y se regenera.', '',
     '## 1. Sin valor verificado', '',
     '| Código | Parámetro | Régimen | Unidad | Base legal citada | Bloquea MVP | Nota | Valor y norma validados |',
     '|---|---|---|---|---|---|---|---|']
L += [fila(p) for p in sorted(pend, key=lambda p: (p['regimen'] not in MVP_REGIMENES, p['codigo']))]
L += ['', '## 2. Con valor, pero con fecha de inicio no verificada', '',
      f'{len(fecha)} parámetros tienen valor verificado y una fecha «vigente_desde» de referencia. Basta con confirmar que el valor regía en esa fecha.', '',
      '| Código | Parámetro | Régimen | Valor | Vigente desde | Base legal citada |', '|---|---|---|---|---|---|']
for p in sorted(fecha, key=lambda p: p['codigo']):
    v = p['valor'] if p['valor'] is not None else json.dumps(p['valor_json'], ensure_ascii=False)
    L.append(f"| `{p['codigo']}` | {p['nombre']} | {reg.get(p['regimen'], 'Todos') if p['regimen'] else 'Todos'} | {v} | {p['vigente_desde']} | {p['base_legal']} |")
L.append('')
open(os.path.join(KIT, 'docs', '19-parametros-legales-por-validar.md'), 'w', encoding='utf-8').write('\n'.join(L))
print(f'{len(pend)} sin validar ({len(mvp)} del MVP); {len(fecha)} con fecha por confirmar')
