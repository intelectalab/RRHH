-- Datos iniciales del sistema AyniHR
-- Solo roles, parámetros base, feriados nacionales oficiales y usuario administrador

USE `aynihr`;

-- 1. Roles iniciales
INSERT INTO `roles` (`id`, `nombre`, `alcance`, `permisos`, `sistema`) VALUES
(1, 'Administrador', 'T', '["personal.ver","personal.editar","personal.sueldo","personal.sensible","organizacion.editar","asistencia.ver","asistencia.editar","vacaciones.ver","vacaciones.aprobar","config.usuarios","config.auditoria"]', 1),
(2, 'Recursos Humanos', 'T', '["personal.ver","personal.editar","personal.sueldo","personal.sensible","organizacion.editar","asistencia.ver","asistencia.editar","vacaciones.ver","vacaciones.aprobar","config.auditoria"]', 1),
(3, 'Jefe de área', 'A', '["personal.ver","asistencia.ver","vacaciones.ver","vacaciones.aprobar"]', 1),
(4, 'Colaborador', 'P', '[]', 1),
(5, 'Auditor', 'T', '["personal.ver","asistencia.ver","vacaciones.ver","config.auditoria"]', 1)
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);

-- 2. Parámetros de configuración
INSERT INTO `parametros` (`id`, `diasVacAnio`, `diasMinVac`, `avisoContratoDias`, `filasPagina`) VALUES
(1, 30, 7, 60, 25)
ON DUPLICATE KEY UPDATE `id` = 1;

-- 3. Feriados nacionales oficiales (2026 y 2027)
INSERT INTO `feriados` (`fecha`, `nombre`) VALUES
-- 2026
('2026-01-01', 'Año Nuevo'),
('2026-04-02', 'Jueves Santo'),
('2026-04-03', 'Viernes Santo'),
('2026-05-01', 'Día del Trabajo'),
('2026-06-07', 'Batalla de Arica y Día de la Bandera'),
('2026-06-29', 'San Pedro y San Pablo'),
('2026-07-23', 'Día de la Fuerza Aérea'),
('2026-07-28', 'Fiestas Patrias'),
('2026-07-29', 'Fiestas Patrias'),
('2026-08-06', 'Batalla de Junín'),
('2026-08-30', 'Santa Rosa de Lima'),
('2026-10-08', 'Combate de Angamos'),
('2026-11-01', 'Todos los Santos'),
('2026-12-08', 'Inmaculada Concepción'),
('2026-12-09', 'Batalla de Ayacucho'),
('2026-12-25', 'Navidad'),
-- 2027
('2027-01-01', 'Año Nuevo'),
('2027-03-25', 'Jueves Santo'),
('2027-03-26', 'Viernes Santo'),
('2027-05-01', 'Día del Trabajo'),
('2027-06-07', 'Batalla de Arica y Día de la Bandera'),
('2027-06-29', 'San Pedro y San Pablo'),
('2027-07-23', 'Día de la Fuerza Aérea'),
('2027-07-28', 'Fiestas Patrias'),
('2027-07-29', 'Fiestas Patrias'),
('2027-08-06', 'Batalla de Junín'),
('2027-08-30', 'Santa Rosa de Lima'),
('2027-10-08', 'Combate de Angamos'),
('2027-11-01', 'Todos los Santos'),
('2027-12-08', 'Inmaculada Concepción'),
('2027-12-09', 'Batalla de Ayacucho'),
('2027-12-25', 'Navidad')
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);

-- 4. Usuario inicial Administrador
INSERT INTO `usuarios` (`id`, `usuario`, `nombre`, `colabId`, `rolId`, `activo`, `ultimo`, `clave_hash`, `debe_cambiar`, `bloqueado_hasta`) VALUES
(1, 'admin', 'Administrador', 0, 1, 1, '', '$2y$10$.EKqGYFw82lscOXwIoh6DO3dL0rD6S7z5jG9FYSaqh5WUCvA.q53i', 1, NULL)
ON DUPLICATE KEY UPDATE `clave_hash` = VALUES(`clave_hash`);

