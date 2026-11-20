<?php

/**
 * IMP-7 · Perfiles de origen para importar exportaciones de otros sistemas sin reordenar columnas.
 *
 * Cada perfil traduce, por tipo de datos, las cabeceras del archivo de origen a las columnas de los
 * importadores de Core HR (IMP-2) y, si hace falta, los códigos del origen a los de GestiaHR. Son
 * estructuras genéricas de referencia, no el formato exacto de un proveedor: todas van «por validar»
 * contra archivos reales. La organización corrige o agrega perfiles como configuración (una versión
 * nueva por cambio), sin desplegar código.
 *
 * Forma: codigo, nombre, descripcion, version, por_validar y entidades → {entity: {columnas:
 * {clave: [cabeceras posibles]}, valores: {clave: {valor_origen: valor_gestia}}, por_defecto: {clave: valor}}}.
 */
return [
    [
        'codigo' => 'pe_t_registro_hoja',
        'nombre' => 'Hoja con estructura de T-Registro',
        'descripcion' => 'Hoja de cálculo armada con los datos del trabajador que se declaran en T-Registro: documento con código de tabla, apellidos, nombres, fechas y régimen.',
        'version' => 1,
        'por_validar' => true,
        'entidades' => [
            'employees' => [
                'columnas' => [
                    'document_type' => ['Tipo de documento', 'Tipo Doc', 'Tip. Doc. Identidad'],
                    'document_number' => ['Número de documento', 'Nro. Documento', 'Num. Doc. Identidad'],
                    'paternal_surname' => ['Apellido paterno', 'Ap. Paterno'],
                    'maternal_surname' => ['Apellido materno', 'Ap. Materno'],
                    'first_names' => ['Nombres'],
                    'birth_date' => ['Fecha de nacimiento', 'Fec. Nacimiento'],
                    'sex' => ['Sexo'],
                    'legal_entity' => ['RUC del empleador', 'RUC'],
                    'hire_date' => ['Fecha de inicio', 'Fecha de ingreso', 'Fec. Inicio'],
                    'contract_type' => ['Tipo de contrato'],
                    'contract_end_date' => ['Fecha de fin', 'Fec. Fin'],
                    'labor_regime' => ['Régimen laboral'],
                    'base_salary' => ['Remuneración', 'Remuneración básica'],
                    'org_unit_code' => ['Área', 'Código de área'],
                    'job_profile_code' => ['Ocupación', 'Cargo'],
                ],
                'valores' => [
                    'document_type' => ['01' => 'dni', '1' => 'dni', '04' => 'ce', '4' => 'ce', '07' => 'passport', '7' => 'passport'],
                    'sex' => ['1' => 'M', '2' => 'F'],
                    'contract_type' => ['01' => 'indeterminado', '1' => 'indeterminado'],
                ],
                'por_defecto' => ['currency' => 'PEN'],
            ],
        ],
    ],
    [
        'codigo' => 'pe_planilla_tradicional',
        'nombre' => 'Sistema de planilla tradicional',
        'descripcion' => 'Exportación típica de un sistema de planillas de escritorio: códigos en mayúsculas, apellidos y nombres en columnas separadas y sueldo básico.',
        'version' => 1,
        'por_validar' => true,
        'entidades' => [
            'employees' => [
                'columnas' => [
                    'employee_code' => ['COD_TRAB', 'CODIGO', 'COD_PERSONAL'],
                    'document_type' => ['TIPO_DOC', 'TIP_DOC'],
                    'document_number' => ['NRO_DOC', 'NUM_DOC', 'DNI'],
                    'paternal_surname' => ['AP_PATERNO', 'APE_PAT'],
                    'maternal_surname' => ['AP_MATERNO', 'APE_MAT'],
                    'first_names' => ['NOMBRES', 'NOMBRE'],
                    'birth_date' => ['FEC_NAC', 'FECHA_NAC'],
                    'sex' => ['SEXO'],
                    'legal_entity' => ['RUC_EMPRESA', 'RUC'],
                    'hire_date' => ['FEC_ING', 'FECHA_INGRESO'],
                    'contract_type' => ['TIPO_CONTRATO', 'TIP_CONT'],
                    'contract_end_date' => ['FEC_FIN_CONT', 'FEC_CESE_PREV'],
                    'org_unit_code' => ['COD_AREA', 'AREA'],
                    'job_profile_code' => ['COD_CARGO', 'CARGO'],
                    'cost_center_code' => ['CENTRO_COSTO', 'COD_CCOSTO'],
                    'site' => ['SEDE', 'LOCAL', 'COD_LOCAL'],
                    'base_salary' => ['SUELDO_BASICO', 'BASICO', 'REMUNERACION'],
                    'currency' => ['MONEDA'],
                ],
                'valores' => [
                    'document_type' => ['DNI' => 'dni', 'L.E.' => 'dni', 'CE' => 'ce', 'C.E.' => 'ce', 'PAS' => 'passport'],
                    'currency' => ['S/' => 'PEN', 'S/.' => 'PEN', 'SOLES' => 'PEN', 'US$' => 'USD', 'DOLARES' => 'USD'],
                ],
                'por_defecto' => [],
            ],
            'org_structure' => [
                'columnas' => [
                    'code' => ['COD_AREA', 'CODIGO'],
                    'name' => ['DESC_AREA', 'DESCRIPCION', 'AREA'],
                    'type' => ['TIPO', 'NIVEL'],
                    'parent_code' => ['COD_AREA_SUP', 'AREA_SUPERIOR'],
                    'cost_center_code' => ['CENTRO_COSTO'],
                ],
                'valores' => [],
                'por_defecto' => ['type' => 'Área'],
            ],
            'salaries' => [
                'columnas' => [
                    'document_number' => ['NRO_DOC', 'DNI'],
                    'legal_entity' => ['RUC_EMPRESA', 'RUC'],
                    'valid_from' => ['FEC_VIGENCIA', 'FECHA'],
                    'base_salary' => ['SUELDO_BASICO', 'BASICO'],
                    'currency' => ['MONEDA'],
                ],
                'valores' => [
                    'currency' => ['S/' => 'PEN', 'S/.' => 'PEN', 'SOLES' => 'PEN', 'US$' => 'USD', 'DOLARES' => 'USD'],
                ],
                'por_defecto' => [],
            ],
        ],
    ],
    [
        'codigo' => 'suite_rrhh_generica',
        'nombre' => 'Suite de RRHH (exportación en inglés)',
        'descripcion' => 'Exportación de personal de una suite de RRHH en la nube con cabeceras en inglés.',
        'version' => 1,
        'por_validar' => true,
        'entidades' => [
            'employees' => [
                'columnas' => [
                    'employee_code' => ['Employee ID', 'Employee Number'],
                    'document_type' => ['National ID Type', 'ID Type'],
                    'document_number' => ['National ID', 'ID Number'],
                    'first_names' => ['First Name', 'Given Name'],
                    'paternal_surname' => ['Last Name', 'Family Name'],
                    'maternal_surname' => ['Second Last Name', 'Mother Last Name'],
                    'birth_date' => ['Date of Birth', 'Birth Date'],
                    'sex' => ['Gender', 'Sex'],
                    'personal_email' => ['Personal Email'],
                    'work_email' => ['Work Email', 'Business Email'],
                    'mobile_phone' => ['Mobile Phone', 'Mobile'],
                    'legal_entity' => ['Legal Entity Tax ID', 'Company Tax ID'],
                    'hire_date' => ['Hire Date', 'Start Date'],
                    'org_unit_code' => ['Department Code', 'Department'],
                    'job_profile_code' => ['Job Code', 'Job Title'],
                    'site' => ['Location'],
                    'manager_document' => ['Manager National ID'],
                    'contract_type' => ['Contract Type', 'Employment Type'],
                    'contract_end_date' => ['Contract End Date'],
                    'base_salary' => ['Base Salary', 'Monthly Base Salary'],
                    'currency' => ['Currency'],
                ],
                'valores' => [
                    'document_type' => ['DNI' => 'dni', 'Foreigner ID' => 'ce', 'Passport' => 'passport'],
                    'sex' => ['Male' => 'M', 'Female' => 'F'],
                    'contract_type' => ['Permanent' => 'indeterminado', 'Part Time' => 'tiempo_parcial'],
                ],
                'por_defecto' => [],
            ],
            'org_structure' => [
                'columnas' => [
                    'code' => ['Department Code'],
                    'name' => ['Department Name', 'Department'],
                    'type' => ['Department Type'],
                    'parent_code' => ['Parent Department Code'],
                ],
                'valores' => [],
                'por_defecto' => ['type' => 'Área'],
            ],
            'job_profiles' => [
                'columnas' => [
                    'code' => ['Job Code'],
                    'name' => ['Job Title'],
                    'family' => ['Job Family'],
                    'level' => ['Job Level', 'Grade'],
                ],
                'valores' => [],
                'por_defecto' => [],
            ],
        ],
    ],
];
