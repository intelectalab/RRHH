#!/usr/bin/env python3
"""Genera kit/backlog/ruta-mvp.md: el orden en que se pueden tomar las actividades.
Agrupa por tandas: una actividad entra en la tanda N cuando todo lo que la bloquea está en tandas anteriores.
Dentro de una tanda, las actividades se pueden trabajar en paralelo. Sin tiempos ni responsables.
Uso (desde la raíz del repositorio): python3 kit/tools/gen_ruta.py"""
import json, os
K = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
d = json.load(open(os.path.join(K, 'backlog', 'backlog.json'), encoding='utf-8'))
acts = {}
for f in d['fases']:
    for e in f['epicas']:
        for a in e['actividades']:
            a['_fase'] = f['nombre']; a['_ep'] = e['codigo'] + ' · ' + e['nombre']
            acts[a['clave']] = a

def tandas(sel):
    sel = {k for k in sel}
    lvl = {}
    def L(k, seen=()):
        if k in lvl: return lvl[k]
        deps = [x for x in acts[k]['depende_de'] if x in acts]
        v = 1 + max([L(x, seen + (k,)) for x in deps], default=0)
        lvl[k] = v; return v
    for k in sel: L(k)
    out = {}
    for k in sel: out.setdefault(lvl[k], []).append(k)
    return out, lvl

def bloque(titulo, intro, sel):
    out, lvl = tandas(sel)
    extra = sorted({x for k in sel for x in acts[k]['depende_de'] if x not in sel and x in acts})
    md = [f'## {titulo}', '', intro, '']
    if extra:
        md += ['Usa además estas actividades de fuera del grupo, que deben estar hechas antes: ' + ', '.join(f'`{x}`' for x in extra) + '.', '']
    for n in sorted(out):
        md += [f'### Tanda {n}', '', '| Clave | Actividad | Épica | Tipo | Bloqueada por |', '|---|---|---|---|---|']
        for k in sorted(out[n], key=lambda x: (acts[x]['_ep'], int(x.split('-')[1]) if x.split('-')[1].isdigit() else 0)):
            a = acts[k]
            dep = ', '.join(a['depende_de']) or '—'
            md.append(f"| `{k}` | {a['titulo']} | {a['_ep']} | {'Historia' if a['tipo'] == 'Story' else 'Tarea'} | {dep} |")
        md.append('')
    return md

mvp = [k for k, a in acts.items() if a['mvp']]
ola2 = [k for k, a in acts.items() if a['ola_2']]
web = [k for k, a in acts.items() if a['web']]
md = ['# Ruta de arranque del MVP', '',
      'Generado por `kit/tools/gen_ruta.py` desde `backlog.json`. No se edita a mano.', '',
      'Cada **tanda** reúne actividades cuyas dependencias ya están en tandas anteriores: dentro de una tanda se pueden trabajar en paralelo y en cualquier orden. '
      'No hay tiempos ni responsables: el equipo los asigna al planificar. Para implementar una actividad con Claude Code: `/historia <clave>`.', '',
      f'Resumen: {len(mvp)} actividades del MVP, {len(ola2)} de la segunda ola y {len(web)} del sitio comercial.', '']
md += bloque('1. Primer corte comercial (MVP, etiqueta `mvp`)', 'Lo mínimo para vender: plataforma, seguridad, organizaciones y planes, ficha del colaborador, vacaciones y planilla peruana.', mvp)
md += bloque('2. Sitio comercial (etiqueta `web`)', 'Va en paralelo al MVP y solo depende de él. Diseño de referencia en `kit/docs/web/sitio/`.', web)
md += bloque('3. Segunda ola (etiqueta `ola-2`)', 'Después del MVP.', [k for k in ola2 if k not in mvp])
open(os.path.join(K, 'backlog', 'ruta-mvp.md'), 'w', encoding='utf-8').write('\n'.join(md) + '\n')
print('ruta-mvp.md:', len(mvp), 'mvp ·', len(web), 'web ·', len(ola2), 'ola-2')
