<?php

return [
    'categories' => [
        'general' => 'General question',
        'payslips' => 'Payslips and payments',
        'documents' => 'Employee file documents',
        'benefits' => 'Benefits and insurance',
        'schedule' => 'Schedule and attendance',
        'other' => 'Other request',
    ],
    'category_descriptions' => [
        'general' => 'Questions about your employment or your data.',
        'payslips' => 'Payslips, deposits, deductions or amounts that do not add up.',
        'documents' => 'Documents you need or must hand in.',
        'benefits' => 'Insurance, health plan, company benefits.',
        'schedule' => 'Your schedule, clock-ins or shifts.',
        'other' => 'Any other request to Human Resources.',
    ],
    'statuses' => [
        'new' => 'New',
        'in_progress' => 'In progress',
        'waiting_info' => 'Waiting for your reply',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ],
    'code_taken' => 'A request type with that code already exists.',
    'role_unknown' => 'That role does not exist in the organization.',
    'category_archived' => 'That request type is archived.',
    'attachment_required' => 'This request type requires an attachment.',
    'closed_no_messages' => 'The request is closed: reopen it or create a new one.',
    'internal_not_allowed' => 'Only Human Resources can add internal notes.',
    'invalid_transition' => 'That action cannot be applied to the request in its current status.',
    'message_required' => 'Write what information you need.',
    'assignee_unknown' => 'That user is not active in the organization.',
];
