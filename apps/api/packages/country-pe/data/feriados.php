<?php

/**
 * Paquete de país Perú · Feriados nacionales (CFG-8).
 *
 * Fuente: calendario oficial de feriados nacionales (Decreto Legislativo 713 y sus modificatorias,
 * incluidas las leyes que agregaron el 7 de junio, el 23 de julio, el 6 de agosto y el 9 de diciembre).
 * Las fechas móviles (Jueves y Viernes Santo) se registran por año. Los «días no laborables» que el
 * Ejecutivo decreta cada año para el sector público no son feriados: cada organización los agrega
 * como tipo «non_working_compensable» si los adopta.
 *
 * Verificado: 2026 contra la publicación del calendario 2026 (El Comercio, 16 feriados).
 * Por validar: 2027 (fechas fijas iguales; Semana Santa calculada: Pascua 28/03/2027).
 */
return [
    'source' => 'D. Leg. 713 y modificatorias',
    'years' => [
        2026 => ['verified' => true, 'holidays' => [
            ['2026-01-01', 'Año Nuevo'],
            ['2026-04-02', 'Jueves Santo'],
            ['2026-04-03', 'Viernes Santo'],
            ['2026-05-01', 'Día del Trabajo'],
            ['2026-06-07', 'Batalla de Arica y Día de la Bandera'],
            ['2026-06-29', 'San Pedro y San Pablo'],
            ['2026-07-23', 'Día de la Fuerza Aérea del Perú'],
            ['2026-07-28', 'Fiestas Patrias'],
            ['2026-07-29', 'Fiestas Patrias'],
            ['2026-08-06', 'Batalla de Junín'],
            ['2026-08-30', 'Santa Rosa de Lima'],
            ['2026-10-08', 'Combate de Angamos'],
            ['2026-11-01', 'Día de Todos los Santos'],
            ['2026-12-08', 'Inmaculada Concepción'],
            ['2026-12-09', 'Batalla de Ayacucho'],
            ['2026-12-25', 'Navidad'],
        ]],
        2027 => ['verified' => false, 'holidays' => [
            ['2027-01-01', 'Año Nuevo'],
            ['2027-03-25', 'Jueves Santo'],
            ['2027-03-26', 'Viernes Santo'],
            ['2027-05-01', 'Día del Trabajo'],
            ['2027-06-07', 'Batalla de Arica y Día de la Bandera'],
            ['2027-06-29', 'San Pedro y San Pablo'],
            ['2027-07-23', 'Día de la Fuerza Aérea del Perú'],
            ['2027-07-28', 'Fiestas Patrias'],
            ['2027-07-29', 'Fiestas Patrias'],
            ['2027-08-06', 'Batalla de Junín'],
            ['2027-08-30', 'Santa Rosa de Lima'],
            ['2027-10-08', 'Combate de Angamos'],
            ['2027-11-01', 'Día de Todos los Santos'],
            ['2027-12-08', 'Inmaculada Concepción'],
            ['2027-12-09', 'Batalla de Ayacucho'],
            ['2027-12-25', 'Navidad'],
        ]],
    ],
];
