-- MySQL dump 10.13  Distrib 8.0.44, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: sena_bolsa_empleo
-- ------------------------------------------------------
-- Server version	8.0.44

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `vacantes`
--

DROP TABLE IF EXISTS `vacantes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vacantes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` bigint unsigned NOT NULL,
  `titulo` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `area_id` smallint unsigned DEFAULT NULL,
  `nivel_id` tinyint unsigned DEFAULT NULL,
  `modalidad_id` tinyint unsigned DEFAULT NULL,
  `tipo_contrato_id` tinyint unsigned DEFAULT NULL,
  `ciudad` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `salario_min` int unsigned DEFAULT NULL,
  `salario_max` int unsigned DEFAULT NULL,
  `moneda` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'COP',
  `descripcion` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `requisitos` text COLLATE utf8mb4_unicode_ci,
  `etiquetas` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` enum('borrador','publicada','pausada','cerrada') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'publicada',
  `publicada_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_vac_area` (`area_id`),
  KEY `fk_vac_nivel` (`nivel_id`),
  KEY `fk_vac_modalidad` (`modalidad_id`),
  KEY `fk_vac_contrato` (`tipo_contrato_id`),
  KEY `idx_vac_empresa` (`empresa_id`),
  KEY `idx_vac_estado` (`estado`),
  KEY `idx_vac_ciudad` (`ciudad`),
  CONSTRAINT `fk_vac_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`),
  CONSTRAINT `fk_vac_contrato` FOREIGN KEY (`tipo_contrato_id`) REFERENCES `contratos` (`id`),
  CONSTRAINT `fk_vac_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vac_modalidad` FOREIGN KEY (`modalidad_id`) REFERENCES `modalidades` (`id`),
  CONSTRAINT `fk_vac_nivel` FOREIGN KEY (`nivel_id`) REFERENCES `niveles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vacantes`
--

LOCK TABLES `vacantes` WRITE;
/*!40000 ALTER TABLE `vacantes` DISABLE KEYS */;
INSERT INTO `vacantes` VALUES (1,1,'Desarrollador Laravel',3,2,3,2,'Remoto',4500000,6500000,'COP','Construcción de APIs REST en Laravel 10, pruebas con PHPUnit, CI/CD.','2+ años en PHP/Laravel, bases de datos MySQL, Git.','Laravel,MySQL,API REST,Git','publicada','2025-10-29 19:56:41','2025-10-29 19:56:41','2025-10-29 19:56:41');
/*!40000 ALTER TABLE `vacantes` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-10-30 19:37:30
