# -*- coding: utf-8 -*-
import os, json, subprocess, sys
import gen as G

S = os.path.dirname(os.path.abspath(__file__))
# Conexión a una base con schema.sql ya cargado. Ejemplo: GESTIA_PSQL='psql -h /tmp/pg -p 54917 -U postgres -d gestiahr'
PSQL = os.environ.get('GESTIA_PSQL', 'psql -d gestiahr').split() + ['-X', '-q', '-t', '-A']

def one(sql):
    return subprocess.check_output(PSQL + ['-c', sql], text=True).strip()

Q = {
 'tablas': "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition",
 'tablas_rls': "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition AND c.relrowsecurity AND c.relforcerowsecurity",
 'tablas_plataforma': "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition AND NOT c.relrowsecurity",
 'politicas': "SELECT count(*) FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid WHERE NOT c.relispartition",
 'particionadas': "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'p'",
 'fks': "SELECT count(*) FROM pg_constraint k JOIN pg_namespace n ON n.oid = k.connamespace WHERE n.nspname = 'public' AND k.contype = 'f' AND k.conparentid = 0",
 'fks_compuestas': "SELECT count(*) FROM pg_constraint k JOIN pg_namespace n ON n.oid = k.connamespace WHERE n.nspname = 'public' AND k.contype = 'f' AND k.conparentid = 0 AND array_length(k.conkey, 1) = 2 AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = k.conrelid AND a.attnum = k.conkey[1] AND a.attname = 'tenant_id') AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = k.confrelid AND a.attnum = k.confkey[1] AND a.attname = 'tenant_id')",
 'fks_a_tenants': "SELECT count(*) FROM pg_constraint k WHERE k.contype = 'f' AND k.conparentid = 0 AND k.confrelid = 'tenants'::regclass AND array_length(k.conkey, 1) = 1",
 'indices': "SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND NOT c.relispartition",
 'indices_explicitos': "SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND NOT c.relispartition AND NOT EXISTS (SELECT 1 FROM pg_constraint k WHERE k.conindid = i.indexrelid)",
 'triggers': "SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND NOT t.tgisinternal AND t.tgparentid = 0",
 'exclusiones': "SELECT count(*) FROM pg_constraint k JOIN pg_namespace n ON n.oid = k.connamespace WHERE n.nspname = 'public' AND k.contype = 'x' AND k.conparentid = 0",
 'checks': "SELECT count(*) FROM pg_constraint k JOIN pg_namespace n ON n.oid = k.connamespace WHERE n.nspname = 'public' AND k.contype = 'c' AND k.conislocal",
 'unicas': "SELECT count(*) FROM pg_constraint k JOIN pg_namespace n ON n.oid = k.connamespace WHERE n.nspname = 'public' AND k.contype = 'u' AND k.conparentid = 0",
 'columnas': "SELECT count(*) FROM information_schema.columns c JOIN information_schema.tables t USING (table_schema, table_name) WHERE c.table_schema = 'public' AND t.table_type = 'BASE TABLE' AND NOT EXISTS (SELECT 1 FROM pg_class k WHERE k.relname = c.table_name AND k.relispartition)",
 'funciones': "SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public' AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = p.oid AND d.deptype = 'e')",
 'vistas': "SELECT count(*) FROM pg_views WHERE schemaname = 'public'",
 'tablas_inmutables': "SELECT count(DISTINCT t.tgrelid) FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid WHERE p.proname = 'reject_mutation' AND t.tgparentid = 0",
 'tablas_soft': "SELECT count(*) FROM information_schema.columns c WHERE c.table_schema = 'public' AND c.column_name = 'deleted_at' AND NOT EXISTS (SELECT 1 FROM pg_class k WHERE k.relname = c.table_name AND k.relispartition)",
 'tablas_lock': "SELECT count(*) FROM information_schema.columns c WHERE c.table_schema = 'public' AND c.column_name = 'lock_version' AND NOT EXISTS (SELECT 1 FROM pg_class k WHERE k.relname = c.table_name AND k.relispartition)",
 'tablas_vigencia': "SELECT count(*) FROM information_schema.columns c WHERE c.table_schema = 'public' AND c.column_name = 'valid_from' AND NOT EXISTS (SELECT 1 FROM pg_class k WHERE k.relname = c.table_name AND k.relispartition)",
 'comentarios_tabla': "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition AND obj_description(c.oid, 'pg_class') IS NOT NULL",
 'permisos': "SELECT count(*) FROM permissions",
 'permisos_campo': "SELECT count(*) FROM permissions WHERE kind = 'field'",
 'permisos_sensibles': "SELECT count(*) FROM permissions WHERE is_sensitive",
 'roles_sistema': "SELECT count(*) FROM system_roles",
 'modulos': "SELECT count(*) FROM modules",
 'version': "SHOW server_version",
}

DOMAIN = {0: 'Cimientos', 1: 'Core HR', 2: 'Tiempo', 3: 'Nómina Perú', 4: 'Talento', 5: 'Desarrollo y compensación',
          6: 'Experiencia', 7: 'Operación y cumplimiento', 8: 'Inteligencia', 9: 'Ecosistema y expansión'}
HUB = {'employments', 'people', 'legal_entities', 'positions', 'job_profiles', 'users', 'tenants', 'documents', 'payroll_periods', 'payroll_concepts'}

def mermaid(phase, tables, by):
    mains = [t for t in tables if t.phase == phase and 'main' in t.flags]
    names = {t.name for t in mains}
    edges, extra = [], set()
    for t in mains:
        seen = set()
        for c in t.cols:
            if not c.fk: continue
            tt = c.fk[1]
            if tt == t.name or tt in seen: continue
            if tt in names or (tt in HUB and 'main' in by[tt].flags):
                seen.add(tt)
                if tt not in names: extra.add(tt)
                card = '||--o{' if c.notnull else '|o--o{'
                edges.append(f'  {tt} {card} {t.name} : "{c.name}"')
        if not t.platform and phase == 0 and t.name in ('users', 'roles', 'audit_events', 'tenant_modules'):
            edges.append(f'  tenants ||--o{{ {t.name} : "tenant_id"')
    out = ['```mermaid', 'erDiagram']
    out += edges
    linked = {e.split()[0] for e in edges} | {e.split()[2] for e in edges}
    for t in mains:
        if t.name not in linked: out.append(f'  {t.name}')
    out.append('```')
    return '\n'.join(out), sorted(extra)

def table_doc(t):
    L = [f'#### `{t.name}`', '', f'- **Propósito:** {t.comment}']
    cols = []
    for c in t.cols:
        if c.fk: continue
        cols.append(f'`{c.name}`' + (' (S)' if c.sens else ''))
    if t.vig or t.vigcols: cols += ['`valid_from`', '`valid_to`']
    L.append('- **Columnas clave:** ' + (', '.join(cols) if cols else 'solo claves foráneas') + '.')
    rel = []
    for c in t.cols:
        if not c.fk: continue
        kind, tt, tc = c.fk
        opt = '' if c.notnull else ' (opcional)'
        tgt = f'`{tt}`' if kind != '~>' else f'`{tt}.{tc}`'
        rel.append(f'`{c.name}` → {tgt}{opt}')
    names = {c.name for c in t.cols}
    poly = [p for p in ('subject', 'entity', 'source', 'aggregate') if f'{p}_type' in names and f'{p}_id' in names]
    for p in poly: rel.append(f'`{p}_type` + `{p}_id`: referencia polimórfica sin clave foránea (la valida la aplicación)')
    L.append('- **Relaciones:** ' + ('; '.join(rel) if rel else 'ninguna además de la organización') + '.')
    R = []
    if t.platform:
        R.append('tabla de plataforma: sin `tenant_id` ni RLS' + ('; la aplicación solo la lee' if 'cat' in t.flags else '; la aplicación no tiene acceso'))
    else:
        R.append('tabla de organización: `tenant_id`, RLS forzado y claves foráneas compuestas')
    if t.vig:
        keys, where = t.vig
        R.append('**vigencia** sin solapamiento por ' + ', '.join(f'`{k}`' for k in keys) + (f' (solo filas con {where})' if where else ''))
    elif t.vigcols:
        R.append('**vigencia** (`valid_from`/`valid_to`)' + ('; el no solapamiento se define en una restricción propia' if any(r.startswith('EXCLUDE') for r in t.raws) else '; se admiten filas simultáneas con distinto alcance'))
    for r in t.raws:
        if r.startswith('EXCLUDE') and 'position_id' in r: R.append('una posición no puede tener dos ocupantes en el mismo periodo')
        if r.startswith('EXCLUDE') and 'asset_id' in r: R.append('un activo no puede tener dos asignaciones en el mismo periodo')
    if 'part' in t.flags: R.append('**particionada por mes** sobre `occurred_at` (A-06): clave primaria (`id`, `occurred_at`), particiones creadas con `ensure_monthly_partitions()` y retención por descarte de particiones')
    if 'imm' in t.flags: R.append('**inalterable**: solo inserción; UPDATE, DELETE y TRUNCATE fallan (AY001)')
    if 'ao' in t.flags: R.append('registro de solo anexado por convención (sin `updated_at`); se depura por retención')
    if 'nots' in t.flags: R.append('sin `created_at` ni `updated_at`, para no permitir correlación por hora')
    if 'soft' in t.flags: R.append('borrado lógico (`deleted_at`, papelera)')
    if 'lock' in t.flags: R.append('concurrencia optimista (`lock_version`)')
    if 'sens' in t.flags: R.append('**sensible**: permiso por campo y acceso registrado')
    elif any(c.sens for c in t.cols): R.append('contiene columnas sensibles (S)')
    if any(c.name.endswith('_enc') for c in t.cols): R.append('columnas `_enc` cifradas en la aplicación')
    for u in t.uniqs: R.append('único: ' + ', '.join(f'`{c}`' for c in u))
    for ch in t.checks: R.append(f'`CHECK ({ch})`')
    extra = SPECIAL.get(t.name)
    if extra: R.append(extra)
    L.append('- **Reglas:** ' + '; '.join(R) + '.')
    return '\n'.join(L)

SPECIAL = {
 'payslips': 'cerrada (`closed_at`), no admite UPDATE ni DELETE (AY002); la rectificación es otra boleta',
 'payroll_runs': 'cerrada (`closed_at`), no admite UPDATE ni DELETE (AY002)',
 'payroll_run_lines': 'no se insertan, modifican ni borran si la corrida está cerrada (AY002)',
 'payroll_run_employments': 'no se insertan, modifican ni borran si la corrida está cerrada (AY002)',
 'complaint_identities': 'un trigger impide insertar si la denuncia es anónima (AY003)',
 'complaints': 'una denuncia anónima no puede volverse identificada (AY003)',
 'survey_response_identities': 'un trigger impide insertar si la encuesta es anónima (AY003)',
 'surveys': 'con respuestas, no puede cambiar `is_anonymous` (AY003)',
 'attendance_punches': 'las correcciones son filas nuevas (`entry_type`, `corrects_punch_id`, `correction_reason`)',
 'tenants': 'la aplicación solo ve su fila por la vista `current_tenant`',
}

def main():
    phases, epics, tables = G.parse([os.path.join(S, f'model{i}.dsl') for i in (1, 2, 3)])
    by = G.validate(tables)
    n = {k: one(v) for k, v in Q.items()}
    n['fks_resto'] = str(int(n['fks']) - int(n['fks_compuestas']) - int(n['fks_a_tenants']))
    assert int(n['tablas']) == len(tables), (n['tablas'], len(tables))
    assert int(n['comentarios_tabla']) == len(tables)
    sens_cols = sum(1 for t in tables for c in t.cols if c.sens)
    enc_cols = sum(1 for t in tables for c in t.cols if c.name.endswith('_enc'))
    imm = sorted(t.name for t in tables if 'imm' in t.flags)
    vig = [t for t in tables if t.vig or t.vigcols or any(c.name == 'valid_from' for c in t.cols)]
    sens_t = sorted(t.name for t in tables if 'sens' in t.flags)
    head = open(os.path.join(S, 'doc_head.md'), encoding='utf-8').read()
    for k, v in n.items(): head = head.replace('{{' + k + '}}', v)
    head = head.replace('{{sens_cols}}', str(sens_cols)).replace('{{enc_cols}}', str(enc_cols))
    head = head.replace('{{lista_inmutables}}', ', '.join(f'`{x}`' for x in imm))
    head = head.replace('{{lista_sensibles}}', ', '.join(f'`{x}`' for x in sens_t))
    vl = []
    for t in vig:
        if t.vig: vl.append(f'| `{t.name}` | {", ".join("`" + k + "`" for k in t.vig[0])} | Sí' + (f' (filas con {t.vig[1]})' if t.vig[1] else '') + ' |')
        else: vl.append(f'| `{t.name}` | — | ' + ('Sí, restricción propia' if any(r.startswith('EXCLUDE') for r in t.raws) else 'No: conviven filas de distinto alcance') + ' |')
    head = head.replace('{{tabla_vigencias}}', '\n'.join(vl))
    rows = []
    for e in epics:
        ts = [t for t in tables if t.epic == e['code']]
        rows.append(f'| {e["phase"]} | {e["code"]} | {e["title"]} | {len(ts)} |')
    head = head.replace('{{tabla_epicas}}', '\n'.join(rows))
    out = [head]
    sec = 6
    for ph in phases:
        out.append(f'\n## {sec}. Fase {ph["n"]} · {ph["title"]}\n')
        mm, extra = mermaid(ph['n'], tables, by)
        out.append('Diagrama del dominio (solo tablas principales' + (f'; {", ".join("`" + x + "`" for x in extra)} de otro dominio, como referencia' if extra else '') + '):\n')
        out.append(mm)
        for e in [e for e in epics if e['phase'] == ph['n']]:
            out.append(f'\n### {e["code"]} · {e["title"]}\n')
            if e['note']: out.append(e['note'] + '\n')
            for t in [t for t in tables if t.epic == e['code']]:
                out.append(table_doc(t) + '\n')
        sec += 1
    tail = open(os.path.join(S, 'doc_tail.md'), encoding='utf-8').read().replace('{{n}}', str(sec))
    out.append(tail)
    os.makedirs(G.KIT + '/docs', exist_ok=True)
    with open(G.KIT + '/docs/04-modelo-de-datos.md', 'w', encoding='utf-8') as f:
        f.write('\n'.join(out).rstrip() + '\n')
    print(json.dumps(n, ensure_ascii=False))
    print('sens_cols', sens_cols, 'enc', enc_cols, 'vig', len(vig), 'imm', len(imm))

main()
