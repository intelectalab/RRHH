<?php

return [
    'password_reset' => [
        'subject' => 'Recover your Ayni HR password',
        'body' => "We received a request to reset your password.\n\nUse this link (valid for :minutes minutes, one time only):\n:link\n\nIf you did not request it, ignore this message.",
        'requested' => 'If the email belongs to an active account, we will send a link to reset the password.',
    ],
];
