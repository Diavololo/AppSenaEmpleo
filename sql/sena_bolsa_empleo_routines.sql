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
-- Temporary view structure for view `vw_postulacion_resumen`
--

DROP TABLE IF EXISTS `vw_postulacion_resumen`;
/*!50001 DROP VIEW IF EXISTS `vw_postulacion_resumen`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_postulacion_resumen` AS SELECT 
 1 AS `id`,
 1 AS `vacante_id`,
 1 AS `vacante`,
 1 AS `empresa_id`,
 1 AS `empresa`,
 1 AS `candidato_email`,
 1 AS `candidato`,
 1 AS `estado`,
 1 AS `match_score`,
 1 AS `aplicada_at`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `vw_empresa_resumen`
--

DROP TABLE IF EXISTS `vw_empresa_resumen`;
/*!50001 DROP VIEW IF EXISTS `vw_empresa_resumen`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_empresa_resumen` AS SELECT 
 1 AS `id`,
 1 AS `nit`,
 1 AS `razon_social`,
 1 AS `nombre_publico`,
 1 AS `sector`,
 1 AS `tamano`,
 1 AS `ciudad`,
 1 AS `sitio_web`,
 1 AS `email_contacto`,
 1 AS `verificada`,
 1 AS `estado`,
 1 AS `logo_url`,
 1 AS `portada_url`,
 1 AS `created_at`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `vw_candidato_resumen`
--

DROP TABLE IF EXISTS `vw_candidato_resumen`;
/*!50001 DROP VIEW IF EXISTS `vw_candidato_resumen`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_candidato_resumen` AS SELECT 
 1 AS `email`,
 1 AS `nombre_completo`,
 1 AS `telefono`,
 1 AS `ciudad`,
 1 AS `created_at`,
 1 AS `rol_deseado`,
 1 AS `area`,
 1 AS `nivel`,
 1 AS `modalidad`,
 1 AS `habilidades`,
 1 AS `anios_experiencia`,
 1 AS `nivel_estudio`,
 1 AS `disponibilidad`,
 1 AS `contrato_preferido`,
 1 AS `visible_empresas`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `vw_vacante_resumen`
--

DROP TABLE IF EXISTS `vw_vacante_resumen`;
/*!50001 DROP VIEW IF EXISTS `vw_vacante_resumen`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_vacante_resumen` AS SELECT 
 1 AS `id`,
 1 AS `titulo`,
 1 AS `empresa`,
 1 AS `ciudad`,
 1 AS `area`,
 1 AS `nivel`,
 1 AS `modalidad`,
 1 AS `contrato`,
 1 AS `salario_min`,
 1 AS `salario_max`,
 1 AS `moneda`,
 1 AS `estado`,
 1 AS `publicada_at`,
 1 AS `etiquetas`*/;
SET character_set_client = @saved_cs_client;

--
-- Final view structure for view `vw_postulacion_resumen`
--

/*!50001 DROP VIEW IF EXISTS `vw_postulacion_resumen`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_postulacion_resumen` AS select `p`.`id` AS `id`,`p`.`vacante_id` AS `vacante_id`,`v`.`titulo` AS `vacante`,`v`.`empresa_id` AS `empresa_id`,`e`.`razon_social` AS `empresa`,`p`.`candidato_email` AS `candidato_email`,concat(`c`.`nombres`,' ',`c`.`apellidos`) AS `candidato`,`p`.`estado` AS `estado`,`p`.`match_score` AS `match_score`,`p`.`aplicada_at` AS `aplicada_at` from (((`postulaciones` `p` join `vacantes` `v` on((`v`.`id` = `p`.`vacante_id`))) join `empresas` `e` on((`e`.`id` = `v`.`empresa_id`))) join `candidatos` `c` on((`c`.`email` = `p`.`candidato_email`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_empresa_resumen`
--

/*!50001 DROP VIEW IF EXISTS `vw_empresa_resumen`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_empresa_resumen` AS select `e`.`id` AS `id`,`e`.`nit` AS `nit`,`e`.`razon_social` AS `razon_social`,coalesce(`e`.`nombre_comercial`,`e`.`razon_social`) AS `nombre_publico`,`s`.`nombre` AS `sector`,`t`.`nombre` AS `tamano`,`e`.`ciudad` AS `ciudad`,`e`.`sitio_web` AS `sitio_web`,`e`.`email_contacto` AS `email_contacto`,`e`.`verificada` AS `verificada`,`e`.`estado` AS `estado`,`e`.`logo_url` AS `logo_url`,`e`.`portada_url` AS `portada_url`,`e`.`created_at` AS `created_at` from ((`empresas` `e` left join `sectores` `s` on((`s`.`id` = `e`.`sector_id`))) left join `tamanos_empresa` `t` on((`t`.`id` = `e`.`tamano_id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_candidato_resumen`
--

/*!50001 DROP VIEW IF EXISTS `vw_candidato_resumen`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_candidato_resumen` AS select `c`.`email` AS `email`,concat(`c`.`nombres`,' ',`c`.`apellidos`) AS `nombre_completo`,`c`.`telefono` AS `telefono`,`c`.`ciudad` AS `ciudad`,`c`.`created_at` AS `created_at`,`cp`.`rol_deseado` AS `rol_deseado`,`a`.`nombre` AS `area`,`n`.`nombre` AS `nivel`,`m`.`nombre` AS `modalidad`,`cp`.`habilidades` AS `habilidades`,`cp`.`anios_experiencia` AS `anios_experiencia`,`ne`.`nombre` AS `nivel_estudio`,`d`.`nombre` AS `disponibilidad`,`co`.`nombre` AS `contrato_preferido`,`cp`.`visible_empresas` AS `visible_empresas` from (((((((`candidatos` `c` join `candidato_perfil` `cp` on((`cp`.`email` = `c`.`email`))) join `areas` `a` on((`a`.`id` = `cp`.`area_id`))) join `niveles` `n` on((`n`.`id` = `cp`.`nivel_id`))) join `modalidades` `m` on((`m`.`id` = `cp`.`modalidad_id`))) join `niveles_estudio` `ne` on((`ne`.`id` = `cp`.`estudios_id`))) join `disponibilidades` `d` on((`d`.`id` = `cp`.`disponibilidad_id`))) left join `contratos` `co` on((`co`.`id` = `cp`.`contrato_pref_id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_vacante_resumen`
--

/*!50001 DROP VIEW IF EXISTS `vw_vacante_resumen`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_vacante_resumen` AS select `v`.`id` AS `id`,`v`.`titulo` AS `titulo`,`e`.`razon_social` AS `empresa`,`v`.`ciudad` AS `ciudad`,`a`.`nombre` AS `area`,`n`.`nombre` AS `nivel`,`m`.`nombre` AS `modalidad`,`c`.`nombre` AS `contrato`,`v`.`salario_min` AS `salario_min`,`v`.`salario_max` AS `salario_max`,`v`.`moneda` AS `moneda`,`v`.`estado` AS `estado`,`v`.`publicada_at` AS `publicada_at`,`v`.`etiquetas` AS `etiquetas` from (((((`vacantes` `v` join `empresas` `e` on((`e`.`id` = `v`.`empresa_id`))) left join `areas` `a` on((`a`.`id` = `v`.`area_id`))) left join `niveles` `n` on((`n`.`id` = `v`.`nivel_id`))) left join `modalidades` `m` on((`m`.`id` = `v`.`modalidad_id`))) left join `contratos` `c` on((`c`.`id` = `v`.`tipo_contrato_id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Dumping events for database 'sena_bolsa_empleo'
--

--
-- Dumping routines for database 'sena_bolsa_empleo'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-10-30 19:37:30
