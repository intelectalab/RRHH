# -*- coding: utf-8 -*-
"""Conciliación entre las máquinas de estado (docs/05-maquinas-de-estado.md) y los CHECK de schema.sql.

MAPA: por máquina, el valor de base de datos de cada estado funcional, en el mismo orden en que el
documento 05 lista los estados. «-» = estado derivado (no se guarda); la explicación va en DERIVADOS.

Uso:  python3 estados.py        reescribe en el 05 la sección final y la línea «Valores en schema.sql» de cada máquina.
Falla si un valor del mapa no está en el CHECK de la tabla (hay que añadirlo al .dsl y regenerar el esquema).
"""
import os, re, sys
import gen as G

MAPA = {
 'ME-01': 'pending active suspended terminating terminated cancelled',
 'ME-02': 'invited active locked alumni disabled',
 'ME-03': 'proposed approved open recruiting filled frozen closed',
 'ME-04': 'draft submitted approved rejected cancelled',
 'ME-05': 'draft pending_approval approved open on_hold filled cancelled',
 'ME-06': 'new active offer hired rejected withdrawn',
 'ME-07': 'draft in_review pending_approval approved sent accepted declined expired withdrawn',
 'ME-08': 'preboarding in_progress paused completed cancelled',
 'ME-09': 'draft in_progress pending_settlement executed cancelled',
 'ME-10': 'draft sent viewed in_progress completed declined expired cancelled',
 'ME-11': 'draft pending_approval pending_signature signed active - expired terminated cancelled',
 'ME-12': 'requested pending_approval active rejected - expired archived',
 'ME-13': '- - - - -',
 'ME-14': 'pending approved rejected cancelled',
 'ME-15': 'draft pending hr_review approved in_progress taken rejected cancelled',
 'ME-16': 'pending pending_document approved in_progress completed rejected cancelled',
 'ME-17': 'pending approved pending_review reviewed in_payroll banked rejected',
 'ME-18': 'open submitted approved rejected locked',
 'ME-19': 'open calculated in_review approved closed paid accounted',
 'ME-20': 'closed - - - -',
 'ME-21': 'requested approved disbursed active paid refinanced rejected cancelled',
 'ME-22': 'requested approved paid deducted rejected',
 'ME-23': 'draft calculated pending_approval approved paid documents_delivered voided',
 'ME-24': 'draft validated with_errors submitted accepted observed rectified',
 'ME-25': 'draft in_progress calibration results_published closed cancelled',
 'ME-26': 'pending in_progress submitted calibrated published acknowledged cancelled',
 'ME-27': 'draft pending_approval active at_risk closed cancelled',
 'ME-28': 'not_started in_progress completed overdue cancelled',
 'ME-29': 'draft open hr_review finance_review executive_review approved applied cancelled',
 'ME-30': 'draft scheduled open closed results_published archived',
 'ME-31': 'new in_progress waiting_info resolved closed',
 'ME-32': 'draft submitted manager_approved observed finance_approved paid rejected',
 'ME-33': 'requested approved paid pending_settlement settled overdue closed rejected',
 'ME-34': 'available assigned maintenance lost retired',
 'ME-35': 'reported classified under_investigation actions_in_progress closed dismissed',
 'ME-36': 'open in_progress done verified overdue cancelled',
 'ME-37': 'received admitted under_investigation report_issued resolved closed dismissed',
 'ME-38': 'open charges_notified awaiting_defense under_review decided sanctioned closed dismissed',
 'ME-39': 'received verifying_identity in_progress answered rejected closed',
 'ME-40': 'trial active grace_period suspended cancelled deleted',
 'ME-41': 'uploaded mapped validated with_errors importing completed failed reverted cancelled',
 'ME-42': 'draft active inactive archived',
 'ME-43': 'pending running approved rejected cancelled escalated',
 'ME-44': 'queued running waiting succeeded failed cancelled',
 'ME-45': 'new assigned in_preparation answered overdue archived',
 'ME-46': 'planned notified called board_formed nominations candidates_published voting counted proclaimed installed annulled',
 'ME-47': 'scheduled called held no_quorum minutes_drafted minutes_signed cancelled',
 'ME-48': 'received referred_to_committee investigating report_issued decided closed',
 'ME-49': 'pending refunded compensated authority_requested closed',
}
DERIVADOS = {
 'ME-11': '«Por vencer» se deriva de `valid_to` y del parámetro de aviso; no se guarda.',
 'ME-12': '«Por vencer» se deriva de `expires_on`; no se guarda.',
 'ME-13': 'La marcación es inalterable y no lleva columna de estado. «Con incidencia» = existe fila en `attendance_incidents`; «Validada» = `attendance_days.status`; «Reemplazada por corrección» = existe otra marcación con `corrects_punch_id` hacia ella; «Duplicada» = rechazada al sincronizar por `client_id` repetido (queda en `attendance_sync_batches.rejected_count`).',
 'ME-20': 'La boleta cerrada es inalterable: «Generada» = `payslips.status = closed` (`draft` es previo a la emisión). «Publicada», «Vista» y «Con acuse» se guardan en `payslip_deliveries.status` (`published`, `viewed`, `acknowledged`). «Anulada» = existe una boleta de rectificación con `rectifies_payslip_id` hacia ella.',
}
COLUMNA = {'ME-48': 'procedure_stage'}   # máquina (o tabla) -> columna de estado si no es «status»

def enum_values(t, col='status'):
    for c in t.cols:
        if c.name == col:
            m = re.search(r"IN \(([^)]*)\)", c.sql)
            return [v.strip("'") for v in m.group(1).split(',')] if m else None
    return None

def main():
    here = os.path.dirname(os.path.abspath(__file__))
    phases, epics, tables = G.parse([os.path.join(here, f'model{i}.dsl') for i in (1, 2, 3)])
    by = {t.name: t for t in tables}
    path = os.path.join(G.KIT, 'docs', '05-maquinas-de-estado.md')
    text = open(path, encoding='utf-8').read()
    a = text.index('## Conciliación con `schema.sql`')
    body, old = text[:a], text[a:]
    rows, errors = [], []
    for line in old.splitlines():
        m = re.match(r'^\| (ME-\d+) (.*?) \| `([a-z_]+)` \| (.*?) \|', line)
        if not m: continue
        mid, name, table, states = m.groups()
        states = [s.split(' → ')[0].strip() for s in states.split(', ')]
        vals = MAPA[mid].split()
        if len(vals) != len(states): errors.append(f'{mid}: {len(states)} estados y {len(vals)} valores'); continue
        enum = enum_values(by[table], COLUMNA.get(mid, COLUMNA.get(table, 'status')))
        for v in vals:
            if v != '-' and (enum is None or v not in enum): errors.append(f'{mid} {table}: falta «{v}» en el CHECK')
        used = {v for v in vals if v != '-'}
        extra = [v for v in (enum or []) if v not in used]
        pairs = ', '.join(f'{s} → `{v}`' if v != '-' else f'{s} → derivado' for s, v in zip(states, vals))
        note = DERIVADOS.get(mid, '')
        other = (', '.join(f'`{v}`' for v in extra) if extra else '—')
        rows.append((mid, name, table, pairs, other, note, enum))
    if errors: sys.exit('\n'.join(errors))
    assert len(rows) == len(MAPA), (len(rows), len(MAPA))
    out = ['## Conciliación con `schema.sql`', '',
           'Conciliado: cada estado funcional tiene su valor en el `CHECK` de la columna `status` de `schema.sql`, o está documentado como derivado. **No quedan diferencias.** Los valores de base van en inglés y `snake_case`; los nombres en español son solo de interfaz (archivos de traducción).', '',
           'La columna «Otros valores del esquema» lista valores que el `CHECK` admite y la máquina no nombra: son estados técnicos o de detalle (por ejemplo `superseded` en contratos o `failed` en una ejecución) que la interfaz agrupa bajo el estado funcional más cercano. Esta sección la escribe `tools/esquema/estados.py`; si cambian las máquinas o el esquema, se actualiza el mapa de ese archivo y se vuelve a ejecutar.', '',
           '| Máquina | Tabla | Estado funcional → valor en base de datos | Otros valores del esquema |', '|---|---|---|---|']
    for mid, name, table, pairs, other, note, enum in rows:
        out.append(f'| {mid} {name} | `{table}` | {pairs} | {other} |')
    out += ['', 'Estados derivados (no se guardan):', '']
    for mid, name, table, pairs, other, note, enum in rows:
        if note: out.append(f'- **{mid} {name}.** {note}')
    # línea «Valores en schema.sql» de cada máquina
    for mid, name, table, pairs, other, note, enum in rows:
        if not enum or mid in COLUMNA: continue
        pat = re.compile(r'(## ' + re.escape(mid) + r' · .*?\n)(Valores en `schema\.sql` \(`' + table + r'`\): `status` ∈ \{)[^}]*(\})', re.S)
        body, n = pat.subn(lambda m: m.group(1) + m.group(2) + ', '.join(enum) + m.group(3), body, count=1)
    open(path, 'w', encoding='utf-8').write(body + '\n'.join(out) + '\n')
    print('05 conciliado:', len(rows), 'máquinas')

if __name__ == '__main__':
    main()
