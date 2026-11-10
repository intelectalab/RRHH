<?php

return [
    'password_reset' => [
        'subject' => 'Recover your Ayni HR password',
        'body' => "We received a request to reset your password.\n\nUse this link (valid for :minutes minutes, one time only):\n:link\n\nIf you did not request it, ignore this message.",
        'requested' => 'If the email belongs to an active account, we will send a link to reset the password.',
    ],
    'roles' => [
        'copy_of' => 'Copy of :name',
        'system_immutable' => 'System roles cannot be edited or deleted; duplicate them to adjust.',
        'unknown_permission' => 'The permission :code does not exist in the catalog or is not of this kind.',
        'cannot_grant' => 'You cannot grant permissions you do not have: :codes.',
    ],
];
