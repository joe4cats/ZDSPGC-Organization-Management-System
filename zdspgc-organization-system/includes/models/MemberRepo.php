<?php
/**
 * models/MemberRepo.php — organization membership: applications, decisions and
 * the per-academic-year membership history.
 */

declare(strict_types=1);

final class MemberRepo
{
    private const SELECT = 'SELECT m.*, s.student_id, s.first_name, s.middle_name, s.last_name, s.course,
            s.year_level, s.section, d.name AS department_name, o.name AS organization_name, o.acronym,
            p.name AS position_name, ay.name AS academic_year_name
            FROM organization_members m
            JOIN students s ON s.id = m.student_id
            LEFT JOIN departments d ON d.id = s.department_id
            JOIN organizations o ON o.id = m.organization_id
            LEFT JOIN officer_positions p ON p.id = m.position_id
            LEFT JOIN academic_years ay ON ay.id = m.academic_year_id';

    /**
     * @param array<string,mixed> $filters organization_id, status, q, academic_year_id, officers_only
     * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int}
     */
    public static function list(array $filters, int $page = 1, int $perPage = 25): array
    {
        [$whereSql, $params] = self::where($filters);
        $where = ' WHERE ' . $whereSql;
        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM organization_members m JOIN students s ON s.id = m.student_id' . $where,
            $params
        );
        $page = max(1, $page);

        return [
            'rows'  => Database::all(
                self::SELECT . ' ' . $where
                . ' ORDER BY FIELD(m.status, "pending","active","suspended","inactive","rejected","archived"), s.last_name ASC'
                . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
                $params
            ),
            'total' => $total,
            'pages' => (int) max(1, ceil($total / max(1, $perPage))),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one(self::SELECT . ' WHERE m.id = :id', ['id' => $id]);
    }

    /**
     * A student's own memberships (all academic years).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forStudent(int $studentId): array
    {
        return Database::all(
            self::SELECT . ' WHERE m.student_id = :s ORDER BY ay.start_date DESC, m.id DESC',
            ['s' => $studentId]
        );
    }

    /**
     * Active members of an organization (for selects and pickers).
     *

    /**
     * Records a membership application (pending).
     *
     * @return array{ok:bool,message:string,id?:int}
     */
    public static function apply(int $organizationId, int $studentId, string $message = ''): array
    {
        $org = Database::one('SELECT * FROM organizations WHERE id = :id AND status = "active"', ['id' => $organizationId]);
        if ($org === null) {
            return ['ok' => false, 'message' => 'That organization is not accepting applications.'];
        }
        if (Database::count('students', 'id = :s AND status <> "archived"', ['s' => $studentId]) === 0) {
            return ['ok' => false, 'message' => 'Student record not found.'];
        }

        $yearId = AcademicRepo::activeYearId();
        $exists = Database::one(
            'SELECT * FROM organization_members WHERE organization_id = :o AND student_id = :s AND academic_year_id = :y',
            ['o' => $organizationId, 's' => $studentId, 'y' => $yearId]
        );
        if ($exists !== null) {
            return $exists['status'] === 'pending'
                ? ['ok' => false, 'message' => 'Your application is already pending review.']
                : ['ok' => false, 'message' => 'You are already a member of this organization for this academic year.'];
        }

        $id = Database::insert('organization_members', [
            'organization_id'  => $organizationId,
            'student_id'       => $studentId,
            'academic_year_id' => $yearId,
            'status'           => 'pending',
            'applied_at'       => Helpers::now(),
            'remarks'          => mb_substr($message, 0, 400),
            'created_at'       => Helpers::now(),
        ]);

        Audit::log('MEMBERSHIP_APPLIED', 'members', 'Applied for membership in ' . $org['name'], $id);
        Notifications::pushMany(
            Notifications::userIdsForOrganization($organizationId, true),
            'New membership application',
            'A student applied to join ' . $org['name'] . '. Review it in the members module.',
            'info',
            $id,
            'membership',
            'organization/members.php'
        );

        return ['ok' => true, 'message' => 'Your application was submitted to the officers of ' . $org['name'] . '.', 'id' => $id];
    }

    /**
     * Approves, rejects, suspends or reinstates a membership.
     */
    public static function decide(int $memberId, string $status, string $remarks = '', ?int $positionId = null): void
    {
        if (!in_array($status, AcademicRepo::MEMBER_STATUSES, true)) {
            throw new InvalidArgumentException('Unknown membership status.');
        }
        $member = self::find($memberId);
        if ($member === null) {
            throw new RuntimeException('Membership record not found.');
        }

        $row = [
            'status'     => $status,
            'remarks'    => mb_substr($remarks, 0, 400),
            'decided_by' => Auth::id(),
            'decided_at' => Helpers::now(),
            'updated_at' => Helpers::now(),
        ];
        if ($status === 'active') {
            $row['joined_at'] = Helpers::now();
        }
        if ($positionId !== null) {
            $row['position_id']    = $positionId > 0 ? $positionId : null;
            $row['position_title'] = $positionId > 0
                ? (string) Database::value('SELECT name FROM officer_positions WHERE id = :p', ['p' => $positionId], '')
                : '';
        }
        Database::update('organization_members', $row, 'id = :id', ['id' => $memberId]);
        Audit::log('MEMBER_' . strtoupper($status), 'members', 'Membership decision #' . $memberId . ': ' . $status, $memberId);

        $messages = [
            'active'    => 'Your membership application to ' . $member['organization_name'] . ' was approved.',
            'rejected'  => 'Your membership application to ' . $member['organization_name'] . ' was rejected. ' . $remarks,
            'suspended' => 'Your membership in ' . $member['organization_name'] . ' has been suspended. ' . $remarks,
            'inactive'  => 'Your membership in ' . $member['organization_name'] . ' was closed.',
            'archived'  => 'Your membership record was archived.',
        ];
        Notifications::notifyStudent(
            (int) $member['student_id'],
            'Membership ' . ui_status($status),
            $messages[$status] ?? ('Your membership status is now ' . $status . '.'),
            $status === 'active' ? 'approval' : (in_array($status, ['rejected', 'suspended'], true) ? 'rejection' : 'warning'),
            $memberId,
            'membership',
            'student/my-organizations.php'
        );
    }

    /** Removes (deactivates) a membership while keeping the history row. */
    public static function remove(int $memberId, string $reason = ''): void
    {
        self::decide($memberId, 'inactive', $reason !== '' ? $reason : 'Removed from the organization.');
    }

    /** @return array<string,int> */
    public static function countByOrganization(int $organizationId, ?int $yearId = null): array
    {
        $yearId ??= AcademicRepo::activeYearId();
        return [
            'active'   => Database::count('organization_members', 'organization_id = :o AND academic_year_id = :y AND status = "active"', ['o' => $organizationId, 'y' => $yearId]),
            'pending'  => Database::count('organization_members', 'organization_id = :o AND academic_year_id = :y AND status = "pending"', ['o' => $organizationId, 'y' => $yearId]),
            'officers' => Database::count('organization_members', 'organization_id = :o AND academic_year_id = :y AND status = "active" AND position_id IS NOT NULL', ['o' => $organizationId, 'y' => $yearId]),
            'total'    => Database::count('organization_members', 'organization_id = :o AND academic_year_id = :y', ['o' => $organizationId, 'y' => $yearId]),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function where(array $filters): array
    {
        $where  = ['1'];
        $params = [];

        if (!empty($filters['q'])) {
            $where[] = '(s.student_id LIKE :q OR s.first_name LIKE :q2 OR s.last_name LIKE :q3
                         OR CONCAT(s.first_name, " ", s.last_name) LIKE :q4)';
            foreach (['q', 'q2', 'q3', 'q4'] as $key) {
                $params[$key] = '%' . $filters['q'] . '%';
            }
        }
        foreach (['organization_id' => 'm.organization_id', 'status' => 'm.status',
                  'academic_year_id' => 'm.academic_year_id', 'student_id' => 'm.student_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]             = $column . ' = :f_' . $key;
                $params['f_' . $key] = $filters[$key];
            }
        }
        if (!empty($filters['officers_only'])) {
            $where[] = 'm.position_id IS NOT NULL';
        }

        return [implode(' AND ', $where), $params];
    }
}
