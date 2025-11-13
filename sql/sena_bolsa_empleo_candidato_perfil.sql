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
-- Table structure for table `candidato_perfil`
--

DROP TABLE IF EXISTS `candidato_perfil`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candidato_perfil` (
  `email` varchar(254) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rol_deseado` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `area_id` smallint unsigned NOT NULL,
  `nivel_id` tinyint unsigned NOT NULL,
  `modalidad_id` tinyint unsigned NOT NULL,
  `habilidades` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `resumen` text COLLATE utf8mb4_unicode_ci,
  `estudios_id` tinyint unsigned NOT NULL,
  `institucion` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anios_experiencia` tinyint unsigned NOT NULL,
  `contrato_pref_id` tinyint unsigned DEFAULT NULL,
  `disponibilidad_id` tinyint unsigned NOT NULL,
  `visible_empresas` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`email`),
  KEY `fk_perfil_estudios` (`estudios_id`),
  KEY `fk_perfil_contrato` (`contrato_pref_id`),
  KEY `fk_perfil_disponibilidad` (`disponibilidad_id`),
  KEY `idx_perfil_area` (`area_id`),
  KEY `idx_perfil_nivel` (`nivel_id`),
  KEY `idx_perfil_modalidad` (`modalidad_id`),
  CONSTRAINT `fk_perfil_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`),
  CONSTRAINT `fk_perfil_candidato` FOREIGN KEY (`email`) REFERENCES `candidatos` (`email`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_perfil_contrato` FOREIGN KEY (`contrato_pref_id`) REFERENCES `contratos` (`id`),
  CONSTRAINT `fk_perfil_disponibilidad` FOREIGN KEY (`disponibilidad_id`) REFERENCES `disponibilidades` (`id`),
  CONSTRAINT `fk_perfil_estudios` FOREIGN KEY (`estudios_id`) REFERENCES `niveles_estudio` (`id`),
  CONSTRAINT `fk_perfil_modalidad` FOREIGN KEY (`modalidad_id`) REFERENCES `modalidades` (`id`),
  CONSTRAINT `fk_perfil_nivel` FOREIGN KEY (`nivel_id`) REFERENCES `niveles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `candidato_perfil`
--

LOCK TABLES `candidato_perfil` WRITE;
/*!40000 ALTER TABLE `candidato_perfil` DISABLE KEYS */;
INSERT INTO `candidato_perfil` VALUES ('ana.perez@ejemplo.com','Desarrollador Backend',3,2,3,'Laravel, MySQL, Git, API REST','Backender con foco en Laravel y MySQL. Buenas prácticas y control de versiones.',3,'SENA',2,2,1,1),('catalina.rios@sena.test','Desarrollador Backend',3,2,3,'Laravel, MySQL, Git, API REST','Desarrolladora enfocada en Laravel/MySQL. Buenas prácticas y control de versiones.',3,'SENA',2,2,1,1);
/*!40000 ALTER TABLE `candidato_perfil` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-10-30 19:37:29
