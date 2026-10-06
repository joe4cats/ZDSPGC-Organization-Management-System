<?php
/**
 * models/OfficerRepo.php — organization officers, their terms and the history
 * kept per academic year.
 */

declare(strict_types=1);

final class OfficerRepo
{
    private const SELECT = 'SELECT oc.*, p.name AS position_name, p.sort_order,
            s.student_id, s.first_name, s.middle_name, s.last_name, s.course, s.year_level, s.section,
            d.name AS department_name, ay.name AS academic_year_name, o.name AS organization_name,
            o.acronym AS organization_acronym, u.full_name AS appointed_by_name
            FROM organization_officers oc
            JOIN students s ON s.id = oc.student_id
            LEFT JOIN departments d ON d.id = s.department_id
            JOIN officer_positions p ON p.id = oc.position_id
            JOIN academic_years ay ON ay.id = oc.academic_year_id
            JOIN organizations o ON o.id = oc.organization_id
            LEFT JOIN users u ON u.id = oc.appointed_by';

    /**
     * @param array<string,mixed> $filters organization_id, academic_year_id, status, position_id, q
     * @return array<int,array<string,mixed>>
     */
    public static function list(array $filters = [], int $limit = 500): array
    {
        $where  = ['1'];
        $params = [];

        if (!empty($filters['organization_id'])) {
            $where[]       = 'oc.organization_id = :org';
            $params['org'] = (int) $filters['organization_id'];
        }
        if (!empty($filters['academic_year_id'])) {
            $where[]   = 'oc.academic_year_id = :y';
            $params['y'] = (int) $filters['academic_year_id'];
        }
        if (!empty($filters['status'])) {
            $where[]    = 'oc.status = :st';
            $params['st'] = (string) $filters['status'];
        }
        if (!empty($filters['position_id'])) {
            $where[]    = 'oc.position_id = :p';
            $params['p'] = (int) $filters['position_id'];
        }
        if (!empty($filters['q'])) {
            $where[]        = '(s.first_name LIKE :q OR s.last_name LIKE :q2 OR s.student_id LIKE :q3)';
            $params['q']    = '%' . $filters['q'] . '%';
            $params['q2']   = '%' . $filters['q'] . '%';
            $params['q3']   = '%' . $filters['q'] . '%';
        }

        return Database::all(
            self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY p.sort_order ASC, s.last_name ASC LIMIT ' . (int) max(1, min(2000, $limit)),
            $params
        );
    }

    /** Current officers of an organization. @return array<int,array<string,mixed>> */
    public static function current(int $organizationId, ?int $yearId = null): array
    {
        $yearId ??= AcademicRepo::activeYearId();
        return self::list(['organization_id' => $organizationId, 'academic_year_id' => $yearId, 'status' => 'active']);
    }

    /**
     * Assigns a member as an officer and syncs the membership position so the
     * student gains access to the organization workspace.
     *
     * @param array<string,string> $terms term, start_date, end_date
     * @return int the new officer id
     */
    public static function assign(int $organizationId, int $studentId, int $positionId, array $terms = []): int
    {
        $yearId = AcademicRepo::activeYearId();

        $member = Database::one(
            'SELECT * FROM organization_members WHERE organization_id = :o AND student_id = :s AND academic_year_id = :y',
            ['o' => $organizationId, 's' => $studentId, 'y' => $yearId]
        );
        if ($member === null || $member['status'] !== 'active') {
            throw new RuntimeException('Only an active member can be appointed as an officer.');
        }
        if (Database::count('officer_positions', 'id = :p AND status = "active"', ['p' => $positionId]) === 0) {
            throw new RuntimeException('Unknown officer position.');
        }

        $existing = Database::one(
            'SELECT id FROM organization_officers WHERE organization_id = :o AND student_id = :s AND position_id = :p AND academic_year_id = :y',
            ['o' => $organizationId, 's' => $studentId, 'p' => $positionId, 'y' => $yearId]
        );
        if ($existing !== null) {
            Database::update('organization_officers', ['status' => 'active'], 'id = :id', ['id' => (int) $existing['id']]);
            $officerId = (int) $existing['id'];
        } else {
            $officerId = Database::insert('organization_officers', [
                'organization_id'  => $organizationId,
                'student_id'       => $studentId,
                'position_id'      => $positionId,
                'academic_year_id' => $yearId,
                'term'             => (string) ($terms['term'] ?? ''),
                'start_date'       => empty($terms['start_date']) ? Helpers::today() : (string) $terms['start_date'],
                'end_date'         => Database::nullableDateTime($terms['end_date'] ?? null),
                'status'           => 'active',
                'appointed_by'     => Auth::id(),
                'created_at'       => Helpers::now(),
            ]);
        }

        $positionName = (string) Database::value('SELECT name FROM officer_positions WHERE id = :p', ['p' => $positionId], '');
        Database::update('organization_members', [
            'position_id'    => $positionId,
            'position_title' => $positionName,
            'updated_at'     => Helpers::now(),
        ], 'id = :id', ['id' => (int) $member['id']]);

        $userId = Database::value('SELECT user_id FROM students WHERE id = :s', ['s' => $studentId]);
        if ($userId !== null && Database::value('SELECT role FROM users WHERE id = :u', ['u' => (int) $userId]) === 'student') {
            Database::update('users', ['role' => 'officer', 'updated_at' => Helpers::now()], 'id = :u', ['u' => (int) $userId]);
        }

        Audit::log('OFFICER_ASSIGNED', 'officers', 'Appointed ' . $positionName . ' for student #' . $studentId, $officerId);
        return $officerId;
    }

    /** Ends an officer's term and clears the membership position. */
    public static function end(int $officerId, string $endDate = '', string $notes = ''): void
    {
        $officer = self::find($officerId);
        if ($officer === null) {
            throw new RuntimeException('Officer record not found.');
        }
        Database::update('organization_officers', [
            'status'   => 'ended',
            'end_date' => $endDate !== '' ? $endDate : Helpers::today(),
            'notes'    => mb_substr($notes, 0, 255),
        ], 'id = :id', ['id' => $officerId]);

        Database::update('organization_members', [
            'position_id'    => null,
            'position_title' => '',
            'updated_at'     => Helpers::now(),
        ], 'organization_id = :o AND student_id = :s AND position_id = :p', [
            'o' => (int) $officer['organization_id'],
            's' => (int) $officer['student_id'],
            'p' => (int) $officer['position_id'],
        ]);

        Audit::log('OFFICER_ENDED', 'officers', 'Ended the term of officer #' . $officerId, $officerId);
    }

    /** @return array<string,int> */
    public static function countByOrganization(int $organizationId, ?int $yearId = null): array
    {
        $yearId ??= AcademicRepo::activeYearId();
        return [
            'active'  => Database::count('organization_officers', 'organization_id = :o AND academic_year_id = :y AND status = "active"', ['o' => $organizationId, 'y' => $yearId]),
            'ended'   => Database::count('organization_officers', 'organization_id = :o AND academic_year_id = :y AND status <> "active"', ['o' => $organizationId, 'y' => $yearId]),
            'history' => Database::count('organization_officers', 'organization_id = :o AND academic_year_id <> :y', ['o' => $organizationId, 'y' => $yearId]),
        ];
    }

    /** Officers of previous academic years (history). @return array<int,array<string,mixed>> */
    public static function history(int $organizationId, ?int $yearId = null): array
    {
        $yearId ??= AcademicRepo::activeYearId();
        return Database::all(
            self::SELECT . ' WHERE oc.organization_id = :org AND oc.academic_year_id <> :y
                     ORDER BY ay.start_date DESC, p.sort_order ASC',
            ['org' => $organizationId, 'y' => $yearId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one(self::SELECT . ' WHERE oc.id = :id', ['id' => $id]);
    }
}
