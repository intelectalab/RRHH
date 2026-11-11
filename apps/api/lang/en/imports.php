<?php

return [
    'entities' => [
        'legal_entities' => 'Companies',
        'employees' => 'Employees',
        'contracts' => 'Contracts',
        'vacation_balances' => 'Vacation balances',
        'salaries' => 'Salaries',
        'attendance' => 'Attendance',
        'concepts' => 'Pay items',
        'history' => 'History',
        'org_structure' => 'Structure',
        'dependents' => 'Dependents',
        'positions' => 'Positions',
    ],
    'columns' => [
        'legal_entities' => [
            'name' => 'Legal name',
            'tax_id' => 'Tax ID',
            'trade_name' => 'Trade name',
            'payroll_regime' => 'Labor regime',
            'country' => 'Country',
            'currency' => 'Currency',
            'timezone' => 'Time zone',
        ],
    ],
    'file' => [
        'empty' => 'The file is empty or could not be read.',
        'no_headers' => 'The first row of the file must have the column names.',
        'duplicate_headers' => 'Several columns in the first row have the same name.',
        'no_rows' => 'The file has no data rows below the column names.',
        'too_many_rows' => 'The file exceeds the :max rows allowed; split it into parts.',
        'encoding' => 'The file encoding is not recognized; save it as CSV UTF-8.',
        'unreadable' => 'The file could not be read. Use CSV or Excel (.xlsx).',
    ],
    'mapping' => [
        'invalid' => 'The mapping must give, for each column, the file header.',
        'unknown_column' => 'Column “:column” does not exist for this kind of data.',
        'unknown_header' => 'Header “:header” is not in the file.',
        'header_twice' => 'Header “:header” is assigned to two columns.',
        'missing_required' => 'Required column “:column” is not assigned.',
    ],
    'errors' => [
        'required' => '“:column” is required.',
        'duplicate_in_file' => 'Repeats the data of row :row in the same file.',
    ],
    'warnings' => [
        'duplicate_file' => 'This file was already uploaded for the same kind of data. Make sure you are not importing it twice.',
        'sensitive_ignored' => 'Column “:column” is ignored: you are not allowed to import that data.',
    ],
    'csv' => [
        'row' => 'Row',
        'reason' => 'Reason',
    ],
    'entity_invalid' => 'That kind of data cannot be imported.',
    'confirm_required' => 'Review the preview and confirm to import.',
    'nothing_to_import' => 'There are no valid rows to import.',
    'revert_blocked' => 'It cannot be undone: :n records of the batch are already used by other processes.',
    'no_errors' => 'This import has no rows with errors.',
    'failed_unexpected' => 'An unexpected error occurred while importing; no records were saved.',
];
