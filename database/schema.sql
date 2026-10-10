
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
DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `announcements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `message` text COLLATE utf8mb4_general_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = shown on dashboards',
  `created_by` int DEFAULT NULL COMMENT 'FK → students.id (author)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ann_active` (`is_active`),
  KEY `idx_ann_author` (`created_by`),
  CONSTRAINT `fk_announcement_author` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Announcements shown on the student dashboard.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL COMMENT 'FK users.id ÔÇö NULL for anonymous/failed events; SET NULL if the account is ever deleted',
  `actor_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'Snapshot of the name at event time',
  `actor_role` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'Student / Registrar / Admin / unknown',
  `action` varchar(50) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'LOGIN, LOGIN_FAILED, REQUEST_SUBMITTED, STATUS_CHANGED, CERTIFICATE_RELEASED, USER_CREATED, ...',
  `entity` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'auth / document_request / user',
  `entity_id` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'e.g. REQ-0005 or student_id',
  `details` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Human-readable summary of the event',
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'IPv4/IPv6 of the actor',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='System-wide audit trail (append-only).';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `credential_resend_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `credential_resend_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `requested_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_resend_email` (`email`),
  KEY `idx_resend_time` (`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Abuse protection for the Forgot-Credentials e-mail flow.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `document_type_id` int NOT NULL,
  `purpose` varchar(255) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Stated reason (e.g. College application)',
  `grade_level` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `school_year_last_attended` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('Pending','Processing','Ready for Pickup','Released','Cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pending',
  `cancelled_by` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'student | registrar (rejected) | unclaimed (legacy)',
  `rejection_reason` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_requested` datetime DEFAULT CURRENT_TIMESTAMP,
  `registrar_viewed_at` datetime DEFAULT NULL COMMENT 'First time staff opened this request''s details ÔÇö locks student editing',
  `date_released` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `id_photo` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Valid ID (uploads/request_requirements/…)',
  `e_certificate` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'System-generated PDF (uploads/e_certificates/…)',
  PRIMARY KEY (`id`),
  KEY `idx_req_status` (`status`),
  KEY `idx_req_date` (`date_requested`),
  KEY `idx_req_user` (`user_id`),
  KEY `fk_request_doc_type` (`document_type_id`),
  CONSTRAINT `fk_request_doc_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_request_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Document requests and their full lifecycle.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `requires_grade_level` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = the request form asks for the Grade Level',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 = hidden from the request form (kept for history)',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Requestable document types (admin-managed).';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `role_id` int NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='User roles (lookup table).';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `site_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_settings` (
  `setting_key` varchar(50) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'logo_path | theme_color',
  `setting_value` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Key–value store for site branding.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `student_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_profiles` (
  `user_id` int NOT NULL,
  `student_id` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'School-issued number (2026-0001)',
  `lrn` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Learner Reference Number (12 digits)',
  `grade_level` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `school_year_last_attended` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_profile_student_no` (`student_id`),
  CONSTRAINT `fk_profile_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Student-only academic data (1:1 with users).';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role_id` int NOT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active' COMMENT 'active | archived',
  `email` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contact` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'bcrypt hash (password_hash)',
  `profile_photo` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'uploads/profile_photos/…',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `failed_login_count` int NOT NULL DEFAULT '0' COMMENT 'Consecutive wrong passwords; reset on success',
  `lockout_count` int NOT NULL DEFAULT '0' COMMENT 'How many times locked (escalates duration); reset on success',
  `locked_until` datetime DEFAULT NULL COMMENT 'Active lock expiry; NULL = not locked',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_email` (`email`),
  KEY `idx_student_status` (`status`),
  KEY `fk_users_role` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='User accounts for every role (students, registrar, admin).';
/*!40101 SET character_set_client = @saved_cs_client */;

-- ── Seed data ──────────────────────────────────────────────
-- Roles (fixed ids referenced by users.role_id)
INSERT INTO `roles` (`role_id`, `role_name`) VALUES
(1, 'Student'),
(2, 'Registrar'),
(3, 'Admin');

-- Default accounts (bcrypt hashes; change these on any real deployment)
INSERT INTO `users` (`id`, `role_id`, `first_name`, `last_name`, `status`, `email`, `contact`, `password`) VALUES
(1, 2, 'Juanito', 'Bunao',     'active', 'registrar@hehmhs.edu.ph', '09170000001', '$2y$10$67bmpeQ91MNy4c.xx7MIUO49M38fqpcrUCk81V.1xVsBF0LZWsCfK'),  -- Registrar@123
(2, 3, 'Juanito', 'Bunao',     'active', 'hehmhsadmin@gmail.com',  '09170000002', '$2y$10$vz/hE3MinRWOhHJyST8KE.8m6VZBPbZTNl1YuA34FPQyF7xPmZSc.'),  -- Admin@123
(3, 1, 'Juan',    'Dela Cruz', 'active', 'juan@hehmhs.edu.ph',      '09123456789', '$2y$10$FfMQCfUH54jmNE4J1kz/2Oh4b6IDNMuKRuHlhAUT25rZRmU.fOHAO'); -- Student@123

-- Demo student's academic profile (school number 2026-9001)
INSERT INTO `student_profiles` (`user_id`, `student_id`, `lrn`, `grade_level`, `school_year_last_attended`) VALUES
(3, '2026-9001', '123456789012', NULL, NULL);

-- Requestable document types (mirrors the seed list in phpLogics/document_types.php).
-- The catalog is intentionally just THREE certificates — everything else
-- was removed from the system (2026-10-08 cleanup).
INSERT INTO `document_types` (`id`, `name`, `requires_grade_level`, `is_active`, `sort_order`) VALUES
(1, 'Certificate of Enrollment',            1, 1, 1),
(2, 'Certificate of Good Moral',            0, 1, 2),
(3, 'Certificate of Completion/Graduation', 1, 1, 3);

-- Branding + operational defaults (matches the code defaults in site_config.php)
INSERT INTO `site_settings` (`setting_key`, `setting_value`) VALUES
('logo_path',             'assets/img/Logo.png'),
('theme_color',           '#6b8f3a'),
('design_theme',          'classic'),
('office_hours',          'Monday to Friday, 8:00 AM – 4:00 PM'),
('contact_email',         'hehmhsadmin@gmail.com'),
('contact_phone',         '(044) 123-4567'),
('contact_location',      'Hilario E. Hermosa Memorial High School, Siclong, Laur, Nueva Ecija'),
('document_types_seeded', '1');

-- Welcome announcement (shown on the student dashboard)
INSERT INTO `announcements` (`title`, `message`, `is_active`, `created_by`) VALUES
('Welcome to the HEHMHS Credential Request System',
 'Request and track your school documents online — no campus visit required. Click “Request a Document” to get started.',
 1, NULL);

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

