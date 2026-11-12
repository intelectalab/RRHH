<?php

/**
 * SAS-6 · Plan limits and usage. Every limit message explains how to raise it.
 */
return [
    'metrics' => [
        'employees' => 'Active employees',
        'admins' => 'Administrators',
        'legal_entities' => 'Active companies',
        'storage_mb' => 'Storage (MB)',
        'api_calls' => 'API calls this month',
    ],
    'reached' => [
        'employees' => 'Your plan allows up to :limit active employees and all are in use. To hire more people, upgrade the plan or add an employee add-on (Settings → Plan usage), or terminate people who no longer work here.',
        'admins' => 'Your plan allows up to :limit administrators. To give this role to someone else, remove it from another person or upgrade the plan from Settings → Plan usage.',
        'legal_entities' => 'Your plan allows up to :limit active companies. To add another, archive one you no longer use or upgrade the plan from Settings → Plan usage.',
        'storage_mb' => 'Your plan storage (:limit MB) is not enough for this file. You can still view and download what is stored; to upload more, delete files you do not need or upgrade the plan from Settings → Plan usage.',
        'default' => 'You reached your plan limit (:limit). Upgrade the plan or add an add-on from Settings → Plan usage.',
    ],
];
