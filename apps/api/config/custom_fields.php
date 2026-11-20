<?php

/**
 * CFG-4 · Entidades que admiten campos personalizados. Cada módulo agrega la suya al implementarse
 * (persona, relación laboral, candidato, activo…). view/edit: permisos del recurso.
 */
return [
    'entities' => [
        'legal_entity' => ['module' => 'base', 'view' => 'org.legal_entities.view', 'edit' => 'org.legal_entities.update'],
        'task' => ['module' => 'base', 'view' => 'workflow.tasks.view', 'edit' => 'workflow.tasks.update'],
        // CFG-5 · La relación laboral (ficha del colaborador): tabla, filtros, exportación, importación y API.
        'employment' => ['module' => 'core', 'view' => 'people.employments.view', 'edit' => 'people.employments.update'],
    ],
    'max_per_entity' => 50,
];
