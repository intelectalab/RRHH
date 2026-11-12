<?php

return [
    'scope_not_allowed' => 'Este parámetro no se puede definir para ese ámbito.',
    'invalid_value' => 'El valor no es válido para este parámetro.',
    'later_value_exists' => 'Ya hay un valor programado con fecha posterior; cámbialo o elimínalo antes.',
    'national_calendar' => 'Feriados nacionales (:country)',
    'holiday_exists' => 'Ya hay un feriado el :date en este calendario.',
    'past_holiday_locked' => 'Un feriado que ya pasó no se quita: asistencia y planilla ya lo usaron.',
    'national_holiday_locked' => 'Los feriados nacionales los fija la ley: no se quitan del calendario.',
    'no_exchange_rate' => 'No hay tipo de cambio de :from a :to vigente al :date.',
    'rate_exists' => 'Ya hay un tipo de cambio para ese par y fecha; publica uno con otra fecha.',
    'custom_field_unknown' => 'Este campo no existe para este registro.',
    'custom_field_forbidden' => 'No tienes permiso para este campo.',
    'custom_field_required' => 'Este campo es obligatorio.',
    'custom_field_key_taken' => 'Ya existe un campo con esa clave para esta entidad (puede estar en la papelera).',
    'custom_field_type_locked' => 'El campo ya tiene valores guardados: no se puede cambiar su tipo.',
    'custom_field_options_required' => 'Indica las opciones de la lista.',
    'custom_field_permission_required' => 'Un campo sensible necesita un permiso por campo.',
    'calendar' => [
        'invalid_range' => 'Indica un rango válido: from y to en formato AAAA-MM-DD, to no anterior a from y de hasta 100 días.',
        'unknown_source' => 'La fuente «:source» no existe en el calendario.',
    ],
    'saved_view_filter' => 'Solo se puede filtrar por resource.',
    'saved_view_limit' => 'Llegaste al máximo de :max vistas guardadas en esta tabla. Borra alguna para guardar otra.',
    'saved_view_not_owner' => 'Solo quien creó la vista puede cambiarla o borrarla.',
    'saved_view_name_taken' => 'Ya tienes una vista con ese nombre en esta tabla.',
];
