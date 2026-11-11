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
];
