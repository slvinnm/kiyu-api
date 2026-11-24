-- MySQL dump 10.13  Distrib 9.7.4, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: kiyu
-- ------------------------------------------------------
-- Server version	9.7.4

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `platform_user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `metadata` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_tenant_id_foreign` (`tenant_id`),
  KEY `audit_logs_platform_user_id_foreign` (`platform_user_id`),
  KEY `audit_logs_user_id_index` (`user_id`),
  KEY `audit_logs_action_index` (`action`),
  KEY `audit_logs_entity_type_index` (`entity_type`),
  KEY `audit_logs_entity_id_index` (`entity_id`),
  KEY `audit_logs_created_at_index` (`created_at`),
  CONSTRAINT `audit_logs_platform_user_id_foreign` FOREIGN KEY (`platform_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_logs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,1,NULL,NULL,'PHYSICAL_INTAKE_CREATED','QueueSession','1','{\"channel\": \"MANUAL\", \"queue_number\": \"R001\", \"session_number\": \"V-20261124-0001\"}',NULL,'2026-11-24 04:31:39','2026-11-24 04:31:39'),(2,1,4,NULL,'RECEPTION_CALL_NEXT','QueueTicket','1','{\"station_id\": 1, \"queue_number\": \"R001\", \"assigned_to_user_id\": 4}',NULL,'2026-11-24 04:32:03','2026-11-24 04:32:03');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `billing_webhook_events`
--

DROP TABLE IF EXISTS `billing_webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_webhook_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `error` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billing_webhook_events_provider_event_id_unique` (`provider`,`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing_webhook_events`
--

LOCK TABLES `billing_webhook_events` WRITE;
/*!40000 ALTER TABLE `billing_webhook_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `billing_webhook_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
INSERT INTO `cache` VALUES ('laravel-cache-5c785c036466adea360111aa28563bfd556b5fba','i:2;',1795527607),('laravel-cache-5c785c036466adea360111aa28563bfd556b5fba:timer','i:1795527607;',1795527607);
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customers_user_id_foreign` (`user_id`),
  KEY `customers_tenant_id_user_id_index` (`tenant_id`,`user_id`),
  CONSTRAINT `customers_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `customers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES (1,1,NULL,'sas',NULL,NULL,NULL,NULL,NULL,NULL),(2,1,6,'Sample Client',NULL,'client@kiyu.id',NULL,'2026-11-24 06:27:40','2026-11-24 06:27:40',NULL);
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `departments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `facility_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `display_in_kiosk` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `departments_facility_id_code_unique` (`facility_id`,`code`),
  CONSTRAINT `departments_facility_id_foreign` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (1,1,'GEN','General Service',1,1,'2026-11-24 01:45:25','2026-11-24 01:45:25'),(2,1,'SUP','Support Service',1,1,'2026-11-24 01:45:25','2026-11-24 01:45:25');
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `display_devices`
--

DROP TABLE IF EXISTS `display_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `display_devices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `facility_id` bigint unsigned NOT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `credential_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pairing_code_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pairing_expires_at` timestamp NULL DEFAULT NULL,
  `paired_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `display_devices_credential_hash_unique` (`credential_hash`),
  UNIQUE KEY `display_devices_pairing_code_hash_unique` (`pairing_code_hash`),
  KEY `display_devices_facility_id_foreign` (`facility_id`),
  KEY `display_devices_department_id_foreign` (`department_id`),
  KEY `display_devices_tenant_id_facility_id_index` (`tenant_id`,`facility_id`),
  CONSTRAINT `display_devices_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `display_devices_facility_id_foreign` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `display_devices_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `display_devices`
--

LOCK TABLES `display_devices` WRITE;
/*!40000 ALTER TABLE `display_devices` DISABLE KEYS */;
/*!40000 ALTER TABLE `display_devices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facilities`
--

DROP TABLE IF EXISTS `facilities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facilities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timezone` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `facilities_tenant_id_code_unique` (`tenant_id`,`code`),
  UNIQUE KEY `facilities_id_tenant_id_unique` (`id`,`tenant_id`),
  CONSTRAINT `facilities_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facilities`
--

LOCK TABLES `facilities` WRITE;
/*!40000 ALTER TABLE `facilities` DISABLE KEYS */;
INSERT INTO `facilities` VALUES (1,1,'KIYU Demo Facility','MAIN','Jl. Sudirman No. 1, Jakarta',NULL,NULL,1,'2026-11-24 01:45:25','2026-11-24 01:45:25');
/*!40000 ALTER TABLE `facilities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `kiosk_devices`
--

DROP TABLE IF EXISTS `kiosk_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kiosk_devices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `facility_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `credential_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pairing_code_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pairing_expires_at` timestamp NULL DEFAULT NULL,
  `paired_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kiosk_devices_credential_hash_unique` (`credential_hash`),
  UNIQUE KEY `kiosk_devices_pairing_code_hash_unique` (`pairing_code_hash`),
  KEY `kiosk_devices_facility_id_foreign` (`facility_id`),
  KEY `kiosk_devices_tenant_id_facility_id_index` (`tenant_id`,`facility_id`),
  CONSTRAINT `kiosk_devices_facility_id_foreign` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `kiosk_devices_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kiosk_devices`
--

LOCK TABLES `kiosk_devices` WRITE;
/*!40000 ALTER TABLE `kiosk_devices` DISABLE KEYS */;
INSERT INTO `kiosk_devices` VALUES (1,1,1,'Demo Kiosk','507a8eaf10dd8bd65d7e1896a112db89b3d038ec1806abd7a7a77d868b3b5955',NULL,NULL,'2026-11-24 01:45:25',1,NULL,NULL,'2026-11-24 01:45:25','2026-11-24 01:45:25');
/*!40000 ALTER TABLE `kiosk_devices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_10_28_051217_create_personal_access_tokens_table',1),(5,'2026_10_28_060000_create_kiyu_domain_schema',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subscription_id` bigint unsigned NOT NULL,
  `provider` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_transaction_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` int NOT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_provider_provider_transaction_id_unique` (`provider`,`provider_transaction_id`),
  KEY `payments_subscription_id_foreign` (`subscription_id`),
  CONSTRAINT `payments_subscription_id_foreign` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
INSERT INTO `personal_access_tokens` VALUES (1,'App\\Models\\User',1,'web','26672b7b24a718a090665cfa57e780bf6d06bdb403c6c54cf6a0e9892f05e25b','[\"*\"]','2026-11-24 04:25:45','2026-11-24 12:23:57','2026-11-24 04:23:57','2026-11-24 04:25:45'),(4,'App\\Models\\User',4,'web','728a1ac0fc18850e7726b2620d8afb783f4cf8eee51304dc2faf2cb6265e36dc','[\"*\"]','2026-11-24 04:32:47','2026-11-24 12:30:39','2026-11-24 04:30:39','2026-11-24 04:32:47'),(6,'App\\Models\\User',4,'web','1c014ff9d66bdd91fd4eed36ce4388dd59dbf2764b210a2c825369a5d05e9bd2','[\"*\"]','2026-11-24 05:12:34','2026-11-24 13:12:32','2026-11-24 05:12:32','2026-11-24 05:12:34'),(7,'App\\Models\\User',4,'web','4ee947aa038ca656d306471b4822a38d15e3bf238254e970ffca6f9f4e1b9270','[\"*\"]','2026-11-24 05:13:55','2026-11-24 13:13:54','2026-11-24 05:13:54','2026-11-24 05:13:55'),(8,'App\\Models\\User',4,'web','ab5d1a98f88cfcc3706605270e006ed370bd62b8dcc4cc2d1418ac0dc86f8f03','[\"*\"]','2026-11-24 05:14:43','2026-11-24 13:14:42','2026-11-24 05:14:42','2026-11-24 05:14:43'),(9,'App\\Models\\User',4,'web','f3f1f0167ff9f9cc34ac71094b08257777c80201126b9fc02129e1705fa2a14f','[\"*\"]','2026-11-24 05:16:29','2026-11-24 13:16:29','2026-11-24 05:16:29','2026-11-24 05:16:29'),(14,'App\\Models\\User',2,'web','1b40b45ec9c451e1b8725740c46553facde1593534365cb14da018d98c9dcf6f','[\"*\"]','2026-11-24 05:46:01','2026-11-24 13:46:00','2026-11-24 05:46:00','2026-11-24 05:46:01'),(19,'App\\Models\\User',6,'web','7c71b541330d3365a0c762eacc39ce9f834884d695dd5d1a900052fe3db045ce','[\"*\"]','2026-11-24 06:28:00','2026-11-24 14:27:31','2026-11-24 06:27:31','2026-11-24 06:28:00'),(21,'App\\Models\\User',4,'web','ab0ef91dd8188b4fc78d8800d1ef3530c7f6a891dd464c4605127572ae4b8b69','[\"*\"]','2026-11-24 06:39:08','2026-11-24 14:39:08','2026-11-24 06:39:08','2026-11-24 06:39:08'),(22,'App\\Models\\User',4,'web','1e823969ef6e96b594764e1beece3353e395076a95dd83ec7a3d2c1108f64d47','[\"*\"]','2026-11-24 07:02:58','2026-11-24 14:39:51','2026-11-24 06:39:51','2026-11-24 07:02:58');
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plan_entitlements`
--

DROP TABLE IF EXISTS `plan_entitlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_entitlements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` bigint unsigned NOT NULL,
  `feature_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plan_entitlements_plan_id_feature_code_unique` (`plan_id`,`feature_code`),
  CONSTRAINT `plan_entitlements_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plan_entitlements`
--

LOCK TABLES `plan_entitlements` WRITE;
/*!40000 ALTER TABLE `plan_entitlements` DISABLE KEYS */;
INSERT INTO `plan_entitlements` VALUES (1,1,'queue_management',NULL,'2026-11-24 01:45:27','2026-11-24 01:45:27'),(2,1,'max_facilities','{\"limit\": 1}','2026-11-24 01:45:27','2026-11-24 01:45:27'),(3,1,'max_staff','{\"limit\": 5}','2026-11-24 01:45:27','2026-11-24 01:45:27'),(4,1,'max_kiosk_devices','{\"limit\": 1}','2026-11-24 01:45:27','2026-11-24 01:45:27'),(5,1,'max_display_devices','{\"limit\": 1}','2026-11-24 01:45:27','2026-11-24 01:45:27'),(6,2,'queue_management',NULL,'2026-11-24 01:45:27','2026-11-24 01:45:27'),(7,2,'basic_reports',NULL,'2026-11-24 01:45:27','2026-11-24 01:45:27'),(8,2,'whatsapp_notifications',NULL,'2026-11-24 01:45:27','2026-11-24 01:45:27'),(9,2,'max_facilities','{\"limit\": 1}','2026-11-24 01:45:27','2026-11-24 01:45:27'),(10,2,'max_staff','{\"limit\": 10}','2026-11-24 01:45:27','2026-11-24 01:45:27'),(11,2,'max_kiosk_devices','{\"limit\": 2}','2026-11-24 01:45:27','2026-11-24 01:45:27'),(12,2,'max_display_devices','{\"limit\": 2}','2026-11-24 01:45:27','2026-11-24 01:45:27'),(13,3,'queue_management',NULL,'2026-11-24 01:45:27','2026-11-24 01:45:27'),(14,3,'basic_reports',NULL,'2026-11-24 01:45:27','2026-11-24 01:45:27'),(15,3,'advanced_reports',NULL,'2026-11-24 01:45:27','2026-11-24 01:45:27'),(16,3,'whatsapp_notifications',NULL,'2026-11-24 01:45:27','2026-11-24 01:45:27'),(17,3,'max_facilities','{\"limit\": 5}','2026-11-24 01:45:27','2026-11-24 01:45:27'),(18,3,'max_staff','{\"limit\": 50}','2026-11-24 01:45:27','2026-11-24 01:45:27'),(19,3,'max_kiosk_devices','{\"limit\": 10}','2026-11-24 01:45:27','2026-11-24 01:45:27'),(20,3,'max_display_devices','{\"limit\": 10}','2026-11-24 01:45:27','2026-11-24 01:45:27');
/*!40000 ALTER TABLE `plan_entitlements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plan_prices`
--

DROP TABLE IF EXISTS `plan_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_prices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` bigint unsigned NOT NULL,
  `billing_interval` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` int NOT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `provider_price_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plan_prices_plan_id_billing_interval_currency_provider_unique` (`plan_id`,`billing_interval`,`currency`,`provider`),
  CONSTRAINT `plan_prices_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plan_prices`
--

LOCK TABLES `plan_prices` WRITE;
/*!40000 ALTER TABLE `plan_prices` DISABLE KEYS */;
INSERT INTO `plan_prices` VALUES (1,2,'MONTHLY',15000000,'IDR','',NULL,1,'2026-11-24 01:45:27','2026-11-24 01:45:27'),(2,2,'YEARLY',150000000,'IDR','',NULL,1,'2026-11-24 01:45:27','2026-11-24 01:45:27'),(3,3,'MONTHLY',40000000,'IDR','',NULL,1,'2026-11-24 01:45:27','2026-11-24 01:45:27'),(4,3,'YEARLY',400000000,'IDR','',NULL,1,'2026-11-24 01:45:27','2026-11-24 01:45:27');
/*!40000 ALTER TABLE `plan_prices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plans`
--

DROP TABLE IF EXISTS `plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plans_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plans`
--

LOCK TABLES `plans` WRITE;
/*!40000 ALTER TABLE `plans` DISABLE KEYS */;
INSERT INTO `plans` VALUES (1,'TRIAL','Trial','Time-limited trial of the core queue features.',1,'2026-11-24 01:45:27','2026-11-24 01:45:27'),(2,'STANDARD','Standard','Core queue management for a single facility.',1,'2026-11-24 01:45:27','2026-11-24 01:45:27'),(3,'MULTI_FACILITY','Multi Facility','Multiple facilities, more staff and advanced reporting.',1,'2026-11-24 01:45:27','2026-11-24 01:45:27');
/*!40000 ALTER TABLE `plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `queue_acquisitions`
--

DROP TABLE IF EXISTS `queue_acquisitions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `queue_acquisitions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue_session_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `department_id` bigint unsigned NOT NULL,
  `channel` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACQUIRED',
  `idempotency_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registered_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `queue_acquisitions_tenant_id_idempotency_key_unique` (`tenant_id`,`idempotency_key`),
  KEY `queue_acquisitions_queue_session_id_foreign` (`queue_session_id`),
  KEY `queue_acquisitions_registered_by_user_id_foreign` (`registered_by_user_id`),
  KEY `queue_acquisitions_department_id_status_index` (`department_id`,`status`),
  CONSTRAINT `queue_acquisitions_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `queue_acquisitions_queue_session_id_foreign` FOREIGN KEY (`queue_session_id`) REFERENCES `queue_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `queue_acquisitions_registered_by_user_id_foreign` FOREIGN KEY (`registered_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `queue_acquisitions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `queue_acquisitions`
--

LOCK TABLES `queue_acquisitions` WRITE;
/*!40000 ALTER TABLE `queue_acquisitions` DISABLE KEYS */;
INSERT INTO `queue_acquisitions` VALUES (1,1,1,1,'MANUAL','ACQUIRED','eb8f0f66-96da-4f02-b48c-63ea278b8676',4,'2026-11-24 04:31:39','2026-11-24 04:31:39');
/*!40000 ALTER TABLE `queue_acquisitions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `queue_counters`
--

DROP TABLE IF EXISTS `queue_counters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `queue_counters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `station_id` bigint unsigned NOT NULL,
  `counter_date` date NOT NULL,
  `last_sequence` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `queue_counters_station_id_counter_date_unique` (`station_id`,`counter_date`),
  CONSTRAINT `queue_counters_station_id_foreign` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `queue_counters`
--

LOCK TABLES `queue_counters` WRITE;
/*!40000 ALTER TABLE `queue_counters` DISABLE KEYS */;
INSERT INTO `queue_counters` VALUES (1,1,'2026-11-24',1,NULL,'2026-11-24 04:31:39');
/*!40000 ALTER TABLE `queue_counters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `queue_events`
--

DROP TABLE IF EXISTS `queue_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `queue_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue_ticket_id` bigint unsigned NOT NULL,
  `event_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `queue_events_user_id_foreign` (`user_id`),
  KEY `queue_events_queue_ticket_id_created_at_index` (`queue_ticket_id`,`created_at`),
  CONSTRAINT `queue_events_queue_ticket_id_foreign` FOREIGN KEY (`queue_ticket_id`) REFERENCES `queue_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `queue_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `queue_events`
--

LOCK TABLES `queue_events` WRITE;
/*!40000 ALTER TABLE `queue_events` DISABLE KEYS */;
INSERT INTO `queue_events` VALUES (1,1,'CREATED',NULL,'CREATED',NULL,'{\"channel\": \"MANUAL\"}','2026-11-24 04:31:39','2026-11-24 04:31:39'),(2,1,'CALLED','CREATED','CALLED',4,NULL,'2026-11-24 04:32:03','2026-11-24 04:32:03');
/*!40000 ALTER TABLE `queue_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `queue_session_counters`
--

DROP TABLE IF EXISTS `queue_session_counters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `queue_session_counters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `facility_id` bigint unsigned NOT NULL,
  `counter_date` date NOT NULL,
  `last_sequence` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `queue_session_counters_facility_id_counter_date_unique` (`facility_id`,`counter_date`),
  CONSTRAINT `queue_session_counters_facility_id_foreign` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `queue_session_counters`
--

LOCK TABLES `queue_session_counters` WRITE;
/*!40000 ALTER TABLE `queue_session_counters` DISABLE KEYS */;
INSERT INTO `queue_session_counters` VALUES (1,1,'2026-11-24',1,NULL,'2026-11-24 04:31:39');
/*!40000 ALTER TABLE `queue_session_counters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `queue_sessions`
--

DROP TABLE IF EXISTS `queue_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `queue_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `facility_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned DEFAULT NULL,
  `department_id` bigint unsigned NOT NULL,
  `workflow_id` bigint unsigned NOT NULL,
  `session_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tracking_token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` tinyint NOT NULL DEFAULT '1',
  `intake_channel` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAITING',
  `online_active_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registered_by_user_id` bigint unsigned DEFAULT NULL,
  `checked_in_at` timestamp NULL DEFAULT NULL,
  `registered_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `queue_sessions_facility_id_session_number_unique` (`facility_id`,`session_number`),
  UNIQUE KEY `queue_sessions_tracking_token_unique` (`tracking_token`),
  KEY `queue_sessions_customer_id_foreign` (`customer_id`),
  KEY `queue_sessions_workflow_id_foreign` (`workflow_id`),
  KEY `queue_sessions_registered_by_user_id_foreign` (`registered_by_user_id`),
  KEY `queue_sessions_tenant_id_facility_id_created_at_index` (`tenant_id`,`facility_id`,`created_at`),
  KEY `queue_sessions_department_id_status_index` (`department_id`,`status`),
  KEY `queue_sessions_online_active_key_index` (`online_active_key`),
  CONSTRAINT `queue_sessions_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `queue_sessions_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `queue_sessions_facility_id_foreign` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `queue_sessions_registered_by_user_id_foreign` FOREIGN KEY (`registered_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `queue_sessions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `queue_sessions_workflow_id_foreign` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `queue_sessions`
--

LOCK TABLES `queue_sessions` WRITE;
/*!40000 ALTER TABLE `queue_sessions` DISABLE KEYS */;
INSERT INTO `queue_sessions` VALUES (1,1,1,NULL,1,1,'V-20261124-0001','ae43a524-5b89-4e6f-96e9-67b684f37714',1,'MANUAL','WAITING',NULL,4,'2026-11-24 04:31:39',NULL,NULL,'2026-11-24 04:31:39','2026-11-24 04:31:39',NULL);
/*!40000 ALTER TABLE `queue_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `queue_tickets`
--

DROP TABLE IF EXISTS `queue_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `queue_tickets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue_session_id` bigint unsigned NOT NULL,
  `workflow_step_id` bigint unsigned NOT NULL,
  `station_id` bigint unsigned NOT NULL,
  `queue_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` tinyint NOT NULL DEFAULT '1',
  `internal_sequence` int NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CREATED',
  `assigned_to_user_id` bigint unsigned DEFAULT NULL,
  `transferred_from_ticket_id` bigint unsigned DEFAULT NULL,
  `transferred_to_station_id` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `called_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `queue_tickets_workflow_step_id_foreign` (`workflow_step_id`),
  KEY `queue_tickets_assigned_to_user_id_foreign` (`assigned_to_user_id`),
  KEY `queue_tickets_transferred_from_ticket_id_foreign` (`transferred_from_ticket_id`),
  KEY `queue_tickets_transferred_to_station_id_foreign` (`transferred_to_station_id`),
  KEY `idx_station_queue_selection` (`station_id`,`status`,`priority`,`internal_sequence`),
  KEY `queue_tickets_queue_session_id_status_index` (`queue_session_id`,`status`),
  CONSTRAINT `queue_tickets_assigned_to_user_id_foreign` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `queue_tickets_queue_session_id_foreign` FOREIGN KEY (`queue_session_id`) REFERENCES `queue_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `queue_tickets_station_id_foreign` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `queue_tickets_transferred_from_ticket_id_foreign` FOREIGN KEY (`transferred_from_ticket_id`) REFERENCES `queue_tickets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `queue_tickets_transferred_to_station_id_foreign` FOREIGN KEY (`transferred_to_station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `queue_tickets_workflow_step_id_foreign` FOREIGN KEY (`workflow_step_id`) REFERENCES `workflow_steps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `queue_tickets`
--

LOCK TABLES `queue_tickets` WRITE;
/*!40000 ALTER TABLE `queue_tickets` DISABLE KEYS */;
INSERT INTO `queue_tickets` VALUES (1,1,1,1,'R001',1,1,'CALLED',4,NULL,NULL,NULL,'2026-11-24 04:32:03',NULL,NULL,'2026-11-24 04:31:39','2026-11-24 04:32:03');
/*!40000 ALTER TABLE `queue_tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_transfers`
--

DROP TABLE IF EXISTS `service_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_transfers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `source_queue_session_id` bigint unsigned NOT NULL,
  `target_department_id` bigint unsigned NOT NULL,
  `target_queue_session_id` bigint unsigned DEFAULT NULL,
  `referred_by_user_id` bigint unsigned DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `priority` tinyint NOT NULL DEFAULT '1',
  `reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_transfers_tenant_id_foreign` (`tenant_id`),
  KEY `service_transfers_source_queue_session_id_foreign` (`source_queue_session_id`),
  KEY `service_transfers_target_department_id_foreign` (`target_department_id`),
  KEY `service_transfers_target_queue_session_id_foreign` (`target_queue_session_id`),
  KEY `service_transfers_referred_by_user_id_foreign` (`referred_by_user_id`),
  CONSTRAINT `service_transfers_referred_by_user_id_foreign` FOREIGN KEY (`referred_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_transfers_source_queue_session_id_foreign` FOREIGN KEY (`source_queue_session_id`) REFERENCES `queue_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_transfers_target_department_id_foreign` FOREIGN KEY (`target_department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_transfers_target_queue_session_id_foreign` FOREIGN KEY (`target_queue_session_id`) REFERENCES `queue_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_transfers_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_transfers`
--

LOCK TABLES `service_transfers` WRITE;
/*!40000 ALTER TABLE `service_transfers` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('0TJZgISf0UQUdbz07ag1f4agF4C04ygFyGCCHI6u',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJXeEJSTzRHb21DUUdJZUVoNUZxNXpaVnJaRlB0TnVpcTBOaEN0QWtaIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHBzOlwvXC9hcHAua2l5dS50ZXN0Iiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1795557803),('1cHlOc3HfdiYTADaBhcVx1ZJfT3Gc7nupcqzeTSs',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJWQkdvTVF4QXljVXZxY1dUMjdFRGpNR3J4SDBiZm85dUJsTEoxTVBKIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2FwaS5raXl1LnRlc3QiLCJyb3V0ZSI6bnVsbH0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1795522020),('1JLTBvX0gFUgXgiAHah7Phf6WdylUHPL9dMFB0RK',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/158.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJrVHZCUEpwMWE1dmdONGF2N2dsQnBjYlJrandZZ2czWnVRTjNLNEN5IiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2RlbW8ua2l5dS50ZXN0Iiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1795557684),('1Y2NJG7DoPzKOzfhxpAaEJHHGI5HRJWXGobN5YVg',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiIxTGdmVExvNXZjQjBHNmhRbVlMSE94eFVMZVNMQmVKZ3FYa0Q2RElMIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2FwaS5raXl1LnRlc3QiLCJyb3V0ZSI6bnVsbH0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1795521416),('2yeZmKPe5VEqtikw23Mai0F8kAUwWG5AEPflTJL3',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiIzZ090enJaQUpqRUhOT0VSdnpzM0c5SjFLZk1FSXZ1bzlSaGJ3R2gyIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2FwaS5raXl1LnRlc3QiLCJyb3V0ZSI6bnVsbH0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1795558025),('Aj3BkJjbN9CWu81DrwPsGPSzl8TEDITv9IoKAcC2',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJUN3A5dklLRnRzTEUwQTdQRGE5WnJPV3VVMTRzSlcyQmthZ0Rvbkg4IiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHBzOlwvXC9raXl1LnRlc3QiLCJyb3V0ZSI6bnVsbH0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1795557803),('blj0ME0vSNk3b8fNbJL7CSfb116pMAfHayY3EQFd',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJVSURpdFN4cFFWOW9QZE9iQTF1b3VpNThzbEVwQTVYZE5kdklZaFlaIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHBzOlwvXC9hcHAua2l5dS50ZXN0Iiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1795557791),('bmDKcMEyavX7KC2c1cejPRNPbACSF8cG4Xa7d27E',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiIyZG1VY1JHek5qeVRQemlmd0h4Z044TmlxbGlTb1VnM0VJOTRyOEhMIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2FwaS5raXl1LnRlc3QiLCJyb3V0ZSI6bnVsbH0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1795557781),('c4Petn1pQ9nsOgAe4TIyFLgssUoaCCXmqxGczMJX',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJncVpONGpFZ3FIbm5tamJsajd6VmpVWFRRZWdxWlN2RTRSQld1UDhaIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2FwaS5raXl1LnRlc3QiLCJyb3V0ZSI6bnVsbH0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1795558077),('cTtK0SR3xxYxTOoB7Vwp7OuAAgu9agqd20meAhmm',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJhUnVOdlBiS0paRHVjTWhDMFNoTEFxdjlvUTVhdnQ1UEV6OEpjU3FHIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHBzOlwvXC9raXl1LnRlc3QiLCJyb3V0ZSI6bnVsbH0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1795557791),('CVMIId0eAQFkV1XD0qlt8MQvIjLfNKqQ7Geu50WN',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJQQ2ZBY0hMVVRwNElWVW9PeExpbDFyc3A3UlFhUWE2ZjlRbEsyYXpTIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2tpeXUudGVzdCIsInJvdXRlIjpudWxsfSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1795557874),('Gtvej9P5gr0cnrFLK0ITGqgnistF3OnBwRq3x2LR',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/158.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJ3Rm9wN0E1TzdWd1pZYUpBWGlpQWN3MXNJTjRpdlEwVlI1d2x5QXFYIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2FwaS5raXl1LnRlc3QiLCJyb3V0ZSI6bnVsbH0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1795557662),('Gydv62ZQg6gO3oaxT7ypyrQNFsgIYA9lxIb61aNy',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJoMlBDYjR3ZGl4Zko1OFlEZjg3R0ltUU1MdDEwMkZ2d3ZpUUlpTGxqIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2FjbWUua2l5dS50ZXN0XC8/Y2I9dGVzdDEiLCJyb3V0ZSI6bnVsbH0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1795557850),('H9jrpB8dG3qUpiyE4aySiiJbRTOuPCsrNEu1hcqX',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiI5cUxLd0JhekpYSmJrb3d1cUl3ZWNjZU50RGJ4ZThKQmRMOVVCNjQ3IiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2tpeXUudGVzdCIsInJvdXRlIjpudWxsfSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1795520439),('hJMlcsCjluqFvbMVqufRNa3EsgbTKAC89aoaEqZj',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/158.0.0.0 Safari/537.36','eyJfdG9rZW4iOiIxc0pPWVZWcHVUUGJVUkt4OEFUM01uSWpLMTZVYW0yR2VXSUNwNk9oIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2FwaS5raXl1LnRlc3QiLCJyb3V0ZSI6bnVsbH0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1795522175),('nvmthdPXukzOiOGxbfFWhENsMhWGXP8jIQy3UODg',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJIeG1mSFdZMlNGZDRvRDYxQnl1UGl0M1lDSDBEVmUyVW9WQ3pLRWVWIiwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1795520445),('pehrLKIdCbKGSWIbA6N5WGCB5zNmqL4tYPqjJRkF',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/158.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJ3NGduWHJndmFyeGRtcENUWHo0Z2RvcW1CbzBld0NvVGhkMWpiRHlDIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2FjbWUua2l5dS50ZXN0Iiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1795557675),('PlEa5oveKJMYFFGTYTH0pYF2N1Higbw9Kk3deRgM',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJjZlZ1dFpjNFFFSTYxdXEwN1BnbExod0cxa1NjNE1XY3c4bEcxWGFKIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2FjbWUua2l5dS50ZXN0Iiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1795557831),('prmk1NlvPgbCmJIIct4jsOCDLl5SMXkrSS8pMwri',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJIMkxTS2szWWlLTjEyZzBNRWd1ckdWa1EwSDA2SFJTRnY5QWsyNWdoIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2FjbWUua2l5dS50ZXN0Iiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1795557831),('QLPao42hkDQnrwPokRXkOjlnlaZcebMtQqTuGpet',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/158.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJocndxVjBkSGpISlJIY0M1azJpQnNraFFaZTJaVzN1d2JqYU9jU1czIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2tpeXUudGVzdCIsInJvdXRlIjpudWxsfSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1795557665),('QQMHcUW6Tz4m6f1uYIEmk86X4mm90AQ1T3X0fkOY',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJYNGY1eTloNTM1MXhEczZBMUI0Y0ZvOWd6dFFDakExS0gybzI5VzVIIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2FjbWUua2l5dS50ZXN0Iiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1795557875),('qRhVk8bBYi63X5V31iQZba17s82AIkL8k4twfqj5',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJkVm1pTWhFUWY5WmFlMFdvNTcxa21xN0xaZEcwVktmU2g4azBjblNFIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHBzOlwvXC9raXl1LnRlc3QiLCJyb3V0ZSI6bnVsbH0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1795557791),('QsMHtheZ7wmZ1MxrpEiD0hVIfZ3kjXYvdo2Yguk5',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiI5VDJZd1ZBeDhrVXlESE05dHFzMzZZNkRianpXcFRBOEh4UWo3amhyIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2tpeXUudGVzdCIsInJvdXRlIjpudWxsfSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1795520445),('SBjzse1v94Bijh7AgkIwIeFp2Y8dknyb5A3VkGjP',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJVYVpYTUdhZTNRMVR2TkE4dDZmeUVPekl3eXp3YzlFd05tU0ZyTXZYIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHBzOlwvXC9raXl1LnRlc3QiLCJyb3V0ZSI6bnVsbH0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfX0=',1795557803),('TNis0qHL8ljl0tiY0BC5wL5OmQV5phZzyvOkeNuL',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJCcVQyeUpHYTJzMGxSY1RpWVExb2FKcTdxTnRma052ME51T3Eyd2R2IiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2FjbWUua2l5dS50ZXN0Iiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1795557781),('v2WiujqwkHmrUlbF3jnq1Zsh0JmjCYKs2rF47xwo',NULL,'127.0.0.1','curl/8.23.0','eyJfdG9rZW4iOiJDTnI5Nkl5RGpPVk5KSzZoeE9POXRsYzVQc3paeWNHT3I5d3c2UmQxIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2tpeXUudGVzdCIsInJvdXRlIjpudWxsfSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1795557780),('YCRq9OCfNnp7lsXYq107a9HPlbVwXzTo5VJCiM6Y',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/158.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJINGRkVUNnbDZKWWlOOXZ1cnd3eUFUMklVOUFQRDN3MTR5WUp1TzZjIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2Fhc2FzLmtpeXUudGVzdCIsInJvdXRlIjpudWxsfSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1795557672);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stations`
--

DROP TABLE IF EXISTS `stations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue_prefix` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'A',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stations_department_id_code_unique` (`department_id`,`code`),
  CONSTRAINT `stations_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stations`
--

LOCK TABLES `stations` WRITE;
/*!40000 ALTER TABLE `stations` DISABLE KEYS */;
INSERT INTO `stations` VALUES (1,1,'Intake Counter','GEN-INT','INTAKE','R',1,'2026-11-24 01:45:25','2026-11-24 01:45:25'),(2,1,'Service Counter 1','GEN-SVC1','SERVICE','S1',1,'2026-11-24 01:45:25','2026-11-24 01:45:25'),(3,1,'Service Counter 2','GEN-SVC2','SERVICE','S2',1,'2026-11-24 01:45:25','2026-11-24 01:45:25'),(4,1,'Cashier','GEN-CSH','CASHIER','K',1,'2026-11-24 01:45:25','2026-11-24 01:45:25'),(5,2,'Support Intake','SUP-INT','INTAKE','P',1,'2026-11-24 01:45:25','2026-11-24 01:45:25'),(6,2,'Support Counter','SUP-SVC','SERVICE','PS',1,'2026-11-24 01:45:25','2026-11-24 01:45:25');
/*!40000 ALTER TABLE `stations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscriptions`
--

DROP TABLE IF EXISTS `subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscriptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `plan_id` bigint unsigned NOT NULL,
  `plan_price_id` bigint unsigned DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `billing_interval` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `provider_customer_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_subscription_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `current_period_start` timestamp NULL DEFAULT NULL,
  `current_period_end` timestamp NULL DEFAULT NULL,
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancel_at_period_end` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscriptions_plan_id_foreign` (`plan_id`),
  KEY `subscriptions_plan_price_id_foreign` (`plan_price_id`),
  KEY `subscriptions_tenant_id_status_index` (`tenant_id`,`status`),
  CONSTRAINT `subscriptions_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `subscriptions_plan_price_id_foreign` FOREIGN KEY (`plan_price_id`) REFERENCES `plan_prices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscriptions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscriptions`
--

LOCK TABLES `subscriptions` WRITE;
/*!40000 ALTER TABLE `subscriptions` DISABLE KEYS */;
INSERT INTO `subscriptions` VALUES (1,1,1,NULL,'TRIALING','MONTHLY','',NULL,NULL,'2026-11-24 01:45:27','2026-12-08 01:45:27','2026-12-08 01:45:27',NULL,0,'2026-11-24 01:45:27','2026-11-24 01:45:27');
/*!40000 ALTER TABLE `subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tenant_invitations`
--

DROP TABLE IF EXISTS `tenant_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_invitations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `facility_ids` json DEFAULT NULL,
  `invited_by_user_id` bigint unsigned DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_invitations_tenant_id_email_role_unique` (`tenant_id`,`email`,`role`),
  UNIQUE KEY `tenant_invitations_token_hash_unique` (`token_hash`),
  KEY `tenant_invitations_invited_by_user_id_foreign` (`invited_by_user_id`),
  KEY `tenant_invitations_tenant_id_email_index` (`tenant_id`,`email`),
  CONSTRAINT `tenant_invitations_invited_by_user_id_foreign` FOREIGN KEY (`invited_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_invitations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tenant_invitations`
--

LOCK TABLES `tenant_invitations` WRITE;
/*!40000 ALTER TABLE `tenant_invitations` DISABLE KEYS */;
/*!40000 ALTER TABLE `tenant_invitations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tenants`
--

DROP TABLE IF EXISTS `tenants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `billing_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timezone` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UTC',
  `allow_public_registration` tinyint(1) NOT NULL DEFAULT '0',
  `owner_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenants_slug_unique` (`slug`),
  KEY `tenants_owner_user_id_foreign` (`owner_user_id`),
  CONSTRAINT `tenants_owner_user_id_foreign` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tenants`
--

LOCK TABLES `tenants` WRITE;
/*!40000 ALTER TABLE `tenants` DISABLE KEYS */;
INSERT INTO `tenants` VALUES (1,'KIYU Demo Organization','acme','ACTIVE',NULL,NULL,'admin@kiyu.id',NULL,'Asia/Jakarta',1,2,'2026-11-24 01:45:25','2026-11-24 01:45:27');
/*!40000 ALTER TABLE `tenants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_facility_assignments`
--

DROP TABLE IF EXISTS `user_facility_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_facility_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `facility_id` bigint unsigned NOT NULL,
  `assigned_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_facility_assignments_tenant_id_user_id_facility_id_unique` (`tenant_id`,`user_id`,`facility_id`),
  KEY `user_facility_assignments_user_id_foreign` (`user_id`),
  KEY `user_facility_assignments_facility_id_foreign` (`facility_id`),
  KEY `user_facility_assignments_assigned_by_user_id_foreign` (`assigned_by_user_id`),
  KEY `user_facility_assignments_tenant_id_facility_id_index` (`tenant_id`,`facility_id`),
  CONSTRAINT `user_facility_assignments_assigned_by_user_id_foreign` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_facility_assignments_facility_id_foreign` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_facility_assignments_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `user_facility_assignments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_facility_assignments`
--

LOCK TABLES `user_facility_assignments` WRITE;
/*!40000 ALTER TABLE `user_facility_assignments` DISABLE KEYS */;
INSERT INTO `user_facility_assignments` VALUES (1,1,3,1,NULL,'2026-11-24 01:45:26','2026-11-24 01:45:26'),(2,1,4,1,NULL,'2026-11-24 01:45:26','2026-11-24 01:45:26'),(3,1,5,1,NULL,'2026-11-24 01:45:26','2026-11-24 01:45:26');
/*!40000 ALTER TABLE `user_facility_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `station_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_id_tenant_id_unique` (`id`,`tenant_id`),
  KEY `users_station_id_foreign` (`station_id`),
  KEY `users_tenant_id_role_index` (`tenant_id`,`role`),
  CONSTRAINT `users_station_id_foreign` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Platform Operator','platform@kiyu.id',NULL,'$2y$12$CqOIf0kJpL4nZg5UFP3H0.vOxlw4APlHjdzBtWxL1HBOqVrUcPqp6',NULL,NULL,'platform_admin',NULL,'2026-11-24 01:45:25','2026-11-24 01:45:25'),(2,'Tenant Administrator','admin@kiyu.id',NULL,'$2y$12$4808y4hELuBNhVuKtIayhuw6eGIHYg6ma8WZ2J/msQ.ZYhJabKS9q',NULL,1,'tenant_admin',NULL,'2026-11-24 01:45:26','2026-11-24 01:45:26'),(3,'Facility Administrator','facility@kiyu.id',NULL,'$2y$12$ZGn7xSO5zXoGnRupgt1rk./Oh6EvPkHPLLhdCCKtQA8s.JcSj78xu',NULL,1,'facility_admin',NULL,'2026-11-24 01:45:26','2026-11-24 01:45:26'),(4,'Front Desk Operator','operator@kiyu.id',NULL,'$2y$12$tqyHkdUsD81b4eDaTBEx1.iorxlS6N6Q5jsHD4Umdxx3e2b3uBL.a',NULL,1,'operator',1,'2026-11-24 01:45:26','2026-11-24 01:45:26'),(5,'Service Staff','staff@kiyu.id',NULL,'$2y$12$I5IzwkKQ6BsMC.ukrRKd/e6z1sGU53vHQ3AreimuRbrOvM3oCY82K',NULL,1,'staff',NULL,'2026-11-24 01:45:26','2026-11-24 01:45:26'),(6,'Sample Client','client@kiyu.id',NULL,'$2y$12$mXY0jeUiLgDvDkoKURSVSODc2nofQR.SusUk/7pGoKU4GfF6/z3gO',NULL,1,'client',NULL,'2026-11-24 01:45:27','2026-11-24 01:45:27');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `workflow_steps`
--

DROP TABLE IF EXISTS `workflow_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_steps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` bigint unsigned NOT NULL,
  `station_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sequence` int NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_optional` tinyint(1) NOT NULL DEFAULT '0',
  `can_skip` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_steps_workflow_id_sequence_unique` (`workflow_id`,`sequence`),
  KEY `workflow_steps_station_id_foreign` (`station_id`),
  CONSTRAINT `workflow_steps_station_id_foreign` FOREIGN KEY (`station_id`) REFERENCES `stations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `workflow_steps_workflow_id_foreign` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `workflow_steps`
--

LOCK TABLES `workflow_steps` WRITE;
/*!40000 ALTER TABLE `workflow_steps` DISABLE KEYS */;
INSERT INTO `workflow_steps` VALUES (1,1,1,'Intake',1,1,0,0,'2026-11-24 01:45:25','2026-11-24 01:45:25'),(2,1,2,'Service',2,1,0,0,'2026-11-24 01:45:25','2026-11-24 01:45:25'),(3,1,3,'Follow-up',3,1,0,0,'2026-11-24 01:45:25','2026-11-24 01:45:25'),(4,2,5,'Support Intake',1,1,0,0,'2026-11-24 01:45:25','2026-11-24 01:45:25'),(5,2,6,'Support Service',2,1,0,0,'2026-11-24 01:45:25','2026-11-24 01:45:25');
/*!40000 ALTER TABLE `workflow_steps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `workflows`
--

DROP TABLE IF EXISTS `workflows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workflows_department_id_foreign` (`department_id`),
  CONSTRAINT `workflows_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `workflows`
--

LOCK TABLES `workflows` WRITE;
/*!40000 ALTER TABLE `workflows` DISABLE KEYS */;
INSERT INTO `workflows` VALUES (1,1,'General Flow',1,'2026-11-24 01:45:25','2026-11-24 01:45:25'),(2,2,'Support Flow',1,'2026-11-24 01:45:25','2026-11-24 01:45:25');
/*!40000 ALTER TABLE `workflows` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'kiyu'
--

--
-- Dumping routines for database 'kiyu'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-25  5:33:31
