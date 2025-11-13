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
-- Table structure for table `empresas`
--

DROP TABLE IF EXISTS `empresas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `empresas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nit` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `razon_social` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre_comercial` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sector_id` tinyint unsigned DEFAULT NULL,
  `tamano_id` tinyint unsigned DEFAULT NULL,
  `sitio_web` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_contacto` varchar(254) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ciudad` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `logo_url` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `portada_url` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linkedin_url` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `facebook_url` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `instagram_url` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verificada` tinyint(1) NOT NULL DEFAULT '0',
  `estado` enum('activa','bloqueada','pendiente') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nit` (`nit`),
  KEY `fk_emp_sector` (`sector_id`),
  KEY `fk_emp_tamano` (`tamano_id`),
  KEY `idx_emp_ciudad` (`ciudad`),
  KEY `idx_emp_estado` (`estado`),
  CONSTRAINT `fk_emp_sector` FOREIGN KEY (`sector_id`) REFERENCES `sectores` (`id`),
  CONSTRAINT `fk_emp_tamano` FOREIGN KEY (`tamano_id`) REFERENCES `tamanos_empresa` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empresas`
--

LOCK TABLES `empresas` WRITE;
/*!40000 ALTER TABLE `empresas` DISABLE KEYS */;
INSERT INTO `empresas` VALUES (1,'900123456','DevAndes S.A.S','DevAndes',1,2,'https://devandes.co',NULL,'talento@devandes.co','Bogotá',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'activa','2025-10-29 19:56:41','2025-10-29 19:56:41'),(2,'901234567-8','GreenTech S.A.S','GreenTech',NULL,NULL,'https://greentech.co',NULL,'contacto@greentech.co','Bogotá',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'activa','2025-10-29 21:19:42','2025-10-29 21:19:42');
/*!40000 ALTER TABLE `empresas` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-10-30 19:37:28
