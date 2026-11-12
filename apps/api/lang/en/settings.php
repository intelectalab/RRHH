<?php

return [
    'scope_not_allowed' => 'This parameter cannot be set at that scope.',
    'invalid_value' => 'The value is not valid for this parameter.',
    'later_value_exists' => 'A value is already scheduled for a later date; change it first.',
    'national_calendar' => 'National holidays (:country)',
    'holiday_exists' => 'There is already a holiday on :date in this calendar.',
    'past_holiday_locked' => 'A past holiday cannot be removed: attendance and payroll already used it.',
    'national_holiday_locked' => 'National holidays are set by law: they cannot be removed.',
    'no_exchange_rate' => 'There is no :from to :to exchange rate in effect on :date.',
    'rate_exists' => 'There is already a rate for that pair and date; publish one with another date.',
    'custom_field_unknown' => 'This field does not exist for this record.',
    'custom_field_forbidden' => 'You do not have permission for this field.',
    'custom_field_required' => 'This field is required.',
    'custom_field_key_taken' => 'A field with that key already exists for this entity (it may be in the trash).',
    'custom_field_type_locked' => 'The field already has saved values: its type cannot change.',
    'custom_field_options_required' => 'Enter the list options.',
    'custom_field_permission_required' => 'A sensitive field needs a field permission.',
    'calendar' => [
        'invalid_range' => 'Enter a valid range: from and to as YYYY-MM-DD, to not before from and up to 100 days.',
        'unknown_source' => 'The calendar has no source «:source».',
    ],
    'saved_view_filter' => 'You can only filter by resource.',
    'saved_view_limit' => 'You reached the maximum of :max saved views for this table. Delete one to save another.',
    'saved_view_not_owner' => 'Only the person who created the view can change or delete it.',
    'saved_view_name_taken' => 'You already have a view with that name for this table.',
];
