-- ══════════════════════════════════════════════════════════════════
-- HEHMHS ERD v5 migration — roles + student_profiles + document_type_id
-- Backup taken: dumps/backup_pre_erd_migration_20261006_173106.sql
-- Run as one file (mysql stops at the first error).
-- ══════════════════════════════════════════════════════════════════
USE hehmhs;

-- A. Roles lookup table ────────────────────────────────────────────
CREATE TABLE roles (
    role_id   INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='User roles (lookup table).';

INSERT INTO roles (role_id, role_name) VALUES
    (1, 'Student'), (2, 'Registrar'), (3, 'Admin');

ALTER TABLE users ADD COLUMN role_id INT NULL AFTER id;

UPDATE users u JOIN roles r ON r.role_name = u.role
        SET u.role_id = r.role_id;

ALTER TABLE users
    MODIFY role_id INT NOT NULL,
    ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id)
        REFERENCES roles (role_id) ON UPDATE CASCADE;

ALTER TABLE users DROP COLUMN role;

-- B. Student profiles (5 columns out of users) ─────────────────────
CREATE TABLE student_profiles (
    user_id     INT NOT NULL PRIMARY KEY,
    student_id  VARCHAR(20) DEFAULT NULL COMMENT 'School-issued number (2026-0001)',
    lrn         VARCHAR(20) DEFAULT NULL COMMENT 'Learner Reference Number (12 digits)',
    grade_level VARCHAR(20) DEFAULT NULL,
    school_year_last_attended VARCHAR(20) DEFAULT NULL,
    UNIQUE KEY uq_profile_student_no (student_id),
    CONSTRAINT fk_profile_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Student-only academic data (1:1 with users).';

INSERT INTO student_profiles
    (user_id, student_id, lrn, grade_level, school_year_last_attended)
SELECT id, student_id, lrn, grade_level, school_year_last_attended
FROM users;

ALTER TABLE users
    DROP COLUMN student_id, DROP COLUMN lrn,
    DROP COLUMN grade_level, DROP COLUMN school_year_last_attended;

-- C. Real link: requests → document_types ──────────────────────────
ALTER TABLE document_requests
    ADD COLUMN document_type_id INT NULL AFTER user_id;

UPDATE document_requests dr
   JOIN document_types dt ON dt.name = dr.document_type
   SET dr.document_type_id = dt.id;

ALTER TABLE document_requests
    MODIFY document_type_id INT NOT NULL,
    ADD CONSTRAINT fk_request_doc_type FOREIGN KEY (document_type_id)
        REFERENCES document_types (id) ON UPDATE CASCADE;

ALTER TABLE document_requests DROP COLUMN document_type;
