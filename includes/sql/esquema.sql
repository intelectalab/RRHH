-- Esquema de base de datos para AyniHR
-- Motor: MySQL / MariaDB (InnoDB, utf8mb4)

CREATE DATABASE IF NOT EXISTS `aynihr` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `aynihr`;

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Empresas
DROP TABLE IF EXISTS `empresas`;
CREATE TABLE `empresas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ruc` VARCHAR(20) NOT NULL,
  `razon` VARCHAR(255) NOT NULL,
  `direccion` VARCHAR(255) NOT NULL DEFAULT '',
  `logo` VARCHAR(255) NOT NULL DEFAULT '',
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  INDEX `idx_empresas_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Sedes
DROP TABLE IF EXISTS `sedes`;
CREATE TABLE `sedes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `empresaId` INT NOT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  `ciudad` VARCHAR(100) NOT NULL DEFAULT '',
  `direccion` VARCHAR(255) NOT NULL DEFAULT '',
  `foto` VARCHAR(255) NOT NULL DEFAULT '',
  `responsableId` INT NOT NULL DEFAULT 0,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  INDEX `idx_sedes_empresaId` (`empresaId`),
  INDEX `idx_sedes_activo` (`activo`),
  CONSTRAINT `fk_sedes_empresa` FOREIGN KEY (`empresaId`) REFERENCES `empresas` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Áreas
DROP TABLE IF EXISTS `areas`;
CREATE TABLE `areas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NOT NULL,
  `padreId` INT NOT NULL DEFAULT 0,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  INDEX `idx_areas_padreId` (`padreId`),
  INDEX `idx_areas_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Puestos
DROP TABLE IF EXISTS `puestos`;
CREATE TABLE `puestos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NOT NULL,
  `areaId` INT NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  INDEX `idx_puestos_areaId` (`areaId`),
  INDEX `idx_puestos_activo` (`activo`),
  CONSTRAINT `fk_puestos_area` FOREIGN KEY (`areaId`) REFERENCES `areas` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Centros de Costo (ccs)
DROP TABLE IF EXISTS `ccs`;
CREATE TABLE `ccs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo` VARCHAR(50) NOT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  INDEX `idx_ccs_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Horarios
DROP TABLE IF EXISTS `horarios`;
CREATE TABLE `horarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NOT NULL,
  `entrada` VARCHAR(10) NOT NULL,
  `salida` VARCHAR(10) NOT NULL,
  `dias` VARCHAR(50) NOT NULL,
  `tolerancia` INT NOT NULL DEFAULT 0,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  INDEX `idx_horarios_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Roles
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NOT NULL,
  `alcance` VARCHAR(10) NOT NULL DEFAULT 'P',
  `permisos` LONGTEXT NOT NULL,
  `sistema` TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Colaboradores
DROP TABLE IF EXISTS `colaboradores`;
CREATE TABLE `colaboradores` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo` VARCHAR(20) NOT NULL,
  `tipoDoc` VARCHAR(20) NOT NULL DEFAULT 'DNI',
  `nroDoc` VARCHAR(30) NOT NULL,
  `apPat` VARCHAR(100) NOT NULL,
  `apMat` VARCHAR(100) NOT NULL,
  `nombres` VARCHAR(100) NOT NULL,
  `sexo` VARCHAR(1) NOT NULL DEFAULT 'M',
  `estadoCivil` VARCHAR(50) NOT NULL DEFAULT '',
  `direccion` VARCHAR(255) NOT NULL DEFAULT '',
  `distrito` VARCHAR(100) NOT NULL DEFAULT '',
  `telefono` VARCHAR(50) NOT NULL DEFAULT '',
  `email` VARCHAR(150) NOT NULL DEFAULT '',
  `fecNac` VARCHAR(10) NOT NULL DEFAULT '',
  `fecIngreso` VARCHAR(10) NOT NULL,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'Activo',
  `fecCese` VARCHAR(10) NOT NULL DEFAULT '',
  `motivoCese` VARCHAR(255) NOT NULL DEFAULT '',
  `tipoContrato` VARCHAR(50) NOT NULL DEFAULT 'Plazo fijo',
  `finContrato` VARCHAR(10) NOT NULL DEFAULT '',
  `empresaId` INT NOT NULL,
  `sedeId` INT NOT NULL,
  `areaId` INT NOT NULL,
  `puestoId` INT NOT NULL,
  `jefeId` INT NOT NULL DEFAULT 0,
  `horarioId` INT NOT NULL,
  `ccId` INT NOT NULL DEFAULT 0,
  `sueldo` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `pension` VARCHAR(50) NOT NULL DEFAULT '',
  `banco` VARCHAR(50) NOT NULL DEFAULT '',
  `cuenta` VARCHAR(255) NOT NULL DEFAULT '',
  `cuspp` VARCHAR(255) NOT NULL DEFAULT '',
  `reemplazoId` INT NOT NULL DEFAULT 0,
  `foto` VARCHAR(255) NOT NULL DEFAULT '',
  INDEX `idx_colab_empresaId` (`empresaId`),
  INDEX `idx_colab_sedeId` (`sedeId`),
  INDEX `idx_colab_areaId` (`areaId`),
  INDEX `idx_colab_puestoId` (`puestoId`),
  INDEX `idx_colab_estado` (`estado`),
  INDEX `idx_colab_fecha` (`fecIngreso`),
  INDEX `idx_colab_nroDoc` (`nroDoc`),
  CONSTRAINT `fk_colab_empresa` FOREIGN KEY (`empresaId`) REFERENCES `empresas` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_colab_sede` FOREIGN KEY (`sedeId`) REFERENCES `sedes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_colab_area` FOREIGN KEY (`areaId`) REFERENCES `areas` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_colab_puesto` FOREIGN KEY (`puestoId`) REFERENCES `puestos` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_colab_horario` FOREIGN KEY (`horarioId`) REFERENCES `horarios` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Usuarios
DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE `usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario` VARCHAR(50) NOT NULL UNIQUE,
  `nombre` VARCHAR(100) NOT NULL,
  `colabId` INT NOT NULL DEFAULT 0,
  `rolId` INT NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `ultimo` VARCHAR(20) NOT NULL DEFAULT '',
  `clave_hash` VARCHAR(255) NOT NULL DEFAULT '',
  `debe_cambiar` TINYINT(1) NOT NULL DEFAULT 0,
  `bloqueado_hasta` DATETIME NULL DEFAULT NULL,
  INDEX `idx_usuarios_colabId` (`colabId`),
  INDEX `idx_usuarios_rolId` (`rolId`),
  INDEX `idx_usuarios_activo` (`activo`),
  CONSTRAINT `fk_usuarios_rol` FOREIGN KEY (`rolId`) REFERENCES `roles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Historial
DROP TABLE IF EXISTS `historial`;
CREATE TABLE `historial` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `colabId` INT NOT NULL,
  `fecha` VARCHAR(20) NOT NULL,
  `tipo` VARCHAR(50) NOT NULL,
  `detalle` TEXT,
  `antes` TEXT,
  `despues` TEXT,
  `usuario` VARCHAR(100) NOT NULL DEFAULT '',
  INDEX `idx_historial_colabId` (`colabId`),
  INDEX `idx_historial_fecha` (`fecha`),
  CONSTRAINT `fk_historial_colab` FOREIGN KEY (`colabId`) REFERENCES `colaboradores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Marcaciones
DROP TABLE IF EXISTS `marcaciones`;
CREATE TABLE `marcaciones` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `colabId` INT NOT NULL,
  `fecha` VARCHAR(10) NOT NULL,
  `entrada` VARCHAR(10) NOT NULL DEFAULT '',
  `salida` VARCHAR(10) NOT NULL DEFAULT '',
  `origen` VARCHAR(50) NOT NULL DEFAULT '',
  `obs` VARCHAR(255) NOT NULL DEFAULT '',
  INDEX `idx_marcaciones_colabId` (`colabId`),
  INDEX `idx_marcaciones_fecha` (`fecha`),
  CONSTRAINT `fk_marcaciones_colab` FOREIGN KEY (`colabId`) REFERENCES `colaboradores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Vacaciones y ausencias
DROP TABLE IF EXISTS `vacaciones`;
CREATE TABLE `vacaciones` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `colabId` INT NOT NULL,
  `tipo` VARCHAR(50) NOT NULL,
  `desde` VARCHAR(10) NOT NULL,
  `hasta` VARCHAR(10) NOT NULL,
  `dias` INT NOT NULL DEFAULT 0,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'Pendiente',
  `fecSol` VARCHAR(10) NOT NULL,
  `obs` TEXT,
  `resuelto` VARCHAR(100) NOT NULL DEFAULT '',
  `reemplazoId` INT NOT NULL DEFAULT 0,
  INDEX `idx_vacaciones_colabId` (`colabId`),
  INDEX `idx_vacaciones_desde` (`desde`),
  INDEX `idx_vacaciones_hasta` (`hasta`),
  INDEX `idx_vacaciones_estado` (`estado`),
  CONSTRAINT `fk_vacaciones_colab` FOREIGN KEY (`colabId`) REFERENCES `colaboradores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Parámetros
DROP TABLE IF EXISTS `parametros`;
CREATE TABLE `parametros` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `diasVacAnio` INT NOT NULL DEFAULT 30,
  `diasMinVac` INT NOT NULL DEFAULT 7,
  `avisoContratoDias` INT NOT NULL DEFAULT 60,
  `filasPagina` INT NOT NULL DEFAULT 25
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Auditoría
DROP TABLE IF EXISTS `auditoria`;
CREATE TABLE `auditoria` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `fecha` VARCHAR(20) NOT NULL,
  `usuario` VARCHAR(100) NOT NULL,
  `accion` VARCHAR(100) NOT NULL,
  `detalle` TEXT,
  `sens` TINYINT(1) NOT NULL DEFAULT 0,
  INDEX `idx_auditoria_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Notificaciones
DROP TABLE IF EXISTS `notificaciones`;
CREATE TABLE `notificaciones` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuarioId` INT NOT NULL DEFAULT 0,
  `fecha` VARCHAR(20) NOT NULL,
  `txt` VARCHAR(255) NOT NULL,
  `ruta` VARCHAR(100) NOT NULL,
  `leida` TINYINT(1) NOT NULL DEFAULT 0,
  INDEX `idx_notificaciones_usuarioId` (`usuarioId`),
  INDEX `idx_notificaciones_fecha` (`fecha`),
  INDEX `idx_notificaciones_leida` (`leida`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Feriados
DROP TABLE IF EXISTS `feriados`;
CREATE TABLE `feriados` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `fecha` VARCHAR(10) NOT NULL UNIQUE,
  `nombre` VARCHAR(150) NOT NULL,
  INDEX `idx_feriados_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Documentos adjuntos
DROP TABLE IF EXISTS `documentos`;
CREATE TABLE `documentos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `colabId` INT NOT NULL,
  `tipo` VARCHAR(50) NOT NULL,
  `nombre_original` VARCHAR(255) NOT NULL,
  `archivo` VARCHAR(255) NOT NULL,
  `tamano` INT NOT NULL DEFAULT 0,
  `fecha` VARCHAR(20) NOT NULL,
  `usuario` VARCHAR(100) NOT NULL,
  INDEX `idx_documentos_colabId` (`colabId`),
  INDEX `idx_documentos_fecha` (`fecha`),
  CONSTRAINT `fk_documentos_colab` FOREIGN KEY (`colabId`) REFERENCES `colaboradores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Intentos de ingreso (control de fuerza bruta)
DROP TABLE IF EXISTS `intentos_login`;
CREATE TABLE `intentos_login` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario` VARCHAR(100) NOT NULL,
  `ip` VARCHAR(50) NOT NULL,
  `fecha` DATETIME NOT NULL,
  `exito` TINYINT(1) NOT NULL DEFAULT 0,
  INDEX `idx_intentos_usuario` (`usuario`),
  INDEX `idx_intentos_ip` (`ip`),
  INDEX `idx_intentos_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

