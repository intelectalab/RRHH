# CFG-10 · Catálogos maestros: datos iniciales de master_catalogs y master_catalog_items.
# Cada elemento tiene un código estable (code) para la aplicación e integraciones y, cuando existe,
# el código oficial (statutory_code). verified = False marca lo que falta contrastar con la fuente
# oficial («por validar»): la plataforma lo corrige con `php artisan catalogs:import` sin desplegar.
from paises_iso import COUNTRIES

FROM = '2000-01-01'  # vigencia abierta de la carga inicial

# (code, name, description, is_extensible, country_code, source)
CATALOGS = [
    ('document_types', 'Tipos de documento de identidad', 'Tabla 3 de la Planilla Electrónica; el código interno coincide con person_documents.doc_type.', False, 'PE', 'docs/18 · Tabla 3 (anexo RM 286-2023-TR)'),
    ('countries', 'Países', 'ISO 3166-1 alfa-2 con nombres en es, en y pt (CLDR). También sirve de lista de nacionalidades.', False, None, 'ISO 3166-1 · CLDR'),
    ('ubigeo_pe', 'Ubigeo del Perú', 'Departamentos (código de dos dígitos). Provincias y distritos se cargan con catalogs:import desde el archivo oficial del INEI (parent_code = departamento o provincia).', False, 'PE', 'INEI · por validar'),
    ('banks_pe', 'Bancos', 'Entidades bancarias para el abono de remuneraciones. El código oficial (tabla 36 / CCI) está por validar; la organización puede agregar cooperativas o cajas.', True, 'PE', 'SBS · por validar'),
    ('termination_reasons_pe', 'Motivos de fin del periodo', 'Tabla 17 de la Planilla Electrónica. Los motivos propios de cada organización (termination_reasons) se enlazan por statutory_code.', False, 'PE', 'docs/18 · Tabla 17 (ayuda SUNAT)'),
]

# catálogo -> [(code, statutory_code, {es, en, pt}, verified, parent_code)]
def _l(es, en, pt): return {'es': es, 'en': en, 'pt': pt}

ITEMS = {
    'document_types': [
        ('dni', '01', _l('Documento nacional de identidad', 'National identity document', 'Documento nacional de identidade'), True, None),
        ('ce', '04', _l('Carné de extranjería', 'Foreigner identity card', 'Carteira de estrangeiro'), True, None),
        ('ruc', '06', _l('Registro único de contribuyentes', 'Taxpayer registration number', 'Registro único de contribuintes'), True, None),
        ('passport', '07', _l('Pasaporte', 'Passport', 'Passaporte'), True, None),
        ('refugee_card', '09', _l('Carné de solicitante de refugio', 'Refugee applicant card', 'Carteira de solicitante de refúgio'), True, None),
        ('birth_certificate', '11', _l('Partida de nacimiento', 'Birth certificate', 'Certidão de nascimento'), True, None),
        ('foreign_affairs_id', '22', _l('Carné de identidad – Relaciones Exteriores', 'Foreign Affairs identity card', 'Carteira de identidade – Relações Exteriores'), True, None),
        ('ptp', '23', _l('Permiso temporal de permanencia', 'Temporary stay permit', 'Permissão temporária de permanência'), True, None),
        ('foreign_id', '24', _l('Documento de identidad extranjero', 'Foreign identity document', 'Documento de identidade estrangeiro'), True, None),
        ('cpp', '26', _l('Carné de permiso temporal de permanencia', 'Temporary stay permit card', 'Carteira de permissão temporária de permanência'), True, None),
    ],
    'countries': [(c, c, _l(es, en, pt), True, None) for c, es, en, pt in COUNTRIES],
    'ubigeo_pe': [(code, code, _l(n, n, n), False, None) for code, n in [
        ('01', 'Amazonas'), ('02', 'Áncash'), ('03', 'Apurímac'), ('04', 'Arequipa'), ('05', 'Ayacucho'), ('06', 'Cajamarca'),
        ('07', 'Callao'), ('08', 'Cusco'), ('09', 'Huancavelica'), ('10', 'Huánuco'), ('11', 'Ica'), ('12', 'Junín'),
        ('13', 'La Libertad'), ('14', 'Lambayeque'), ('15', 'Lima'), ('16', 'Loreto'), ('17', 'Madre de Dios'), ('18', 'Moquegua'),
        ('19', 'Pasco'), ('20', 'Piura'), ('21', 'Puno'), ('22', 'San Martín'), ('23', 'Tacna'), ('24', 'Tumbes'), ('25', 'Ucayali')]],
    'banks_pe': [(code, None, _l(n, n, n), False, None) for code, n in [
        ('bcp', 'Banco de Crédito del Perú'), ('bbva', 'BBVA Perú'), ('interbank', 'Interbank'), ('scotiabank', 'Scotiabank Perú'),
        ('banbif', 'BanBif'), ('pichincha', 'Banco Pichincha'), ('nacion', 'Banco de la Nación'), ('gnb', 'Banco GNB Perú'),
        ('falabella', 'Banco Falabella'), ('ripley', 'Banco Ripley'), ('mibanco', 'Mibanco'), ('santander', 'Banco Santander Perú'),
        ('citibank', 'Citibank del Perú'), ('alfin', 'Alfin Banco'), ('bank_of_china', 'Bank of China (Perú)'), ('icbc', 'ICBC Perú Bank')]],
    'termination_reasons_pe': [
        ('resignation', '01', _l('Renuncia', 'Resignation', 'Pedido de demissão'), True, None),
        ('resignation_incentives', '02', _l('Renuncia con incentivos', 'Resignation with incentives', 'Pedido de demissão com incentivos'), True, None),
        ('dismissal', '03', _l('Despido o destitución', 'Dismissal', 'Demissão pelo empregador'), True, None),
        ('collective_termination', '04', _l('Cese colectivo', 'Collective termination', 'Desligamento coletivo'), True, None),
        ('retirement', '05', _l('Jubilación', 'Retirement', 'Aposentadoria'), True, None),
        ('permanent_disability', '06', _l('Invalidez absoluta permanente', 'Permanent total disability', 'Invalidez absoluta permanente'), True, None),
        ('contract_end', '07', _l('Terminación de la obra o servicio, cumplimiento de condición resolutoria o vencimiento del plazo', 'End of work or service, resolutory condition or end of term', 'Término da obra ou serviço, condição resolutiva ou fim do prazo'), True, None),
        ('mutual_agreement', '08', _l('Mutuo disenso', 'Mutual agreement', 'Distrato'), True, None),
        ('death', '09', _l('Fallecimiento', 'Death', 'Falecimento'), True, None),
        ('employer_succession', '14', _l('Baja por sucesión en posición del empleador', 'Employer succession', 'Sucessão do empregador'), True, None),
        ('employer_extinction', '15', _l('Extinción o liquidación del empleador', 'Employer dissolution or liquidation', 'Extinção ou liquidação do empregador'), True, None),
        ('not_started', '17', _l('No se inició la relación laboral o la prestación efectiva de servicios', 'Employment or services never started', 'A relação de trabalho ou a prestação de serviços não se iniciou'), True, None),
        ('age_limit', '18', _l('Límite de edad 70 años', 'Age limit of 70', 'Limite de idade de 70 anos'), False, None),
        ('no_link_plame', '99', _l('Sin vínculo laboral, habilitado para PLAME', 'No employment link, enabled for PLAME', 'Sem vínculo de trabalho, habilitado para o PLAME'), False, None),
    ],
}


def seed_sql(q):
    import json
    o = ['INSERT INTO master_catalogs (code, name, description, is_extensible, country_code, source) VALUES']
    o.append(',\n'.join(f"  ({q(c)}, {q(n)}, {q(d)}, {str(ext).lower()}, {'NULL' if cc is None else q(cc)}, {q(src)})" for c, n, d, ext, cc, src in CATALOGS) + ';')
    for cat, items in ITEMS.items():
        o.append('INSERT INTO master_catalog_items (master_catalog_id, code, label, labels, statutory_code, parent_code, sort_order, is_verified, valid_from) VALUES')
        o.append(',\n'.join(
            f"  ((SELECT id FROM master_catalogs WHERE code = {q(cat)}), {q(code)}, {q(lab['es'])}, {q(json.dumps(lab, ensure_ascii=False))}::jsonb, {'NULL' if st is None else q(st)}, {'NULL' if par is None else q(par)}, {i}, {str(ver).lower()}, {q(FROM)})"
            for i, (code, st, lab, ver, par) in enumerate(items, 1)) + ';')
    return '\n'.join(o)


def as_json():
    return dict(
        nota='CFG-10 · Catálogos maestros de la plataforma. Generado por tools/esquema/gen.py desde tools/esquema/maestros.py. La API los carga y actualiza con «php artisan catalogs:import» (sin desplegar): inserta los códigos nuevos y actualiza etiquetas, código oficial, verificación y vigencia de los existentes; nunca borra (RN-CFG-09).',
        catalogos=[dict(code=c, name=n, description=d, is_extensible=ext, country_code=cc, source=src,
                        items=[dict(code=code, statutory_code=st, labels=lab, parent_code=par, sort_order=i, is_verified=ver, valid_from=FROM)
                               for i, (code, st, lab, ver, par) in enumerate(ITEMS[c], 1)])
                   for c, n, d, ext, cc, src in CATALOGS])
