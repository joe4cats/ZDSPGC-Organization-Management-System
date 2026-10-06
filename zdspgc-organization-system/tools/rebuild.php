<?php
/**
 * tools/rebuild.php — restores records lost when tools/self-check.php wiped
 * the database on 2026-10-07 (before the wipe was gated behind --wipe).
 *
 * Idempotent: every section skips records that already exist, so the script
 * can be re-run safely.
 *
 *   php tools/rebuild.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line.\n");
}

define('ZDSPGC_SKIP_INSTALL_CHECK', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

function out(string $label, string $detail = ''): void
{
    echo '  ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
}

try {
    // ---------------------------------------------------------------
    echo "== 1. Known passwords for the demo accounts ==\n";
    // self-check.php passed the password as a 2nd argument, which the old
    // UserRepo::create() signature silently discarded — reset them here.
    foreach (['sysadmin' => 'AdminPass@2026', 'adviser.test' => 'AdviserPass@2026'] as $username => $plain) {
        $user = UserRepo::findByUsername($username);
        if ($user === null) {
            out($username, 'account missing — skipped');
            continue;
        }
        if (password_verify($plain, (string) $user['password_hash'])) {
            out($username, 'password already correct');
            continue;
        }
        Database::update('users', ['password_hash' => Auth::hash($plain)], 'id = :id', ['id' => (int) $user['id']]);
        out($username, 'password set to ' . $plain);
    }

    // ---------------------------------------------------------------
    echo "\n== 2. Student — Kyle Alejo ==\n";
    if (StudentRepo::findByStudentId('2026-00503') !== null) {
        out('2026-00503', 'already exists');
    } else {
        StudentRepo::create([
            'first_name'    => 'Kyle',
            'last_name'     => 'Alejo',
            'student_id'    => '2026-00503',
            'email'         => 'kylealejo@gmail.com',
            'course'        => 'BS Information Technology',
            'year_level'    => '1st Year',
            'department_id' => 1,
        ], 'StudentPass@2026');
        out('2026-00503', 'created — StudentPass@2026');
    }

    // ---------------------------------------------------------------
    echo "\n== 3. Adviser — Maria Elena C. Sarmiento ==\n";
    $mariaUser = Database::one(
        'SELECT id FROM users WHERE full_name = :n AND role = "adviser"',
        ['n' => 'Maria Elena C. Sarmiento']
    );
    if ($mariaUser !== null) {
        $mariaAdviser = Database::one('SELECT id FROM advisers WHERE user_id = :u', ['u' => (int) $mariaUser['id']]);
        $adviserId    = (int) ($mariaAdviser['id'] ?? 0);
        out('adviser', 'already exists (id ' . $adviserId . ')');
    } else {
        $mariaUserId = UserRepo::create([
            'username'  => 'msarmiento',
            'email'     => 'maria.sarmiento@zdspgc.edu.ph',
            'full_name' => 'Maria Elena C. Sarmiento',
            'role'      => 'adviser',
            'status'    => 'active',
        ], 'AdviserPass@2026');
        $adviserId = Database::insert('advisers', [
            'user_id'        => $mariaUserId,
            'employee_no'    => 'FAC-001',
            'department_id'  => 1,
            'specialization' => '',
            'status'         => 'active',
            'created_at'     => Helpers::now(),
        ]);
        out('adviser', 'created as msarmiento — AdviserPass@2026 (adviser id ' . $adviserId . ')');
    }

    $admin = UserRepo::findByUsername('sysadmin');
    $adminId = (int) ($admin['id'] ?? 1);
    $ay      = AcademicRepo::activeYearId();

    // ---------------------------------------------------------------
    echo "\n== 4. Organizations — SITE and SSC ==\n";
    $orgIds = [];
    foreach ([
        'SITE' => ['type' => 'Departmental', 'category' => 7],
        'SSC'  => ['type' => 'Student Council', 'category' => 8],
    ] as $acronym => $spec) {
        $existing = Database::one('SELECT id FROM organizations WHERE acronym = :a', ['a' => $acronym]);
        if ($existing !== null) {
            $orgIds[$acronym] = (int) $existing['id'];
            out($acronym, 'already exists (id ' . $orgIds[$acronym] . ')');
            continue;
        }
        $orgIds[$acronym] = Database::insert('organizations', [
            'organization_code'    => OrgRepo::nextCode($acronym),
            'name'                 => $acronym, // full name pending — update in the admin portal
            'acronym'              => $acronym,
            'organization_type'    => $spec['type'],
            'category_id'          => $spec['category'],
            'department_id'        => 1,
            'adviser_id'           => $adviserId,
            'status'               => 'active',
            'accreditation_status' => 'approved',
            'academic_year_id'     => $ay,
            'submitted_at'         => Helpers::now(),
            'created_by'           => $adminId,
            'created_at'           => Helpers::now(),
        ]);
        out($acronym, 'created (id ' . $orgIds[$acronym] . ', adviser: Maria Elena C. Sarmiento)');
    }

    // ---------------------------------------------------------------
    echo "\n== 5. Events ==\n";
    $events = [
        [
            'org'   => 'SITE',
            'title' => 'TechTalk 2026: Artificial Intelligence in Public Service',
            'date'  => '2026-10-15',
            'venue' => 'ZDSPGC ICT Laboratory 2',
        ],
        [
            'org'   => 'SSC',
            'title' => 'Leadership Summit 2026',
            'date'  => '2026-10-25',
            'venue' => 'ZDSPGC AVR',
        ],
    ];
    foreach ($events as $spec) {
        $orgId = $orgIds[$spec['org']];
        $existing = Database::one(
            'SELECT id FROM events WHERE organization_id = :o AND title = :t',
            ['o' => $orgId, 't' => $spec['title']]
        );
        if ($existing !== null) {
            out($spec['title'], 'already exists');
            continue;
        }
        $deadline = date('Y-m-d 23:59:59', strtotime($spec['date'] . ' -1 day'));
        $eventId = Database::insert('events', [
            'event_code'            => EventRepo::nextCode($orgId),
            'organization_id'       => $orgId,
            'academic_year_id'      => $ay,
            'title'                 => $spec['title'],
            'event_type'            => 'Seminar',
            'venue'                 => $spec['venue'],
            'event_date'            => $spec['date'],
            'start_time'            => '08:00:00',
            'end_time'              => '17:00:00',
            'registration_deadline' => $deadline,
            'organizer'             => $spec['org'],
            'adviser_id'            => $adviserId,
            'status'                => 'approved',
            'requires_registration' => 1,
            'grace_minutes'         => 15,
            'qr_nonce'              => bin2hex(random_bytes(6)),
            'created_by'            => $adminId,
            'created_at'            => Helpers::now(),
        ]);
        out($spec['title'], 'created — ' . $spec['date'] . ' 08:00, ' . $spec['venue'] . ' (id ' . $eventId . ')');
    }

    // ---------------------------------------------------------------
    echo "\n== 6. Computing Society membership (from the original seed) ==\n";
    $csOrg   = Database::one('SELECT id FROM organizations WHERE acronym = "CS"');
    $juan    = StudentRepo::findByStudentId('2026-00001');
    $kyleRow = StudentRepo::findByStudentId('2026-00503');
    if ($csOrg === null || $juan === null) {
        out('CS membership', 'seed records missing — skipped');
    } else {
        $juanUserId = (int) $juan['user_id'];
        if (Database::value('SELECT role FROM users WHERE id = :u', ['u' => $juanUserId]) !== 'officer') {
            Database::update('users', ['role' => 'officer', 'updated_at' => Helpers::now()], 'id = :u', ['u' => $juanUserId]);
            out('2026-00001', 'promoted to officer (CS President)');
        } else {
            out('2026-00001', 'already an officer');
        }
        if (Database::count('organization_members', 'organization_id = :o AND student_id = :s', ['o' => (int) $csOrg['id'], 's' => (int) $juan['id']]) === 0) {
            Database::insert('organization_members', [
                'organization_id'  => (int) $csOrg['id'],
                'student_id'       => (int) $juan['id'],
                'academic_year_id' => $ay,
                'position_id'      => 1,
                'position_title'   => 'President',
                'status'           => 'active',
                'applied_at'       => Helpers::now(),
                'joined_at'        => Helpers::now(),
                'created_at'       => Helpers::now(),
            ]);
            out('2026-00001', 'CS membership restored (President)');
        } else {
            out('2026-00001', 'already a CS member');
        }
        if ($kyleRow !== null && Database::count('organization_members', 'organization_id = :o AND student_id = :s', ['o' => (int) $csOrg['id'], 's' => (int) $kyleRow['id']]) === 0) {
            Database::insert('organization_members', [
                'organization_id'  => (int) $csOrg['id'],
                'student_id'       => (int) $kyleRow['id'],
                'academic_year_id' => $ay,
                'status'           => 'active',
                'applied_at'       => Helpers::now(),
                'joined_at'        => Helpers::now(),
                'created_at'       => Helpers::now(),
            ]);
            out('2026-00503', 'CS membership restored (member)');
        } elseif ($kyleRow !== null) {
            out('2026-00503', 'already a CS member');
        }
    }

    echo "\nRebuild complete.\n";
} catch (Throwable $e) {
    echo 'EXCEPTION: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . PHP_EOL;
    exit(1);
}
