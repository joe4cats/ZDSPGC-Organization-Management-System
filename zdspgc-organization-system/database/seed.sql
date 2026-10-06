-- =============================================================================
--  ZDSPGC Organization Management System — Institutional Seed Data
--  database/seed.sql
--
--  Loaded automatically by install.php on a fresh database.
--  Contains institutional configuration, departments, academic years, categories,
--  document types, and officer positions.
--
--  NO demo user accounts are included. Administrators and students create accounts
--  via the registration and user-management workflows.
-- =============================================================================

INSERT IGNORE INTO settings (setting_key, setting_value, setting_group, label, updated_at) VALUES
('system_name', 'ZDSPGC Organization Management System', 'general', 'System name', NOW()),
('school_name', 'Zamboanga del Sur Provincial Government College', 'general', 'Institution name', NOW()),
('academic_year_label', '2026–2027', 'general', 'Current academic year label', NOW()),
('max_upload_mb', '8', 'uploads', 'Maximum upload size (MB)', NOW()),
('accreditation_validity_years', '2', 'accreditation', 'Accreditation validity (years)', NOW()),
('organization_registration_open', '1', 'organizations', 'Organization registration open', NOW()),
('membership_requires_officer_approval', '1', 'organizations', 'Membership applications need officer approval', NOW()),
('attendance_grace_minutes', '15', 'attendance', 'Default grace period (minutes)', NOW()),
('maintenance_mode', '0', 'general', 'Maintenance mode', NOW()),
('contact_email', 'orgsys@zdspgc.edu.ph', 'general', 'Official contact e-mail', NOW());

INSERT IGNORE INTO required_document_types (id, name, description, applies_to, is_required, sort_order, status) VALUES
(1, 'Constitution and By-Laws', 'Signed copy of the organization constitution and by-laws', 'registration', 1, 10, 'active'),
(2, 'List of Officers', 'Complete list of officers with positions and signatures', 'registration', 1, 20, 'active'),
(3, 'List of Members', 'Roster of members for the current academic year', 'registration', 1, 30, 'active'),
(4, 'Adviser Certification', 'Certification signed by the organization adviser', 'registration', 1, 40, 'active'),
(5, 'Organization Plan', 'Annual plan of activities and projects', 'registration', 1, 50, 'active'),
(6, 'Accreditation Certificate', 'Certificate of accreditation from the Office of Student Affairs', 'accreditation', 1, 60, 'active'),
(7, 'Financial Report', 'Statement of funds and liquidation report', 'general', 0, 70, 'active'),
(8, 'Event Program', 'Program flow of an approved event', 'event', 0, 80, 'active'),
(9, 'Post-Activity Report', 'Narrative report submitted after an activity', 'event', 1, 90, 'active'),
(10, 'Attendance Report', 'Attendance summary of an activity', 'event', 0, 100, 'active');

INSERT IGNORE INTO departments (id, code, name, head_name, status, created_at) VALUES
(1, 'CICS', 'College of Information and Computing Studies', 'Dr. Marilou S. Estrella', 'active', NOW()),
(2, 'CBA',  'College of Business Administration', 'Dr. Alfredo N. Cabrera', 'active', NOW()),
(3, 'CTE',  'College of Teacher Education', 'Dr. Bernadette L. Fuentes', 'active', NOW()),
(4, 'CAS',  'College of Arts and Sciences', 'Prof. Rowena T. Salcedo', 'active', NOW()),
(5, 'COE',  'College of Engineering', 'Engr. Danilo M. Reyes', 'active', NOW());

INSERT IGNORE INTO academic_years (id, name, start_date, end_date, status, created_at) VALUES
(1, '2024–2025', '2024-08-01', '2025-05-31', 'closed',   NOW()),
(2, '2025–2026', '2025-08-01', '2026-05-31', 'closed',   NOW()),
(3, '2026–2027', '2026-08-01', '2027-05-31', 'active',   NOW()),
(4, '2027–2028', '2027-08-01', '2028-05-31', 'upcoming', NOW());

INSERT IGNORE INTO organization_categories (id, name, description, status, created_at) VALUES
(1, 'Academic', 'Course-related and academically inclined organizations', 'active', NOW()),
(2, 'Cultural', 'Performing arts, cultural and heritage organizations', 'active', NOW()),
(3, 'Sports', 'Athletic and sports development organizations', 'active', NOW()),
(4, 'Religious', 'Faith-based and spiritual organizations', 'active', NOW()),
(5, 'Community Service', 'Outreach, volunteerism and civic organizations', 'active', NOW()),
(6, 'Special Interest', 'Hobby, advocacy and special interest organizations', 'active', NOW()),
(7, 'Departmental', 'Organizations anchored in a specific college or department', 'active', NOW()),
(8, 'Student Government', 'Official student councils and governing bodies', 'active', NOW());

INSERT IGNORE INTO officer_positions (id, name, description, sort_order, is_officer, status) VALUES
(1,  'President',                 'Highest executive officer of the organization', 10,  1, 'active'),
(2,  'Vice President',            'Assists the president and chairs committees', 20,  1, 'active'),
(3,  'Secretary',                 'Keeps minutes and organizational records', 30,  1, 'active'),
(4,  'Treasurer',                 'Custodian of funds and financial reports', 40,  1, 'active'),
(5,  'Auditor',                   'Audits finances and internal processes', 50,  1, 'active'),
(6,  'Public Information Officer', 'Handles communications and announcements', 60,  1, 'active'),
(7,  'Peace Officer',             'Maintains order during activities', 70,  1, 'active'),
(8,  'Representative',            'Represents the organization in councils', 80,  1, 'active'),
(9,  'Committee Head',            'Leads a standing or special committee', 90,  1, 'active'),
(10, 'Adviser',                   'Faculty adviser supervising the organization', 100, 0, 'active'),
(11, 'Member',                    'Regular member without an officer position', 110, 0, 'active');
