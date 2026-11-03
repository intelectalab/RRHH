# -*- coding: utf-8 -*-
import re, json, hashlib, os, sys
import catalogos as C

HERE = os.path.dirname(os.path.abspath(__file__))
KIT = os.path.abspath(os.path.join(HERE, '..', '..'))
TENANT_EXPR = "NULLIF(current_setting('app.tenant_id', true), '')::uuid"
BASE = {'ts': 'timestamptz', 'j': 'jsonb', 'money': 'numeric(14,2)', 'bool': 'boolean'}
FKOPS = ('->', '?->', '=>', '?=>', '~>', '?~>')

def nm(prefix, *parts):
    s = prefix + '_' + '_'.join(parts)
    if len(s) > 63:
        s = s[:54] + '_' + hashlib.md5(s.encode()).hexdigest()[:8]
    return s

def q(v):
    return "'" + str(v).replace("'", "''") + "'"

class Col:
    def __init__(s, name, sql, sens=False, fk=None, notnull=False, typ=''):
        s.name, s.sql, s.sens, s.fk, s.notnull, s.typ = name, sql, sens, fk, notnull, typ

class Table:
    def __init__(s, name, flags, comment, epic, phase):
        s.name, s.flags, s.comment, s.epic, s.phase = name, set(flags), comment, epic, phase
        s.cols, s.uniqs, s.idxs, s.checks, s.raws = [], [], [], [], []
        s.vig = None; s.vigcols = False
    @property
    def platform(s): return 'P' in s.flags

def infer(name):
    if name in ('code', 'name', 'title'): return 'text NOT NULL'
    if name == 'status': return "text NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive'))"
    if name == 'sort_order': return 'integer NOT NULL DEFAULT 0'
    if name.startswith(('is_', 'has_')) or name in ('exceeds_limit', 'dues_authorized', 'contains_sensitive', 'cross_border_transfer', 'requires_objective_cause', 'requires_enrollment', 'requires_consent', 'entitles_family_allowance', 'default_enabled'):
        return 'boolean NOT NULL DEFAULT false'
    if name.endswith('_at'): return 'timestamptz'
    if name.endswith('_on'): return 'date'
    return 'text'

def typetok(name, tok):
    if tok == 'cur': return 'char(3) NOT NULL'
    if tok == 'cur?': return 'char(3)'
    m = re.match(r'^(enum\([^)]*\)|[a-z_]+(?:\([0-9,]+\))?(?:\[\])?)(=[^!]*)?(!)?$', tok)
    if not m: raise SystemExit(f'tipo no reconocido: {name} {tok}')
    base, dflt, bang = m.groups()
    check = ''
    if base.startswith('enum('):
        vals = base[5:-1].split(',')
        check = f" CHECK ({name} IN ({','.join(q(v) for v in vals)}))"
        base = 'text'
    sqlt = BASE.get(base, base)
    out = sqlt
    if dflt is not None:
        d = dflt[1:]
        if sqlt == 'boolean': d = {'f': 'false', 't': 'true'}[d]
        elif sqlt == 'jsonb': d = q(d) + '::jsonb'
        elif d == 'now' and sqlt == 'timestamptz': d = 'now()'
        elif re.match(r'^-?\d+(\.\d+)?$', d) and sqlt != 'text': pass
        else: d = q(d)
        out += f' NOT NULL DEFAULT {d}'
    elif bang:
        out += ' NOT NULL'
    return out + check

def parse(paths):
    phases, epics, tables = [], [], []
    cur = None; phase = None; epic = None
    for p in paths:
        for raw in open(p, encoding='utf-8'):
            line = raw.rstrip('\n')
            if not line.strip(): continue
            if line.startswith('#F '):
                n, t = [x.strip() for x in line[3:].split('|')]; phase = dict(n=int(n), title=t); phases.append(phase); continue
            if line.startswith('#E '):
                c, t = [x.strip() for x in line[3:].split('|')]; epic = dict(code=c, title=t, phase=phase['n'], note=None); epics.append(epic); continue
            if line.startswith('#N '):
                epic['note'] = line[3:].strip(); continue
            if line.startswith('@'):
                head, comment = line[1:].split(' | ', 1)
                parts = head.split()
                cur = Table(parts[0], parts[1:], comment.strip(), epic['code'], phase['n']); tables.append(cur); continue
            for item in line.strip().split(' ; '):
                item = item.strip()
                if not item: continue
                toks = item.split()
                d = toks[0]
                if d == 'uniq': cur.uniqs.append([c.strip() for c in item[5:].split(',')]); continue
                if d == 'idx': cur.idxs.append([c.strip() for c in item[4:].split(',')]); continue
                if d == 'vig':
                    body = item[4:]; where = None
                    if ' | ' in body: body, where = body.split(' | ', 1)
                    cur.vig = ([c.strip() for c in body.split(',')], where); continue
                if d == 'vigcols': cur.vigcols = True; continue
                if d == 'check': cur.checks.append(item[6:]); continue
                if d == 'raw': cur.raws.append(item[4:]); continue
                sens = '#S' in toks
                if sens: toks.remove('#S')
                name = toks[0]
                if name.endswith('_enc'): sens = True
                if len(toks) >= 3 and toks[1] in FKOPS:
                    op = toks[1]; nullable = op.startswith('?'); kind = op.lstrip('?')
                    if kind == '~>':
                        tt, tc = toks[2].split('.'); typ = 'text'
                    else:
                        tt, tc = toks[2], 'id'; typ = 'uuid'
                    sql = typ + ('' if nullable else ' NOT NULL')
                    cur.cols.append(Col(name, sql, sens, (kind, tt, tc), not nullable, typ))
                elif len(toks) == 1:
                    sql = infer(name)
                    if name.endswith('_enc') and sql == 'text': pass
                    cur.cols.append(Col(name, sql, sens, None, 'NOT NULL' in sql, sql.split()[0]))
                else:
                    sql = typetok(name, toks[1]) + (' ' + ' '.join(toks[2:]) if len(toks) > 2 else '')
                    cur.cols.append(Col(name, sql, sens, None, 'NOT NULL' in sql, sql.split()[0]))
    return phases, epics, tables

def validate(tables):
    by = {t.name: t for t in tables}
    assert len(by) == len(tables), 'tabla duplicada'
    for t in tables:
        names = [c.name for c in t.cols]
        assert len(names) == len(set(names)), ('columna duplicada', t.name, names)
        for c in t.cols:
            if not c.fk: continue
            kind, tt, tc = c.fk
            assert tt in by, (t.name, c.name, tt)
            tgt = by[tt]
            if kind == '->':
                assert not t.platform, ('-> en tabla de plataforma', t.name)
                assert not tgt.platform, ('-> hacia tabla de plataforma', t.name, c.name)
            else:
                assert tgt.platform, ('=>/~> hacia tabla de organización', t.name, c.name)
                if kind == '~>':
                    assert [tc] in tgt.uniqs and 'soft' not in tgt.flags, (t.name, c.name)
        allc = set(names) | {'tenant_id', 'valid_from', 'valid_to'}
        for u in t.uniqs + t.idxs + ([t.vig[0]] if t.vig else []):
            for c in u: assert c in allc, ('columna inexistente', t.name, c)
    return by

def table_sql(t, defined, deferred):
    L = []
    def add(sql, cm=None): L.append((sql, cm))
    add('id uuid PRIMARY KEY DEFAULT gen_random_uuid()')
    if not t.platform: add('tenant_id uuid NOT NULL REFERENCES tenants (id)')
    for c in t.cols:
        cm = None
        if c.sens: cm = 'SENSIBLE' + (' (cifrado en la aplicación)' if c.name.endswith('_enc') else '')
        add(f'{c.name} {c.sql}', cm)
    if t.vig or t.vigcols:
        add('valid_from date NOT NULL'); add('valid_to date')
    if 'nots' not in t.flags:
        add('created_at timestamptz NOT NULL DEFAULT now()')
        if not ({'imm', 'ao'} & t.flags): add('updated_at timestamptz NOT NULL DEFAULT now()')
    if 'soft' in t.flags: add('deleted_at timestamptz')
    if 'lock' in t.flags: add('lock_version integer NOT NULL DEFAULT 1')
    if not t.platform: add(f'CONSTRAINT {nm("uq", t.name, "tenant_id")} UNIQUE (tenant_id, id)')
    post = []
    pre = '' if t.platform else 'tenant_id, '
    for u in t.uniqs:
        cols = pre + ', '.join(u)
        if 'soft' in t.flags:
            post.append(f'CREATE UNIQUE INDEX {nm("uq", t.name, *u)} ON {t.name} ({cols}) WHERE deleted_at IS NULL;')
        else:
            add(f'CONSTRAINT {nm("uq", t.name, *u)} UNIQUE ({cols})')
    if t.vig or t.vigcols:
        add(f'CONSTRAINT {nm("ck", t.name, "vigencia")} CHECK (valid_to IS NULL OR valid_to >= valid_from)')
    for i, ch in enumerate(t.checks):
        add(f'CONSTRAINT {nm("ck", t.name, str(i + 1))} CHECK ({ch})')
    if t.vig:
        keys, where = t.vig
        conds = []
        if 'soft' in t.flags: conds.append('deleted_at IS NULL')
        if where: conds.append(where)
        w = f' WHERE ({" AND ".join(conds)})' if conds else ''
        ks = ''.join(f'{k} WITH =, ' for k in keys)
        tk = '' if t.platform else 'tenant_id WITH =, '
        add(f"CONSTRAINT {nm('ex', t.name, 'vigencia')} EXCLUDE USING gist ({tk}{ks}daterange(valid_from, valid_to, '[]') WITH &&){w} DEFERRABLE INITIALLY IMMEDIATE")
    for i, r in enumerate(t.raws):
        pfx = 'ex' if r.startswith('EXCLUDE') else 'uq'
        add(f'CONSTRAINT {nm(pfx, t.name, "r" + str(i + 1))} {r}')
    lead = {tuple(u)[:1] for u in t.uniqs} | {tuple(i)[:1] for i in t.idxs}
    if t.vig: lead.add(tuple(t.vig[0])[:1])
    for c in t.cols:
        if not c.fk: continue
        kind, tt, tc = c.fk
        if kind == '->':
            fk = f'CONSTRAINT {nm("fk", t.name, c.name)} FOREIGN KEY (tenant_id, {c.name}) REFERENCES {tt} (tenant_id, id)'
        else:
            fk = f'CONSTRAINT {nm("fk", t.name, c.name)} FOREIGN KEY ({c.name}) REFERENCES {tt} ({tc})'
        if tt in defined or tt == t.name: add(fk)
        else: deferred.append(f'ALTER TABLE {t.name} ADD {fk};')
        if (c.name,) not in lead:
            post.append(f'CREATE INDEX {nm("ix", t.name, c.name)} ON {t.name} ({pre}{c.name});')
            lead.add((c.name,))
    for i in t.idxs:
        post.append(f'CREATE INDEX {nm("ix", t.name, *i)} ON {t.name} ({pre}{", ".join(i)});')
    out = [f'CREATE TABLE {t.name} (']
    for k, (sql, cm) in enumerate(L):
        comma = ',' if k < len(L) - 1 else ''
        out.append(f'  {sql}{comma}' + (f'  -- {cm}' if cm else ''))
    out.append(');')
    hdr = t.comment + (' -- SENSIBLE: acceso por permiso de campo y registrado en data_access_logs.' if 'sens' in t.flags else '')
    out.append(f'COMMENT ON TABLE {t.name} IS {q(hdr)};')
    out += post
    if not t.platform:
        out.append(f'ALTER TABLE {t.name} ENABLE ROW LEVEL SECURITY;')
        out.append(f'ALTER TABLE {t.name} FORCE ROW LEVEL SECURITY;')
        out.append(f'CREATE POLICY tenant_isolation ON {t.name} USING (tenant_id = {TENANT_EXPR}) WITH CHECK (tenant_id = {TENANT_EXPR});')
    has_upd = 'nots' not in t.flags and not ({'imm', 'ao'} & t.flags)
    if has_upd:
        fn = 'set_updated_at_and_lock' if 'lock' in t.flags else 'set_updated_at'
        out.append(f'CREATE TRIGGER {nm("trg", t.name, "touch")} BEFORE UPDATE ON {t.name} FOR EACH ROW EXECUTE FUNCTION {fn}();')
    elif 'lock' in t.flags:
        out.append(f'CREATE TRIGGER {nm("trg", t.name, "touch")} BEFORE UPDATE ON {t.name} FOR EACH ROW EXECUTE FUNCTION bump_lock_version();')
    if 'imm' in t.flags:
        out.append(f'CREATE TRIGGER {nm("trg", t.name, "inmutable")} BEFORE UPDATE OR DELETE ON {t.name} FOR EACH ROW EXECUTE FUNCTION reject_mutation();')
        out.append(f'CREATE TRIGGER {nm("trg", t.name, "no_truncate")} BEFORE TRUNCATE ON {t.name} FOR EACH STATEMENT EXECUTE FUNCTION reject_mutation();')
    return '\n'.join(out)

PRELUDE = open(os.path.join(HERE, 'prelude.sql'), encoding='utf-8').read()
EPILOGUE = open(os.path.join(HERE, 'epilogue.sql'), encoding='utf-8').read()

def seeds():
    o = []
    o.append("INSERT INTO countries (code, name, default_currency, default_locale) VALUES ('PE', 'Perú', 'PEN', 'es');")
    o.append("INSERT INTO currencies (code, name, symbol) VALUES ('PEN', 'Sol', 'S/'), ('USD', 'Dólar estadounidense', '$'), ('EUR', 'Euro', '€');")
    o.append('INSERT INTO modules (code, name, phase, is_core, epic_codes, description) VALUES')
    o.append(',\n'.join(f"  ({q(c)}, {q(n)}, {ph}, {str(core).lower()}, ARRAY[{','.join(q(e) for e in ep)}], {q(d)})" for c, n, ph, core, ep, req, rec, d in C.MODULES) + ';')
    deps = [(c, r, True) for c, n, ph, core, ep, req, rec, d in C.MODULES for r in req] + [(c, r, False) for c, n, ph, core, ep, req, rec, d in C.MODULES for r in rec]
    o.append('INSERT INTO module_dependencies (module_code, depends_on_code, is_required) VALUES')
    o.append(',\n'.join(f"  ({q(a)}, {q(b)}, {str(r).lower()})" for a, b, r in deps) + ';')
    o.append('INSERT INTO labor_regimes (code, country_code, name, kind) VALUES')
    o.append(',\n'.join(f"  ({q(c)}, 'PE', {q(n)}, {q(k)})" for c, n, k in C.REGIMES_PE) + ';')
    o.append('INSERT INTO system_roles (code, name, description, realm) VALUES')
    o.append(',\n'.join(f"  ({q(c)}, {q(n)}, {q(d)}, {q(r)})" for c, n, r, d, _, _ in C.ROLES) + ';')
    perms = C.permissions()
    o.append('INSERT INTO permissions (code, module_code, resource, action, kind, is_sensitive, description) VALUES')
    o.append(',\n'.join(f"  ({q(p['codigo'])}, {q(p['modulo'])}, {q(p['recurso'])}, {q(p['accion'])}, {q('field' if p['tipo'] == 'campo' else 'action')}, {str(p['sensible']).lower()}, {q(p['descripcion'])})" for p in perms) + ';')
    for r in C.role_matrix():
        o.append(f"INSERT INTO system_role_permissions (role_code, permission_code, scope) VALUES")
        o.append(',\n'.join(f"  ({q(r['codigo'])}, {q(g['codigo'])}, {q(g['alcance'])})" for g in r['permisos']) + ';')
    o.append('INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES')
    rows = []
    for code, mod, name, typ, dflt, scopes, desc, opts in C.PARAMS:
        rows.append(f"  ({q(code)}, {q(mod)}, {q(name)}, {q(desc)}, {q(typ)}, {q(json.dumps(dflt, ensure_ascii=False))}::jsonb, ARRAY[{','.join(q(s) for s in scopes)}], {('NULL' if opts is None else q(json.dumps(opts, ensure_ascii=False)) + '::jsonb')})")
    o.append(',\n'.join(rows) + ';')
    return '\n'.join(o)

def build_schema(phases, epics, tables):
    by = {t.name: t for t in tables}
    out = [PRELUDE]
    defined, deferred = set(), []
    for ph in phases:
        out.append('\n-- ' + '=' * 98)
        out.append(f'-- FASE {ph["n"]} · {ph["title"].upper()}')
        out.append('-- ' + '=' * 98)
        for e in [e for e in epics if e['phase'] == ph['n']]:
            out.append('\n-- ' + '-' * 98)
            out.append(f'-- {e["code"]} · {e["title"]}')
            out.append('-- ' + '-' * 98)
            if e['note']: out.append('-- ' + e['note'])
            for t in [t for t in tables if t.epic == e['code']]:
                out.append('')
                out.append(table_sql(t, defined, deferred))
                defined.add(t.name)
    out.append('\n-- ' + '=' * 98)
    out.append('-- CLAVES FORÁNEAS DIFERIDAS (referencias hacia tablas definidas más abajo en el archivo)')
    out.append('-- ' + '=' * 98)
    out += deferred
    imm = sorted(t.name for t in tables if 'imm' in t.flags)
    cat = sorted(t.name for t in tables if 'cat' in t.flags)
    plat = sorted(t.name for t in tables if t.platform and 'cat' not in t.flags)
    ep = EPILOGUE.replace('{{SEEDS}}', seeds()).replace('{{IMMUTABLE}}', ', '.join(imm)).replace('{{CATALOG}}', ', '.join(cat)).replace('{{PLATFORM_ONLY}}', ', '.join(plat))
    out.append(ep)
    return '\n'.join(out) + '\n'

def write_json(name, obj):
    os.makedirs(f'{KIT}/database/catalogos', exist_ok=True)
    with open(f'{KIT}/database/catalogos/{name}', 'w', encoding='utf-8') as f:
        json.dump(obj, f, ensure_ascii=False, indent=2); f.write('\n')

def build_json():
    perms = C.permissions()
    write_json('permisos_y_roles.json', dict(
        convencion='dominio.recurso.accion, en inglés y minúsculas, alineado con api/openapi.yaml (decisión D-29). Los permisos de campo tienen cuatro partes (dominio.recurso.campo.accion) y tipo «campo». «sensible» = marcado (S) en docs/07-permisos-y-roles.md: su uso queda en data_access_logs. «en_contrato_api» = false para los marcados (+), que aún no figuran en openapi.yaml. «modulo» es el módulo comercial de la épica bajo la que el documento 07 lista el permiso. Los de ámbito «plataforma» son de la consola SaaS y no se copian a las organizaciones.',
        fuente='Generado por tools/esquema/gen.py desde docs/07-permisos-y-roles.md §5 (catálogo y matriz) y docs/01-vision-y-fases.md §4 (módulos). Si esos documentos cambian, hay que regenerar.',
        alcances=[dict(codigo=c, descripcion=d) for c, d in C.SCOPES],
        notas=['Los roles se suman: un jefe tiene Empleado + Manager.', 'Si dos concesiones dan el mismo permiso, vale el alcance más amplio.',
               'En la matriz, la marca de «concedido» se traduce al alcance por defecto del rol; la marca de «solo lo propio o asignado» se traduce a «own» en Empleado y Manager y a «assigned» en los demás roles (criterio a confirmar).',
               'La matriz se carga en system_role_permissions y seed_tenant_defaults la copia a cada organización.'],
        totales=dict(permisos=len(perms), permisos_por_campo=sum(1 for p in perms if p['tipo'] == 'campo'), sensibles=sum(1 for p in perms if p['sensible']), fuera_del_contrato_api=sum(1 for p in perms if not p['en_contrato_api']), roles=len(C.ROLES)),
        permisos=perms, roles=C.role_matrix()))
    write_json('parametros.json', dict(
        nota='Parámetros configurables del producto (no legales). Se guardan en parameter_definitions y su valor por organización, empresa, sede o unidad en tenant_settings. Los valores por defecto son propuestas de producto a confirmar.',
        alcances=dict(organization='Organización', legal_entity='Empresa', location='Sede', org_unit='Unidad'),
        parametros=[dict(codigo=c, modulo=m, nombre=n, tipo=t, valor_por_defecto=d, alcances=s, descripcion=ds, opciones=o) for c, m, n, t, d, s, ds, o in C.PARAMS]))
    write_json('parametros_legales_pe.json', dict(
        pais='PE', moneda='PEN', fuente='docs/research.md, sección 4, y docs/18-localizacion-peru-detalle-tecnico.md (fecha de corte 2 de octubre de 2026)',
        nota='Solo llevan valor los datos que la investigación da por verificados. Los demás van con "verificado": false y valor null: hay que cargarlos desde la fuente oficial antes de calcular planilla. Varias normas se citan desde fuentes secundarias (la nota del parámetro lo indica); conviene que un especialista laboral revise todo el archivo. Los plazos en días hábiles usan la unidad «business_days»; «days» son días calendario. «inicio_vigencia_verificado» indica si la fecha «vigente_desde» consta en la investigación; si es false, es una fecha de referencia.',
        regimenes=[dict(codigo=c, nombre=n, tipo=k) for c, n, k in C.REGIMES_PE],
        totales=dict(parametros=len(C.LEGAL_PE), verificados=sum(1 for p in C.LEGAL_PE if p['verificado']), sin_verificar=sum(1 for p in C.LEGAL_PE if not p['verificado'])),
        parametros=C.LEGAL_PE))
    write_json('conceptos_pe.json', dict(
        pais='PE',
        nota='Plantilla de conceptos de planilla del paquete Perú. Las fórmulas son seudocódigo del motor y solo referencian parámetros legales por código (nunca valores). Las afectaciones NO están verificadas: son una propuesta inicial que un especialista en planillas debe validar concepto por concepto. Los códigos con «codigo_plame_verificado»: true se cotejaron con la Tabla 22 publicada por SUNAT (versión del 01-10-2025, docs/18 §3.2); los demás siguen por confirmar. «regimenes» indica a qué regímenes aplica el concepto, «proyeccion_quinta» su naturaleza en la proyección de quinta categoría (ordinary, gratification, extraordinary) y «rol_monto» qué monto se declara.',
        afectaciones_verificadas=False,
        bases=dict(essalud='Aporte de salud del empleador', pension='Aportes previsionales del trabajador', quinta='Renta de quinta categoría',
                   cts='Remuneración computable de CTS', gratificacion='Remuneración computable de gratificación',
                   vacaciones='Remuneración vacacional', utilidades='Remuneraciones para utilidades'),
        conceptos=C.CONCEPTS_PE))
    write_json('modulos.json', dict(
        nota='Módulos comerciales: lo que una organización contrata (claves de docs/01-vision-y-fases.md §4). «requiere» son dependencias obligatorias; «recomienda» son módulos que enriquecen al módulo pero no lo bloquean. La consola SaaS (SAS) no es un módulo contratable. La épica PAI (paquetes de país) no figura en ese documento: sus tablas y permisos se tratan como parte de «base»; confirmar.',
        modulos=[dict(codigo=c, nombre=n, fase=ph, base=core, epicas=ep, requiere=req, recomienda=rec, descripcion=d) for c, n, ph, core, ep, req, rec, d in C.MODULES]))

if __name__ == '__main__':
    phases, epics, tables = parse([os.path.join(HERE, f'model{i}.dsl') for i in (1, 2, 3)])
    validate(tables)
    os.makedirs(f'{KIT}/database', exist_ok=True)
    with open(f'{KIT}/database/schema.sql', 'w', encoding='utf-8') as f:
        f.write(build_schema(phases, epics, tables))
    build_json()
    print(len(tables), 'tablas;', sum(1 for t in tables if not t.platform), 'de organización')
