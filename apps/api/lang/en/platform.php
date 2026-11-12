<?php

return [
    'invalid_credentials' => 'The email or password is incorrect, or the account is temporarily locked.',
    'invalid_code' => 'The code is not valid or has already been used.',
    'clock_skew' => 'The code belongs to another time: your phone or the server clock is about :minutes minutes off.',
    'mfa_already_enabled' => 'Two-step verification is already set up. To change it, ask for it to be reset.',
    'mfa_enrollment_not_started' => 'Start the authenticator app setup first.',
    'mfa_not_enabled' => 'Two-step verification is not set up yet.',
    'roles' => [
        'superadmin' => 'Owner',
        'support' => 'Support',
        'billing' => 'Finance',
        'readonly' => 'Operations (read only)',
    ],
    'tenants' => [
        'slug_taken' => 'That subdomain is already in use or reserved.',
        'reason_required' => 'Enter the reason: it is kept in the history and in the notice to the organization.',
        'retention_expired' => 'The organization\'s retention period has ended: it can no longer be reactivated.',
        'unknown_country' => 'The country is not in the catalog.',
        'unknown_currency' => 'The currency is not in the catalog.',
        'unknown_timezone' => 'The time zone is not valid (for example, America/Lima).',
        'unknown_regime' => 'The labor regime does not belong to the country.',
    ],
    'modules' => [
        'core' => 'Base modules cannot be withdrawn.',
        'required_by' => 'Other active modules depend on this one: :modules. Withdraw them first.',
    ],
];
