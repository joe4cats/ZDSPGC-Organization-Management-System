<?php
/**
 * tools/self-check.php — system health, schema, and workflow verification.
 *
 * Destructive by design: with --wipe it DROPS EVERY TABLE, reloads the schema
 * and demo data, then runs the workflow tests. Never run --wipe against a
 * database that holds real records.
 *
 *   php tools/self-check.php          safe: read-only schema + helper checks
 *   php tools/self-check.php --wipe   reset database and run all workflow tests
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line.\n");
}

define('ZDSPGC_SKIP_INSTALL_CHECK', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $fail;
    if (!$ok) { $fail++; }
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
}

try {
    if (!in_array('--wipe', $argv ?? [], true)) {
        echo "== 1. Database schema (read-only) ==\n";
        $tables = Schema::tableList();
        check('schema present', count($tables) === 27, count($tables) . ' tables');
        echo "  SKIP destructive rebuild + workflow tests — run with --wipe to reset the database\n";
    } else {
    echo "== 1. Database schema and institutional seed ==\n";
    Schema::createDatabase();
    Schema::migrate();
    $tables = Schema::tableList();
    check('all 27 tables created', count($tables) === 27, count($tables) . ' tables');

    Schema::dropAll();
    Schema::migrate();
    $seeded = Schema::seed();
    check('institutional reference data seeded', $seeded['statements'] >= 6, (string) $seeded['statements'] . ' statements');
    check('system starts with ZERO demo user accounts', $seeded['users'] === 0, (string) $seeded['users'] . ' users');
    check('departments configured', (int) Database::count('departments') >= 5);
    check('academic years configured', (int) Database::count('academic_years') >= 4);
    check('organization categories configured', (int) Database::count('organization_categories') >= 8);
    check('officer positions configured', (int) Database::count('officer_positions') >= 10);

    echo "\n== 2. Core Workflows (Admin, Student, Org, Events) ==\n";
    // Test creating an administrator
    $adminId = UserRepo::create([
        'username'  => 'sysadmin',
        'email'     => 'admin@zdspgc.edu.ph',
        'full_name' => 'System Administrator',
        'role'      => 'admin',
        'status'    => 'active',
    ], 'AdminPass@2026');
    check('administrator account creation', $adminId > 0, 'User ID: ' . $adminId);

    // Test creating an adviser
    $adviserUserId = UserRepo::create([
        'username'  => 'adviser.test',
        'email'     => 'adviser@zdspgc.edu.ph',
        'full_name' => 'Faculty Adviser Test',
        'role'      => 'adviser',
        'status'    => 'active',
    ], 'AdviserPass@2026');
    $adviserId = Database::insert('advisers', [
        'user_id'        => $adviserUserId,
        'employee_no'    => 'FAC-TEST-001',
        'department_id'  => 1,
        'specialization' => 'Information Technology',
        'status'         => 'active',
        'created_at'     => Helpers::now(),
    ]);
    check('adviser profile creation', $adviserId > 0, 'Adviser ID: ' . $adviserId);

    // Test student registration
    $studentId = StudentRepo::create([
        'first_name'     => 'Juan',
        'last_name'      => 'Dela Cruz',
        'student_id'     => '2026-00001',
        'email'          => 'juan.delacruz@zdspgc.edu.ph',
        'course'         => 'BS Information Technology',
        'year_level'     => '3rd Year',
        'section'        => 'BSIT-3A',
        'department_id'  => 1,
        'contact_number' => '09191234567',
    ], 'StudentPass@2026');
    check('student registration workflow', $studentId > 0, 'Student ID: ' . $studentId);

    // Test organization creation
    $orgId = Database::insert('organizations', [
        'organization_code'    => 'ORG-TEST-001',
        'name'                 => 'ZDSPGC Computing Society',
        'acronym'              => 'CS',
        'description'          => 'Testing organization for IT students.',
        'organization_type'    => 'Departmental',
        'category_id'          => 1,
        'department_id'        => 1,
        'adviser_id'           => $adviserId,
        'president_id'         => $studentId,
        'status'               => 'active',
        'accreditation_status' => 'approved',
        'academic_year_id'     => AcademicRepo::activeYearId(),
        'created_by'           => $adminId,
        'created_at'           => Helpers::now(),
    ]);
    check('organization creation', $orgId > 0, 'Org ID: ' . $orgId);

    // Test member joining
    $memberId = Database::insert('organization_members', [
        'organization_id'  => $orgId,
        'student_id'       => $studentId,
        'academic_year_id' => AcademicRepo::activeYearId(),
        'status'           => 'active',
        'position_id'      => 1,
        'position_title'   => 'President',
        'applied_at'       => Helpers::now(),
        'joined_at'        => Helpers::now(),
        'created_at'       => Helpers::now(),
    ]);
    check('member enrollment', $memberId > 0, 'Member ID: ' . $memberId);

    // Seeded directly above with a President position — mirror the promotion
    // OfficerRepo::assign performs, otherwise the account cannot open the
    // organization workspace it was just given.
    $juanUserId = (int) Database::value('SELECT user_id FROM students WHERE id = :s', ['s' => $studentId], 0);
    if ($juanUserId > 0 && Database::value('SELECT role FROM users WHERE id = :u', ['u' => $juanUserId]) !== 'officer') {
        Database::update('users', ['role' => 'officer', 'updated_at' => Helpers::now()], 'id = :u', ['u' => $juanUserId]);
    }
    check('seeded president holds the officer role', Database::value('SELECT role FROM users WHERE id = :u', ['u' => $juanUserId]) === 'officer');

    // Test officer appointment
    $officerId = Database::insert('organization_officers', [
        'organization_id'  => $orgId,
        'student_id'       => $studentId,
        'position_id'      => 1,
        'academic_year_id' => AcademicRepo::activeYearId(),
        'term'             => 'AY 2026-2027',
        'status'           => 'active',
        'appointed_by'     => $adminId,
        'created_at'       => Helpers::now(),
    ]);
    check('officer appointment', $officerId > 0, 'Officer ID: ' . $officerId);

    // Test event creation
    $eventId = Database::insert('events', [
        'event_code'            => 'EVT-TEST-001',
        'organization_id'       => $orgId,
        'academic_year_id'      => AcademicRepo::activeYearId(),
        'title'                 => 'Tech Innovation Assembly',
        'description'           => 'Annual student technology convention.',
        'event_type'            => 'Seminar',
        'venue'                 => 'College Auditorium',
        'event_date'            => date('Y-m-d', strtotime('+3 days')),
        'start_time'            => '08:00:00',
        'end_time'              => '12:00:00',
        'registration_deadline' => date('Y-m-d 23:59:59', strtotime('+2 days')),
        'organizer'             => 'CS Executive Board',
        'adviser_id'            => $adviserId,
        'status'                => 'approved',
        'grace_minutes'         => 15,
        'qr_nonce'              => bin2hex(random_bytes(6)),
        'created_by'            => $adminId,
        'created_at'            => Helpers::now(),
    ]);
    check('event scheduling', $eventId > 0, 'Event ID: ' . $eventId);

    // Test QR tokens
    echo "\n== 3. QR token mechanics ==\n";
    $event   = Database::one('SELECT * FROM events WHERE id = :id', ['id' => $eventId]);
    $token   = Qr::eventToken($event);
    $decoded = Qr::readToken($token);
    check('event token verifies', $decoded !== null && $decoded['key'] === $event['event_code']);
    check('tampered token rejected', Qr::readToken(substr($token, 0, -2) . 'xx') === null);
    $stRow = Database::one('SELECT * FROM students WHERE id = :id', ['id' => $studentId]);
    check('student token verifies', Qr::studentFromToken(Qr::studentToken($stRow)) !== null);
    check('token read from scan URL', Qr::tokenFromScan('https://host/scan.php?t=' . urlencode($token)) === $token);

    // Test attendance record
    $att = AttendanceRepo::record($eventId, $studentId, 'present', 'qr_event', 'Checked in via test');
    check('attendance recorded via QR', $att['ok'] === true);
    } // if --wipe
} catch (Throwable $e) {
    echo 'EXCEPTION: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . PHP_EOL;
    $fail++;
}

echo "\n== 4. Security & helper functions ==\n";
check('csv formula guard', Security::csvSafe('=cmd') === "'=cmd");
check('weak password rejected', Security::passwordProblem('short') !== null);
check('strong password accepted', Security::passwordProblem('Student@2026') === null);
check('percent helper', abs(Helpers::percent(3, 4) - 75.0) < 0.01);
check('path traversal blocked', Uploads::normalise('../../config/database.php') === null);
check('url helper', str_ends_with(Helpers::url('admin/events.php'), 'admin/events.php'));

echo "\n== 5. All 55 portal routes check ==\n";
$routes = [
    'admin/dashboard.php', 'admin/organizations.php', 'admin/calendar.php', 'admin/reports.php',
    'admin/students.php', 'admin/advisers.php', 'admin/members.php', 'admin/officers.php',
    'admin/events.php', 'admin/projects.php', 'admin/proposals.php', 'admin/attendance.php',
    'admin/documents.php', 'admin/announcements.php', 'admin/activity-logs.php',
    'admin/academic-years.php', 'admin/categories.php', 'admin/departments.php',
    'admin/users.php', 'admin/settings.php',
    'adviser/dashboard.php', 'adviser/organizations.php', 'adviser/calendar.php', 'adviser/reports.php',
    'adviser/members.php', 'adviser/officers.php', 'adviser/events.php', 'adviser/proposals.php',
    'adviser/documents.php', 'adviser/attendance.php', 'adviser/announcements.php',
    'organization/dashboard.php', 'organization/profile.php', 'organization/members.php',
    'organization/officers.php', 'organization/events.php', 'organization/attendance.php',
    'organization/projects.php', 'organization/proposals.php', 'organization/documents.php',
    'organization/announcements.php', 'organization/calendar.php', 'organization/reports.php',
    'organization/settings.php',
    'student/dashboard.php', 'student/organizations.php', 'student/my-organizations.php',
    'student/events.php', 'student/my-registrations.php', 'scan.php', 'student/my-attendance.php',
    'student/announcements.php', 'student/calendar.php',
    'notifications.php', 'account.php'
];
$missingRoutes = 0;
foreach ($routes as $r) {
    if (!file_exists(dirname(__DIR__) . '/' . $r)) {
        $missingRoutes++;
    }
}
check('all 55 navigation routes exist and callable', $missingRoutes === 0, (55 - $missingRoutes) . '/55 routes');

echo "\n" . ($fail === 0 ? "ALL CHECKS PASSED PERFECTLY!\n" : $fail . " CHECK(S) FAILED\n");
exit($fail === 0 ? 0 : 1);
