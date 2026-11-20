<?php

/**
 * Textos por defecto de los avisos (NOT-1). Cada organización puede reemplazarlos en
 * Configuración → Mensajes. Las variables van entre llaves: {name}.
 */
return [
    'templates' => [
        'approval.pending' => [
            'in_app' => ['subject' => 'Request to approve', 'body' => '{requester} sent a {type} request (level {level}).'],
            'email' => ['subject' => 'You have a request to approve', 'body' => "Hi {name},\n\nYou have a {type} request waiting for your decision at {organization}.\n\nReview it here: {link}\n\nFor security, details are only shown after signing in."],
        ],
        'approval.reminder' => [
            'in_app' => ['subject' => 'Reminder: request to approve', 'body' => '{requester}\'s {type} request is still waiting for your decision.'],
            'email' => ['subject' => 'Reminder: you have a request to approve', 'body' => "Hi {name},\n\nA {type} request is still waiting for your decision at {organization}.\n\nReview it here: {link}"],
        ],
        'approval.escalated' => [
            'in_app' => ['subject' => 'Request escalated to you', 'body' => '{requester}\'s {type} request is overdue and now needs your decision.'],
            'email' => ['subject' => 'An overdue request was assigned to you', 'body' => "Hi {name},\n\nA {type} request is overdue and now needs your decision at {organization}.\n\nReview it here: {link}"],
        ],
        'approval.approved' => [
            'in_app' => ['subject' => 'Your request was approved', 'body' => 'Your {type} request was approved.'],
            'email' => ['subject' => 'Your request was approved', 'body' => "Hi {name},\n\nYour {type} request was approved.\n\nSee the details here: {link}"],
        ],
        'approval.rejected' => [
            'in_app' => ['subject' => 'Your request was rejected', 'body' => 'Your {type} request was rejected: “{reason}”.'],
            'email' => ['subject' => 'Your request was rejected', 'body' => "Hi {name},\n\nYour {type} request was rejected. The reason is in the app.\n\nSee it here: {link}"],
        ],
        'task.assigned' => [
            'in_app' => ['subject' => 'New task assigned', 'body' => '{title} · due {due_on}'],
            'email' => ['subject' => 'You have a new task', 'body' => "Hi {name},\n\nYou were assigned a task at {organization}: {title}.\n\nOpen it here: {link}"],
        ],
        'task.overdue' => [
            'in_app' => ['subject' => 'Overdue task', 'body' => '{title} was due on {due_on}.'],
            'email' => ['subject' => 'You have an overdue task', 'body' => "Hi {name},\n\nThe task “{title}” was due on {due_on}.\n\nOpen it here: {link}"],
        ],
        'automation.notice' => [
            'in_app' => ['subject' => '{title}', 'body' => '{message}'],
            'email' => ['subject' => '{title}', 'body' => "Hi {name},\n\n{message}\n\nMore details here: {link}"],
        ],
        'automation.failing' => [
            'in_app' => ['subject' => 'An automation is failing', 'body' => 'The rule “{rule}” failed {count} times in a row. Last error: {error}'],
            'email' => ['subject' => 'An automation is failing', 'body' => "Hi {name},\n\nThe automation rule “{rule}”, which you own, failed {count} times in a row.\n\nLast error: {error}\n\nReview it here: {link}"],
        ],
        'automation.paused' => [
            'in_app' => ['subject' => 'An automation paused itself', 'body' => 'The rule “{rule}” was paused because it ran too many times in a row. Review it before turning it back on.'],
            'email' => ['subject' => 'An automation paused itself', 'body' => "Hi {name},\n\nThe automation rule “{rule}” paused itself because it ran too many times in one hour (possible loop). Review it before turning it back on.\n\nOpen it here: {link}"],
        ],
        'account.security' => [
            'in_app' => ['subject' => 'Account security notice', 'body' => '{event}'],
            'email' => ['subject' => 'Account security notice', 'body' => "Hi {name},\n\n{event}\n\nIf this was not you, contact HR right away."],
        ],
        'organization.suspended' => [
            'in_app' => ['subject' => 'Your organization was suspended', 'body' => 'Your organization was suspended: {reason}. Data is kept; contact support to resolve it.'],
            'email' => ['subject' => 'Your organization was suspended', 'body' => "Hi {name},\n\nThe organization {organization} was suspended. Reason: {reason}.\n\nWhile it is suspended, its users cannot sign in. Data is kept. Contact GestiaHR support to resolve it."],
        ],
        'organization.reactivated' => [
            'in_app' => ['subject' => 'Your organization is active again', 'body' => 'The organization is active again. All its data is still available.'],
            'email' => ['subject' => 'Your organization is active again', 'body' => "Hi {name},\n\nThe organization {organization} is active again and its users can sign in. All data is still available.\n\n{link}"],
        ],
        'organization.activated' => [
            'in_app' => ['subject' => 'Your organization is now active', 'body' => 'The trial ended and the organization is now active.'],
            'email' => ['subject' => 'Your organization is now active', 'body' => "Hi {name},\n\nThe organization {organization} moved from trial to active. Everything recorded during the trial is kept.\n\n{link}"],
        ],
        'organization.cancelled' => [
            'in_app' => ['subject' => 'Your organization was cancelled', 'body' => 'The organization was cancelled: {reason}. Data is kept until {retention_until}.'],
            'email' => ['subject' => 'Your organization was cancelled', 'body' => "Hi {name},\n\nThe organization {organization} was cancelled. Reason: {reason}.\n\nIts data is kept until {retention_until}. Until then you can ask support for a full export or for reactivation."],
        ],
        'notifications.email_bounced' => [
            'in_app' => ['subject' => 'An email bounced', 'body' => '{user}\'s email bounced and is no longer used. Fix it in their account or reactivate it from the delivery log.'],
            'email' => ['subject' => 'An email in your organization bounced', 'body' => "Hi {name},\n\n{user}'s email bounced at {organization} and is no longer used; their notices arrive in the app or by text message.\n\nFix it here: {link}"],
        ],
        'people.dependent_majority' => [
            'in_app' => ['subject' => 'A dependent child reached legal age', 'body' => 'Check whether the child of {employee} remains a dependent (studies, disability) or deregister them.'],
            'email' => ['subject' => 'Review a dependent', 'body' => "Hello {name}:\n\nA dependent child of {employee} reached legal age at {organization}. Check whether they remain a dependent or deregister them.\n\nReview it here: {link}"],
        ],
        'people.bank_account_requested' => [
            'in_app' => ['subject' => 'A payment account was registered in your name', 'body' => 'The account ending in {last4} was registered for your payments. If you did not request it, tell HR right away.'],
            'email' => ['subject' => 'A payment account was registered in your name', 'body' => "Hello {name}:\n\nAt {organization}, the account ending in {last4} was registered for your payments and awaits approval.\n\nIf you did not request it, tell HR right away."],
        ],
        'people.bank_account_changed' => [
            'in_app' => ['subject' => 'Your payment account changed', 'body' => 'Your payments will go to the account ending in {last4}. If you did not request it, tell HR right away.'],
            'email' => ['subject' => 'Your payment account changed', 'body' => "Hello {name}:\n\nAt {organization}, your payments will now go to the account ending in {last4}.\n\nIf you did not request it, tell HR right away."],
        ],
        'absences.subsidy_started' => [
            'in_app' => ['subject' => '{employee} has {days} subsidy days to file', 'body' => "{employee}'s leave becomes subsidized from {starts_on} ({days} days). File the reimbursement claim and track it."],
            'email' => ['subject' => '{employee} has {days} subsidy days to file', 'body' => "Hi {name}:\n\nAt {organization}, {employee}'s leave becomes subsidized from {starts_on} ({days} days). File the reimbursement claim and track it here: {link}"],
        ],
        'exports.ready' => [
            'in_app' => ['subject' => 'Your {resource} export is ready', 'body' => 'The {resource} file ({rows} rows) is ready to download.'],
            'email' => ['subject' => 'Your {resource} export is ready', 'body' => "Hi {name},\n\nIn {organization}, the {resource} file ({rows} rows) is ready to download here: {link}"],
        ],
        'exports.failed' => [
            'in_app' => ['subject' => 'The {resource} export could not be generated', 'body' => 'The {resource} export did not finish. Request it again; if it keeps failing, contact support.'],
            'email' => ['subject' => 'The {resource} export could not be generated', 'body' => "Hi {name},\n\nIn {organization}, the {resource} export did not finish. Request it again here: {link}"],
        ],
        'team.birthday' => [
            'in_app' => ['subject' => '{employee}\'s birthday', 'body' => '{date} is the birthday of {employee}, from your team.'],
            'email' => ['subject' => '{employee}\'s birthday', 'body' => "Hello {name}:\n\nIn {organization}, {date} is the birthday of {employee}, from your team.\n\nSee your team's dates here: {link}"],
        ],
        'team.anniversary' => [
            'in_app' => ['subject' => '{employee} completes {years} years at the company', 'body' => 'On {date}, {employee}, from your team, completes {years} years since joining.'],
            'email' => ['subject' => '{employee} completes {years} years at the company', 'body' => "Hello {name}:\n\nIn {organization}, on {date}, {employee}, from your team, completes {years} years since joining.\n\nSee your team's dates here: {link}"],
        ],
        'contracts.probation_ending' => [
            'in_app' => ['subject' => '{employee}\'s probation ends soon', 'body' => '{employee}\'s probation period ends on {ends_on}. Review their performance before that date.'],
            'email' => ['subject' => '{employee}\'s probation ends soon', 'body' => "Hello {name}:\n\nIn {organization}, {employee}'s probation period ends on {ends_on}. Review their performance and decide before that date.\n\nReview it here: {link}"],
        ],
        'contracts.expiring' => [
            'in_app' => ['subject' => '{employee}\'s contract ends in {days_left} days', 'body' => '{employee}\'s contract ends on {ends_on}. {explanation} Decide whether to renew it, convert it to an indefinite contract or let it end.'],
            'email' => ['subject' => '{employee}\'s contract ends in {days_left} days', 'body' => "Hello {name}:\n\nIn {organization}, {employee}'s contract ends on {ends_on} ({days_left} days left). {explanation}\n\nDecide whether to renew it, convert it to an indefinite contract or let it end: {link}"],
        ],
        'contracts.cap_approaching' => [
            'in_app' => ['subject' => '{employee} is close to the fixed-term contract cap', 'body' => 'When the contract ending on {ends_on} is over, {employee} will be close to the allowed cap. {explanation} Renewing could turn the contract into an indefinite one by law.'],
            'email' => ['subject' => '{employee} is close to the fixed-term contract cap', 'body' => "Hello {name}:\n\nIn {organization}, when the contract ending on {ends_on} is over, {employee} will be close to the fixed-term contract cap. {explanation}\n\nRenewing could turn the contract into an indefinite one by law. Review it here: {link}"],
        ],
        'documents.expiring' => [
            'in_app' => ['subject' => '{document} expires in {days_left} days', 'body' => '{document} ({type}) of {employee} expires on {expires_on}. Request or upload the renewed version before then.'],
            'email' => ['subject' => '{document} expires in {days_left} days', 'body' => "Hello {name}:\n\nIn {organization}, {document} ({type}) of {employee} expires on {expires_on} ({days_left} days left).\n\nRequest or upload the renewed version before then: {link}"],
        ],
        'documents.expired' => [
            'in_app' => ['subject' => '{document} has expired', 'body' => '{document} ({type}) of {employee} expired on {expires_on}. Upload the current version to bring the file up to date.'],
            'email' => ['subject' => '{document} has expired', 'body' => "Hello {name}:\n\nIn {organization}, {document} ({type}) of {employee} expired on {expires_on}.\n\nUpload the current version to bring the file up to date: {link}"],
        ],
        'documents.requested' => [
            'in_app' => ['subject' => 'A document is requested from you: {document}', 'body' => 'Human Resources asks you for {document} by {due_on}. {instructions} Submit it from your portal, under My documents.'],
            'email' => ['subject' => 'A document is requested from you: {document}', 'body' => "Hi {name}:\n\nAt {organization}, Human Resources asks you for {document} by {due_on}.\n\n{instructions}\n\nSubmit it from your portal: {link}"],
        ],
        'documents.rejected' => [
            'in_app' => ['subject' => 'Your document {document} needs changes', 'body' => 'Human Resources returned {document} ({type}): {reason} Upload a corrected version.'],
            'email' => ['subject' => 'Your document {document} needs changes', 'body' => "Hello {name}:\n\nIn {organization}, Human Resources returned {document} ({type}): {reason}\n\nUpload a corrected version: {link}"],
        ],
        'signatures.requested' => [
            'in_app' => ['subject' => 'Document to sign: {document}', 'body' => 'You have a document to sign: {document}.'],
            'email' => ['subject' => 'Document to sign: {document}', 'body' => "Hello {name}:\n\nAt {organization} you have a document to sign: {document}.\n\nReview and sign it here: {link}"],
        ],
        'signatures.completed' => [
            'in_app' => ['subject' => 'Signing completed: {document}', 'body' => 'Everyone signed {document}. The signature certificate is in the file.'],
            'email' => ['subject' => 'Signing completed: {document}', 'body' => "Hello {name}:\n\nAt {organization}, everyone signed {document}. The signature certificate is in the file.\n\nView: {link}"],
        ],
        'signatures.declined' => [
            'in_app' => ['subject' => 'Signature declined: {document}', 'body' => '{signer} declined to sign {document}: {reason}'],
            'email' => ['subject' => 'Signature declined: {document}', 'body' => "Hello {name}:\n\nAt {organization}, {signer} declined to sign {document}.\nReason: {reason}\n\nView: {link}"],
        ],
        'certificates.requested' => [
            'in_app' => ['subject' => 'Certificate to issue: {certificate}', 'body' => '{employee} requested a {certificate}. Review it and issue or reject it.'],
            'email' => ['subject' => 'Certificate to issue: {certificate}', 'body' => "Hi {name}:\n\nAt {organization}, {employee} requested a {certificate} from their portal.\n\nIssue or reject it here: {link}"],
        ],
        'certificates.issued' => [
            'in_app' => ['subject' => 'Your {certificate} is ready', 'body' => 'Your {certificate} is now in your documents. Download it whenever you need it.'],
            'email' => ['subject' => 'Your {certificate} is ready', 'body' => "Hi {name}:\n\nAt {organization}, your {certificate} was issued and saved in your documents.\n\nDownload it here: {link}"],
        ],
        'certificates.rejected' => [
            'in_app' => ['subject' => 'Your {certificate} was not issued', 'body' => 'Human Resources did not issue your {certificate}: {reason}'],
            'email' => ['subject' => 'Your {certificate} was not issued', 'body' => "Hi {name}:\n\nAt {organization}, Human Resources did not issue your {certificate}: {reason}\n\nCheck your requests here: {link}"],
        ],
        'vacations.accumulation' => [
            'in_app' => ['subject' => '{employee} has accumulated vacation', 'body' => '{explanation}'],
            'email' => ['subject' => '{employee} has accumulated vacation', 'body' => "Hello {name}:\n\nAt {organization}, {employee} has vacation to take: {explanation}\n\nReview it here: {link}"],
        ],
        'remote_work.response_due' => [
            'in_app' => ['subject' => 'Answer the remote work request from {employee}', 'body' => 'The deadline to answer the remote work request from {employee} is {due_on}.'],
            'email' => ['subject' => 'Remote work request from {employee} is due on {due_on}', 'body' => "Hello {name}:\n\nAt {organization}, the deadline to answer the remote work request from {employee} is {due_on}.\n\nAnswer it here: {link}"],
        ],
        'help.article_review_due' => [
            'in_app' => ['subject' => 'Review “{title}”', 'body' => 'The help article “{title}” of your organization is due for review on {review_on}. It is marked for review and still visible.'],
            'email' => ['subject' => 'Time to review “{title}”', 'body' => "Hello {name}:\n\nAt {organization}, the help article “{title}” is due for review on {review_on}. It stays visible to its audience while you review it.\n\nReview it here: {link}"],
        ],
        'tickets.created' => [
            'in_app' => ['subject' => 'New request {number}: {subject}', 'body' => '{employee} sent a {category} request: {subject}.'],
            'email' => ['subject' => 'New request {number}: {subject}', 'body' => "Hi {name}:\n\nAt {organization}, {employee} sent request {number} ({category}): {subject}.\n\nHandle it here: {link}"],
        ],
        'tickets.replied' => [
            'in_app' => ['subject' => '{employee} replied to request {number}', 'body' => '{employee} added information to {number}: {subject}.'],
            'email' => ['subject' => '{employee} replied to request {number}', 'body' => "Hi {name}:\n\nAt {organization}, {employee} replied to request {number}: {subject}.\n\nReview it here: {link}"],
        ],
        'tickets.updated' => [
            'in_app' => ['subject' => 'Your request {number} changed', 'body' => '{number} · {subject}: {status}.'],
            'email' => ['subject' => 'Your request {number} changed', 'body' => "Hi {name}:\n\nAt {organization}, your request {number} ({subject}) is now: {status}.\n\nSee the details here: {link}"],
        ],
        'billing.invoice_issued' => [
            'in_app' => ['subject' => 'New GestiaHR invoice', 'body' => 'Invoice {number} for {total}, due on {due_on}.'],
            'email' => ['subject' => 'Your GestiaHR invoice {number}', 'body' => "Hi {name},\n\nWe issued electronic invoice {number} for {organization} for {total}, due on {due_on}.\n\nSee your account statement here: {link}"],
        ],
        'billing.due_soon' => [
            'in_app' => ['subject' => 'An invoice is due soon', 'body' => 'Invoice {number} for {total} is due on {due_on}.'],
            'email' => ['subject' => 'Your invoice {number} is due on {due_on}', 'body' => "Hi {name},\n\nInvoice {number} for {organization} for {total} is due on {due_on}. If you already paid, there is nothing to do.\n\nSee your account statement here: {link}"],
        ],
        'billing.overdue' => [
            'in_app' => ['subject' => 'You have an overdue invoice', 'body' => 'Invoice {number} for {total} was due on {due_on}. If it stays unpaid, the organization is suspended on {suspend_on}.'],
            'email' => ['subject' => 'Overdue invoice: {number}', 'body' => "Hi {name},\n\nInvoice {number} for {organization} for {total} was due on {due_on}. If it stays unpaid, the organization is suspended on {suspend_on}: nobody can work until it is settled, and the data is kept.\n\nSee your account statement here: {link}"],
        ],
        'limits.warning' => [
            'in_app' => ['subject' => 'You are close to a plan limit', 'body' => '{metric}: {current} of {limit} ({percent}%). Check your usage before reaching the cap.'],
            'email' => ['subject' => 'Your organization is close to a plan limit', 'body' => "Hi {name},\n\nAt {organization}, {metric} is at {current} of {limit} ({percent}%). Once the cap is reached, new additions or uploads will be blocked; everything already stored keeps working.\n\nCheck your usage and how to raise it here: {link}"],
        ],
        'limits.reached' => [
            'in_app' => ['subject' => 'You reached a plan limit', 'body' => '{metric}: {current} of {limit}. New additions or uploads are blocked until you upgrade the plan or free up space.'],
            'email' => ['subject' => 'Your organization reached a plan limit', 'body' => "Hi {name},\n\nAt {organization}, {metric} reached {current} of {limit}. New additions or uploads of that kind are now blocked; everything already recorded is still available.\n\nUpgrade the plan or add an add-on here: {link}"],
        ],
    ],
    'subject_types' => [
        'vacation_request' => 'vacation',
        'permission_request' => 'leave',
        'overtime_request' => 'overtime',
        'expense_report' => 'expenses',
        'job_requisition' => 'job requisition',
        'position_request' => 'new position',
        'compensation_change' => 'compensation change',
        'certificate_request' => 'certificate',
        'other' => 'request',
    ],
    'invalid_variables' => 'Variables not allowed: :vars. Use only: :allowed.',
    'unknown_category' => 'Unknown notification type.',
    'mandatory_in_app' => 'This notification is mandatory and cannot be turned off in the app.',
    'template_exists' => 'A template for that notification, channel and language already exists.',
    'unsupported_channel' => 'That channel is not available yet.',
    // NOT-5 · Mensajes directos por SMS y WhatsApp (variables entre llaves).
    'messages' => [
        'people.bank_account_requested' => [
            'sms' => '{organization}: the account ending in {last4} was registered for your payments. If you did not request it, tell HR.',
            'whatsapp' => '{organization}: the account ending in {last4} was registered for your payments. If you did not request it, tell HR.',
        ],
        'people.bank_account_changed' => [
            'sms' => '{organization}: your payments will go to the account ending in {last4}. If you did not request it, tell HR.',
            'whatsapp' => '{organization}: your payments will go to the account ending in {last4}. If you did not request it, tell HR.',
        ],
        'identity.login_code' => [
            'sms' => 'GestiaHR: your sign-in code is {code}. It expires in {minutes} minutes. Do not share it.',
            'whatsapp' => 'GestiaHR: your sign-in code is {code}. It expires in {minutes} minutes. Do not share it.',
        ],
        'identity.activation_code' => [
            'sms' => 'GestiaHR: your account activation code is {code}. It expires in {hours} hours. Sign in with your ID document and this code.',
            'whatsapp' => 'GestiaHR: your account activation code is {code}. It expires in {hours} hours. Sign in with your ID document and this code.',
        ],
        'notifications.notice' => [
            'sms' => '{organization}: {title}. Sign in to see it: {link}',
            'whatsapp' => '{organization}: {title}. Sign in to see it: {link}',
        ],
    ],
    'approval_only_whatsapp' => 'Only WhatsApp templates need the provider\'s approval.',
    'approval_reset' => 'The template changed and must be approved again before it is used on WhatsApp.',
    'sender' => [
        'invalid_address' => 'Enter a valid sender email.',
        'nothing_to_verify' => 'The organization uses the platform sender: there is nothing to verify.',
        'already_verified' => 'The sender is already verified.',
    ],
    'bounce' => [
        'nothing_to_clear' => 'This delivery has no bounced address to reactivate.',
        'invalid_signature' => 'The provider signature is not valid.',
    ],
    // NOT-4 · El aviso push solo invita a abrir la aplicación (RN-NOT-02).
    'push' => ['title' => 'New in :organization', 'body' => 'You have a new notice. Open it in GestiaHR.'],
];
