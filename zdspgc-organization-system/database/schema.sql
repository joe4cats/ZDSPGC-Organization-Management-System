-- =============================================================================
--  ZDSPGC Organization Management System — MySQL / MariaDB schema
--  database/schema.sql
--
--  XAMPP users: create the database, then import this file (phpMyAdmin →
--  Import), or simply open install.php which creates everything for you.
--
--  Engine InnoDB, charset utf8mb4. Foreign keys use safe cascade rules:
--  institutional records are archived (status columns) rather than deleted.
-- =============================================================================

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(60)  NOT NULL,
  email         VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','adviser','officer','student') NOT NULL DEFAULT 'student',
  full_name     VARCHAR(160) NOT NULL,
  phone         VARCHAR(30)  NOT NULL DEFAULT '',
  avatar        VARCHAR(160) NOT NULL DEFAULT '',
  status        ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  selector       VARCHAR(32)  NOT NULL,
  validator_hash VARCHAR(64)  NOT NULL,
  expires_at     DATETIME     NOT NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_remember_selector (selector),
  KEY idx_remember_user (user_id),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  token_hash VARCHAR(64)  NOT NULL,
  expires_at DATETIME     NOT NULL,
  used_at    DATETIME     NULL,
  ip_address VARCHAR(45)  NOT NULL DEFAULT '',
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_reset_user (user_id),
  KEY idx_reset_token (token_hash),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS departments (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(30)  NOT NULL,
  name       VARCHAR(160) NOT NULL,
  head_name  VARCHAR(160) NOT NULL DEFAULT '',
  status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS advisers (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  employee_no    VARCHAR(40)  NOT NULL DEFAULT '',
  department_id  INT UNSIGNED NULL,
  specialization VARCHAR(160) NOT NULL DEFAULT '',
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_advisers_user (user_id),
  KEY idx_advisers_department (department_id),
  CONSTRAINT fk_advisers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_advisers_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NULL,
  student_id      VARCHAR(30)  NOT NULL,
  first_name      VARCHAR(80)  NOT NULL,
  middle_name     VARCHAR(80)  NOT NULL DEFAULT '',
  last_name       VARCHAR(80)  NOT NULL,
  suffix          VARCHAR(20)  NOT NULL DEFAULT '',
  gender          ENUM('male','female','other') NOT NULL DEFAULT 'other',
  birthdate       DATE         NULL,
  email           VARCHAR(160) NOT NULL DEFAULT '',
  contact_number  VARCHAR(30)  NOT NULL DEFAULT '',
  address         VARCHAR(255) NOT NULL DEFAULT '',
  course          VARCHAR(80)  NOT NULL DEFAULT '',
  year_level      VARCHAR(20)  NOT NULL DEFAULT '',
  section         VARCHAR(40)  NOT NULL DEFAULT '',
  department_id   INT UNSIGNED NULL,
  profile_picture VARCHAR(160) NOT NULL DEFAULT '',
  qr_nonce        VARCHAR(32)  NOT NULL DEFAULT '',
  status          ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_students_student_id (student_id),
  KEY idx_students_name (last_name, first_name),
  KEY idx_students_department (department_id),
  KEY idx_students_course (course, year_level),
  CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_students_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_years (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(30)  NOT NULL,
  start_date DATE         NOT NULL,
  end_date   DATE         NOT NULL,
  status     ENUM('upcoming','active','closed','archived') NOT NULL DEFAULT 'upcoming',
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_academic_years_name (name),
  KEY idx_academic_years_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organization_categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS officer_positions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  sort_order  INT          NOT NULL DEFAULT 100,
  is_officer  TINYINT(1)   NOT NULL DEFAULT 1,
  status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uq_positions_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organizations (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_code    VARCHAR(40)  NOT NULL,
  name                 VARCHAR(180) NOT NULL,
  acronym              VARCHAR(30)  NOT NULL DEFAULT '',
  description          TEXT         NULL,
  organization_type    VARCHAR(40)  NOT NULL DEFAULT 'Academic',
  category_id          INT UNSIGNED NULL,
  department_id        INT UNSIGNED NULL,
  adviser_id           INT UNSIGNED NULL,
  president_id         INT UNSIGNED NULL,
  contact_email        VARCHAR(160) NOT NULL DEFAULT '',
  contact_number       VARCHAR(30)  NOT NULL DEFAULT '',
  social_link          VARCHAR(255) NOT NULL DEFAULT '',
  logo                 VARCHAR(160) NOT NULL DEFAULT '',
  constitution_path    VARCHAR(200) NOT NULL DEFAULT '',
  status               ENUM('pending','active','suspended','expired','archived','rejected') NOT NULL DEFAULT 'pending',
  accreditation_status ENUM('not_applied','pending','under_review','approved','revision_required','rejected','expired') NOT NULL DEFAULT 'not_applied',
  accreditation_expires_at DATE NULL,
  academic_year_id     INT UNSIGNED NULL,
  date_established     DATE         NULL,
  submitted_at         DATETIME     NULL,
  approved_by          INT UNSIGNED NULL,
  approved_at          DATETIME     NULL,
  rejection_reason     VARCHAR(400) NOT NULL DEFAULT '',
  archived_at          DATETIME     NULL,
  notes                TEXT         NULL,
  created_by           INT UNSIGNED NULL,
  created_at           DATETIME     NOT NULL,
  updated_at           DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_organizations_code (organization_code),
  KEY idx_organizations_status (status),
  KEY idx_organizations_category (category_id),
  KEY idx_organizations_department (department_id),
  KEY idx_organizations_adviser (adviser_id),
  CONSTRAINT fk_orgs_category FOREIGN KEY (category_id) REFERENCES organization_categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_orgs_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
  CONSTRAINT fk_orgs_adviser FOREIGN KEY (adviser_id) REFERENCES advisers (id) ON DELETE SET NULL,
  CONSTRAINT fk_orgs_president FOREIGN KEY (president_id) REFERENCES students (id) ON DELETE SET NULL,
  CONSTRAINT fk_orgs_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE SET NULL,
  CONSTRAINT fk_orgs_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_orgs_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organization_members (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id  INT UNSIGNED NOT NULL,
  student_id       INT UNSIGNED NOT NULL,
  academic_year_id INT UNSIGNED NOT NULL,
  position_id      INT UNSIGNED NULL,
  position_title   VARCHAR(80)  NOT NULL DEFAULT '',
  status           ENUM('pending','active','rejected','suspended','inactive','archived') NOT NULL DEFAULT 'pending',
  applied_at       DATETIME     NULL,
  joined_at        DATETIME     NULL,
  decided_by       INT UNSIGNED NULL,
  decided_at       DATETIME     NULL,
  remarks          VARCHAR(400) NOT NULL DEFAULT '',
  created_at       DATETIME     NOT NULL,
  updated_at       DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_members_slot (organization_id, student_id, academic_year_id),
  KEY idx_members_status (organization_id, status),
  KEY idx_members_student (student_id),
  CONSTRAINT fk_members_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_members_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_members_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE,
  CONSTRAINT fk_members_position FOREIGN KEY (position_id) REFERENCES officer_positions (id) ON DELETE SET NULL,
  CONSTRAINT fk_members_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organization_officers (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id  INT UNSIGNED NOT NULL,
  student_id       INT UNSIGNED NOT NULL,
  position_id      INT UNSIGNED NOT NULL,
  academic_year_id INT UNSIGNED NOT NULL,
  term             VARCHAR(40)  NOT NULL DEFAULT '',
  start_date       DATE         NULL,
  end_date         DATE         NULL,
  status           ENUM('active','ended','archived') NOT NULL DEFAULT 'active',
  appointed_by     INT UNSIGNED NULL,
  notes            VARCHAR(255) NOT NULL DEFAULT '',
  created_at       DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_officers_slot (organization_id, student_id, position_id, academic_year_id),
  KEY idx_officers_org (organization_id, status),
  CONSTRAINT fk_officers_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_officers_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_officers_position FOREIGN KEY (position_id) REFERENCES officer_positions (id) ON DELETE CASCADE,
  CONSTRAINT fk_officers_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE,
  CONSTRAINT fk_officers_appointed_by FOREIGN KEY (appointed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accreditation_applications (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id     INT UNSIGNED NOT NULL,
  academic_year_id    INT UNSIGNED NOT NULL,
  submitted_by        INT UNSIGNED NULL,
  status              ENUM('pending','under_review','approved','revision_required','rejected') NOT NULL DEFAULT 'pending',
  submitted_at        DATETIME     NOT NULL,
  adviser_verified_by INT UNSIGNED NULL,
  adviser_verified_at DATETIME     NULL,
  adviser_remarks     VARCHAR(400) NOT NULL DEFAULT '',
  reviewed_by         INT UNSIGNED NULL,
  reviewed_at         DATETIME     NULL,
  decision_notes      VARCHAR(600) NOT NULL DEFAULT '',
  expires_at          DATE         NULL,
  created_at          DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_accreditation_org (organization_id, status),
  CONSTRAINT fk_accreditation_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_accreditation_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE,
  CONSTRAINT fk_accreditation_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_accreditation_adviser FOREIGN KEY (adviser_verified_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_accreditation_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_code          VARCHAR(40)  NOT NULL,
  organization_id     INT UNSIGNED NOT NULL,
  academic_year_id    INT UNSIGNED NOT NULL,
  title               VARCHAR(180) NOT NULL,
  description         TEXT         NULL,
  event_type          VARCHAR(40)  NOT NULL DEFAULT 'Seminar',
  venue               VARCHAR(160) NOT NULL DEFAULT '',
  event_date          DATE         NOT NULL,
  start_time          TIME         NOT NULL,
  end_time            TIME         NOT NULL,
  registration_deadline DATETIME   NULL,
  max_participants    INT          NOT NULL DEFAULT 0,
  organizer           VARCHAR(160) NOT NULL DEFAULT '',
  adviser_id          INT UNSIGNED NULL,
  status              ENUM('draft','pending_adviser','pending_admin','approved','rejected','ongoing','completed','cancelled') NOT NULL DEFAULT 'draft',
  requires_registration TINYINT(1) NOT NULL DEFAULT 1,
  grace_minutes       INT          NOT NULL DEFAULT 15,
  qr_nonce            VARCHAR(32)  NOT NULL DEFAULT '',
  banner              VARCHAR(160) NOT NULL DEFAULT '',
  created_by          INT UNSIGNED NULL,
  submitted_at        DATETIME     NULL,
  adviser_approved_by INT UNSIGNED NULL,
  adviser_approved_at DATETIME     NULL,
  approved_by         INT UNSIGNED NULL,
  approved_at         DATETIME     NULL,
  rejection_reason    VARCHAR(400) NOT NULL DEFAULT '',
  completed_at        DATETIME     NULL,
  created_at          DATETIME     NOT NULL,
  updated_at          DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_events_code (event_code),
  KEY idx_events_org (organization_id, status),
  KEY idx_events_date (event_date, status),
  CONSTRAINT fk_events_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_events_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE,
  CONSTRAINT fk_events_adviser FOREIGN KEY (adviser_id) REFERENCES advisers (id) ON DELETE SET NULL,
  CONSTRAINT fk_events_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_events_adviser_approved FOREIGN KEY (adviser_approved_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_events_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_registrations (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id      INT UNSIGNED NOT NULL,
  student_id    INT UNSIGNED NOT NULL,
  status        ENUM('registered','cancelled','attended','no_show') NOT NULL DEFAULT 'registered',
  registered_at DATETIME     NOT NULL,
  cancelled_at  DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_registration_slot (event_id, student_id),
  KEY idx_registration_student (student_id, status),
  CONSTRAINT fk_registration_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
  CONSTRAINT fk_registration_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id            INT UNSIGNED NOT NULL,
  student_id          INT UNSIGNED NOT NULL,
  organization_id     INT UNSIGNED NOT NULL,
  attendance_date     DATE         NOT NULL,
  attendance_time     DATETIME     NOT NULL,
  status              ENUM('present','late','excused','absent') NOT NULL DEFAULT 'present',
  verification_method ENUM('qr_event','qr_student','manual','import','self_checkin') NOT NULL DEFAULT 'qr_event',
  ip_address          VARCHAR(45)  NOT NULL DEFAULT '',
  device_info         VARCHAR(190) NOT NULL DEFAULT '',
  recorded_by         INT UNSIGNED NULL,
  remark              VARCHAR(255) NOT NULL DEFAULT '',
  created_at          DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_slot (event_id, student_id),
  KEY idx_attendance_student (student_id, attendance_date),
  KEY idx_attendance_org (organization_id, attendance_date),
  CONSTRAINT fk_attendance_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checkin_requests (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id     INT UNSIGNED NOT NULL,
  student_id   INT UNSIGNED NOT NULL,
  status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  requested_at DATETIME     NOT NULL,
  decided_by   INT UNSIGNED NULL,
  decided_at   DATETIME     NULL,
  remark       VARCHAR(255) NOT NULL DEFAULT '',
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_checkin_event_status (event_id, status),
  KEY idx_checkin_student (student_id, status),
  CONSTRAINT fk_checkin_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
  CONSTRAINT fk_checkin_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_checkin_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_reports (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id           INT UNSIGNED NOT NULL,
  summary            TEXT         NULL,
  highlights         TEXT         NULL,
  participants_count INT          NOT NULL DEFAULT 0,
  file_path          VARCHAR(200) NOT NULL DEFAULT '',
  status             ENUM('draft','submitted','approved','revision_required') NOT NULL DEFAULT 'submitted',
  submitted_by       INT UNSIGNED NULL,
  submitted_at       DATETIME     NOT NULL,
  reviewed_by        INT UNSIGNED NULL,
  reviewed_at        DATETIME     NULL,
  review_notes       VARCHAR(400) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_report_event (event_id),
  CONSTRAINT fk_event_reports_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
  CONSTRAINT fk_event_reports_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_event_reports_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id    INT UNSIGNED NOT NULL,
  academic_year_id   INT UNSIGNED NOT NULL,
  title              VARCHAR(180) NOT NULL,
  description        TEXT         NULL,
  objectives         TEXT         NULL,
  target_participants VARCHAR(160) NOT NULL DEFAULT '',
  budget             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  funding_source     VARCHAR(160) NOT NULL DEFAULT '',
  start_date         DATE         NULL,
  end_date           DATE         NULL,
  status             ENUM('proposed','approved','ongoing','completed','cancelled') NOT NULL DEFAULT 'proposed',
  adviser_id         INT UNSIGNED NULL,
  created_by         INT UNSIGNED NULL,
  created_at         DATETIME     NOT NULL,
  updated_at         DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_projects_org (organization_id, status),
  CONSTRAINT fk_projects_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_projects_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE,
  CONSTRAINT fk_projects_adviser FOREIGN KEY (adviser_id) REFERENCES advisers (id) ON DELETE SET NULL,
  CONSTRAINT fk_projects_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proposals (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id        INT UNSIGNED NOT NULL,
  organization_id   INT UNSIGNED NOT NULL,
  title             VARCHAR(180) NOT NULL,
  submitted_by      INT UNSIGNED NULL,
  status            ENUM('draft','submitted','under_review','approved','rejected','revision_required','implemented','reported') NOT NULL DEFAULT 'draft',
  submitted_at      DATETIME     NULL,
  reviewed_by       INT UNSIGNED NULL,
  reviewed_at       DATETIME     NULL,
  reviewer_comments VARCHAR(600) NOT NULL DEFAULT '',
  current_version   INT          NOT NULL DEFAULT 1,
  created_at        DATETIME     NOT NULL,
  updated_at        DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_proposals_org (organization_id, status),
  CONSTRAINT fk_proposals_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_proposals_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_proposals_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_proposals_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proposal_comments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proposal_id INT UNSIGNED NOT NULL,
  author_id   INT UNSIGNED NULL,
  author_role VARCHAR(20)  NOT NULL DEFAULT '',
  decision    VARCHAR(30)  NOT NULL DEFAULT 'comment',
  comments    TEXT         NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_proposal_comments (proposal_id),
  CONSTRAINT fk_proposal_comments_proposal FOREIGN KEY (proposal_id) REFERENCES proposals (id) ON DELETE CASCADE,
  CONSTRAINT fk_proposal_comments_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NULL,
  document_type   VARCHAR(80)  NOT NULL,
  title           VARCHAR(180) NOT NULL,
  file_name       VARCHAR(200) NOT NULL,
  file_path       VARCHAR(200) NOT NULL DEFAULT '',
  file_size       INT UNSIGNED NOT NULL DEFAULT 0,
  mime_type       VARCHAR(120) NOT NULL DEFAULT '',
  version         INT          NOT NULL DEFAULT 1,
  parent_id       INT UNSIGNED NULL,
  status          ENUM('pending','approved','rejected','revision_required','archived') NOT NULL DEFAULT 'pending',
  remarks         VARCHAR(400) NOT NULL DEFAULT '',
  is_public       TINYINT(1)   NOT NULL DEFAULT 0,
  uploaded_by     INT UNSIGNED NULL,
  uploaded_at     DATETIME     NOT NULL,
  verified_by     INT UNSIGNED NULL,
  verified_at     DATETIME     NULL,
  archived_at     DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_documents_org (organization_id, document_type),
  KEY idx_documents_status (status),
  CONSTRAINT fk_documents_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_documents_parent FOREIGN KEY (parent_id) REFERENCES documents (id) ON DELETE SET NULL,
  CONSTRAINT fk_documents_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_documents_verified_by FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS required_document_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  applies_to  ENUM('registration','accreditation','event','general') NOT NULL DEFAULT 'general',
  is_required TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 100,
  status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uq_document_types (name, applies_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title                  VARCHAR(180) NOT NULL,
  content                TEXT         NULL,
  author_id              INT UNSIGNED NULL,
  organization_id        INT UNSIGNED NULL,
  audience               ENUM('all','organization','department','officers','members') NOT NULL DEFAULT 'all',
  audience_department_id INT UNSIGNED NULL,
  publish_date           DATETIME     NOT NULL,
  expiration_date        DATETIME     NULL,
  status                 ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
  is_pinned              TINYINT(1)   NOT NULL DEFAULT 0,
  created_at             DATETIME     NOT NULL,
  updated_at             DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_announcements_status (status, publish_date),
  KEY idx_announcements_org (organization_id),
  CONSTRAINT fk_announcements_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_announcements_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_announcements_department FOREIGN KEY (audience_department_id) REFERENCES departments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  title          VARCHAR(160) NOT NULL,
  message        VARCHAR(480) NOT NULL DEFAULT '',
  type           VARCHAR(20)  NOT NULL DEFAULT 'info',
  reference_id   INT UNSIGNED NULL,
  reference_type VARCHAR(40)  NOT NULL DEFAULT '',
  url            VARCHAR(255) NOT NULL DEFAULT '',
  is_read        TINYINT(1)   NOT NULL DEFAULT 0,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_notifications_inbox (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NULL,
  actor_name   VARCHAR(160) NOT NULL DEFAULT '',
  actor_role   VARCHAR(20)  NOT NULL DEFAULT '',
  action       VARCHAR(60)  NOT NULL,
  module       VARCHAR(40)  NOT NULL DEFAULT 'system',
  reference_id INT UNSIGNED NULL,
  description  VARCHAR(500) NOT NULL DEFAULT '',
  ip_address   VARCHAR(45)  NOT NULL DEFAULT '',
  user_agent   VARCHAR(190) NOT NULL DEFAULT '',
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_logs_module (module, created_at),
  KEY idx_logs_action (action),
  KEY idx_logs_user (user_id),
  KEY idx_logs_time (created_at),
  CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key   VARCHAR(60)  NOT NULL,
  setting_value TEXT         NULL,
  setting_group VARCHAR(40)  NOT NULL DEFAULT 'general',
  label         VARCHAR(160) NOT NULL DEFAULT '',
  updated_at    DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



