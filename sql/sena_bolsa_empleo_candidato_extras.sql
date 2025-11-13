-- Tablas adicionales para almacenar información extendida del candidato

CREATE TABLE IF NOT EXISTS `candidato_experiencias` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(254) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cargo` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `empresa` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `periodo` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anios_experiencia` decimal(4,1) DEFAULT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `orden` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exp_email` (`email`),
  CONSTRAINT `fk_exp_candidato` FOREIGN KEY (`email`) REFERENCES `candidatos` (`email`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `candidato_educacion` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(254) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titulo` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `institucion` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `periodo` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `orden` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_edu_email` (`email`),
  CONSTRAINT `fk_edu_candidato` FOREIGN KEY (`email`) REFERENCES `candidatos` (`email`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `candidato_habilidades` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(254) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `anios_experiencia` decimal(4,1) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_skill_email` (`email`),
  CONSTRAINT `fk_skill_candidato` FOREIGN KEY (`email`) REFERENCES `candidatos` (`email`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
