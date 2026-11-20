<?php

return [
    'password_reset' => [
        'subject' => 'Recover your GestiaHR password',
        'body' => "We received a request to reset your password.\n\nUse this link (valid for :minutes minutes, one time only):\n:link\n\nIf you did not request it, ignore this message.",
        'requested' => 'If the email belongs to an active account, we will send a link to reset the password.',
    ],
    'roles' => [
        'copy_of' => 'Copy of :name',
        'system_immutable' => 'System roles cannot be edited or deleted; duplicate them to adjust.',
        'unknown_permission' => 'The permission :code does not exist in the catalog or is not of this kind.',
        'cannot_grant' => 'You cannot grant permissions you do not have: :codes.',
        'name_taken' => 'An active role already has that name; rename it before restoring this one.',
    ],
    'users' => [
        'scope_units_required' => 'Specify at least one company, site or unit for that scope.',
        'unknown_scope_unit' => 'One of the companies, sites or units does not exist in this organization.',
        'invite_without_email' => 'The user has no email: give them an activation code from HR.',
        'reset_without_email' => 'The user has no email to receive the link.',
        'unknown_role' => 'One or more roles do not exist in this organization.',
        'last_admin' => 'The organization cannot be left without an active Company administrator.',
    ],
    'invitation' => [
        'subject' => 'Activate your GestiaHR account',
        'body' => "Your company created an GestiaHR account for you.\n\nActivate it and set your password with this link (valid for :hours hours):\n:link",
    ],
    'password' => [
        'too_short' => 'The password must have at least :min characters.',
        'letters_numbers' => 'The password must combine letters and numbers.',
        'reused' => 'You cannot reuse any of your last :n passwords.',
        'current_wrong' => 'The current password is not correct.',
        'locked_event' => 'Your account was locked after several failed sign-in attempts. You can try again in :minutes minutes or reset your password.',
        'changed_event' => 'Your account password was changed.',
    ],
    'mfa' => [
        'authenticator_label' => 'Authenticator app',
        'clock_skew' => 'The code belongs to a different time: your phone or the server clock is about :minutes minutes off. Turn on automatic time on your phone and check the server clock.',
        'invalid_code' => 'The code is not valid or was already used.',
        'mfa_already_enabled' => 'Two-step verification is already on.',
        'mfa_enrollment_not_started' => 'Start the authenticator app setup first.',
        'mfa_not_enabled' => 'Two-step verification is not on.',
        'mfa_required_by_policy' => 'Your role or organization requires two-step verification: it cannot be turned off.',
        'enabled_event' => 'Two-step verification was turned on for your account.',
        'disabled_event' => 'Two-step verification was turned off for your account.',
    ],
    'codes' => [
        'login_message' => 'GestiaHR: your sign-in code is :code. It expires in :minutes minutes. Do not share it.',
        'signature_message' => 'GestiaHR: your code to sign the document is :code. It expires in :minutes minutes. Do not share it.',
        'activation_message' => 'GestiaHR: your account activation code is :code. It expires in :hours hours. Sign in with your ID document and this code.',
        'email_subject' => 'Your GestiaHR code',
        'invalid' => 'The document or the code is not valid, or the code has expired.',
        'activation_not_pending' => 'The account is already active: it does not need an activation code.',
        'no_phone' => 'The user has no mobile number to send the code to.',
    ],
    'support' => [
        'link_invalid' => 'The support link is invalid, already used or expired.',
        'disabled' => 'The organization turned off support access.',
        'device_label' => 'GestiaHR support session',
        'grant_not_active' => 'The authorization is no longer active.',
    ],
    'quick_unlock' => [
        'disabled' => 'PIN sign-in is turned off in your organization.',
        'unavailable' => 'This phone no longer has PIN sign-in. Sign in with your password or a code.',
        'wrong_pin' => 'The PIN is not correct.',
        'weak_pin' => 'Use 4 to 8 digits, not a single repeated number or a sequence.',
    ],
];
