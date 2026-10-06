<?php
/**
 * models/OrgRepo.php — organizations: listing, registration, status changes,
 * accreditation and the dashboard counters.
 */

declare(strict_types=1);

final class OrgRepo
{
    private const SELECT = 'SELECT o.*, c.name AS category_name, d.name AS department_name,
            au.full_name AS adviser_name, CONCAT(s.first_name, " ", s.last_name) AS president_name,
            ay.name AS academic_year_name,
            (SELECT COUNT(*) FROM organization_members m WHERE m.organization_id = o.id AND m.status = "active") AS member_count,
            (SELECT COUNT(*) FROM organization_members m WHERE m.organization_id = o.id AND m.status = "pending") AS pending_members,
            (SELECT COUNT(*) FROM organization_officers oc WHERE oc.organization_id = o.id AND oc.status = "active") AS officer_count
            FROM organizations o
            LEFT JOIN organization_categories c ON c.id = o.category_id
            LEFT JOIN departments d ON d.id = o.department_id
            LEFT JOIN advisers a ON a.id = o.adviser_id
            LEFT JOIN users au ON au.id = a.user_id
            LEFT JOIN students s ON s.id = o.president_id
            LEFT JOIN academic_years ay ON ay.id = o.academic_year_id';

    /**
     * @param array<string,mixed> $filters q, status, category_id, department_id, adviser_id,
     *                                   organization_type, academic_year_id, adviser_user_id
     * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int}
     */
    public static function list(array $filters, int $page = 1, int $perPage = 20): array
    {
        [$whereSql, $params] = self::where($filters);
        $where = ' WHERE ' . $whereSql;
        $total = (int) Database::scalar('SELECT COUNT(*) FROM organizations o' . $where, $params);
        $page  = max(1, $page);
        return [
            'rows'  => Database::all(
                self::SELECT . ' ' . $where
                . ' ORDER BY FIELD(o.status, "pending","active","suspended","expired","rejected","archived"), o.name ASC'
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
        return Database::one(self::SELECT . ' WHERE o.id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByCode(string $code): ?array
    {
        return Database::one(self::SELECT . ' WHERE o.organization_code = :c', ['c' => $code]);
    }

    /**
     * Public directory: active organizations with display-safe columns only.
     *
     * @param array<string,mixed> $filters q, category_id, department_id, organization_type
     * @return array<int,array<string,mixed>>
     */
    public static function publicList(array $filters = [], int $limit = 60): array
    {
        [$whereSql, $params] = self::where($filters);
        $where = [$whereSql, "o.status = 'active'"];
        $sql = 'SELECT o.id, o.organization_code, o.name, o.acronym, o.description, o.organization_type,
                       o.logo, o.accreditation_status, o.date_established,
                       c.name AS category_name, d.name AS department_name, au.full_name AS adviser_name,
                       (SELECT COUNT(*) FROM organization_members m
                         WHERE m.organization_id = o.id AND m.status = "active") AS member_count
                FROM organizations o
                LEFT JOIN organization_categories c ON c.id = o.category_id
                LEFT JOIN departments d ON d.id = o.department_id
                LEFT JOIN advisers a ON a.id = o.adviser_id
                LEFT JOIN users au ON au.id = a.user_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY o.name ASC LIMIT ' . (int) max(1, min(200, $limit));
        return Database::all($sql, $params);
    }

    /** One active organization, public columns only. @return array<string,mixed>|null */
    public static function findPublic(int $id): ?array
    {
        return Database::one(
            'SELECT o.id, o.organization_code, o.name, o.acronym, o.description, o.organization_type,
                    o.logo, o.accreditation_status, o.date_established, o.created_at,
                    o.contact_email, o.contact_number, o.social_link,
                    c.name AS category_name, d.name AS department_name, au.full_name AS adviser_name
             FROM organizations o
             LEFT JOIN organization_categories c ON c.id = o.category_id
             LEFT JOIN departments d ON d.id = o.department_id
             LEFT JOIN advisers a ON a.id = o.adviser_id
             LEFT JOIN users au ON au.id = a.user_id
             WHERE o.id = :id AND o.status = "active"',
            ['id' => $id]
        );
    }

    /**
     * Registers a new organization (status pending until an administrator approves).
     *
     * @param array<string,mixed> $data
     * @return int the new id
     */
    public static function create(array $data): int
    {
        $id = Database::insert('organizations', [
            'organization_code' => self::nextCode((string) ($data['acronym'] ?? $data['name'] ?? 'ORG')),
            'name'              => (string) $data['name'],
            'acronym'           => (string) ($data['acronym'] ?? ''),
            'description'       => (string) ($data['description'] ?? ''),
            'organization_type' => (string) ($data['organization_type'] ?? 'Academic'),
            'category_id'       => empty($data['category_id']) ? null : (int) $data['category_id'],
            'department_id'     => empty($data['department_id']) ? null : (int) $data['department_id'],
            'adviser_id'        => empty($data['adviser_id']) ? null : (int) $data['adviser_id'],
            'contact_email'     => (string) ($data['contact_email'] ?? ''),
            'contact_number'    => (string) ($data['contact_number'] ?? ''),
            'social_link'       => (string) ($data['social_link'] ?? ''),
            'logo'              => (string) ($data['logo'] ?? ''),
            'date_established'  => empty($data['date_established']) ? null : (string) $data['date_established'],
            'status'            => (string) ($data['status'] ?? 'pending'),
            'academic_year_id'  => empty($data['academic_year_id']) ? AcademicRepo::activeYearId() : (int) $data['academic_year_id'],
            'submitted_at'      => Helpers::now(),
            'created_by'        => Auth::id(),
            'created_at'        => Helpers::now(),
        ]);

        Audit::log('ORGANIZATION_REGISTERED', 'organizations', 'Registered organization: ' . (string) $data['name'], $id);
        Notifications::notifyAdmins(
            'Organization registration pending',
            ((string) ($data['name'] ?? 'An organization')) . ' submitted a registration application.',
            'info',
            $id,
            'organization',
            'admin/organizations.php'
        );
        return $id;
    }

    /**
     * Updates the editable organization fields.
     *
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $row = [];
        foreach (['name', 'acronym', 'description', 'organization_type', 'contact_email', 'contact_number',
                  'social_link', 'logo', 'date_established', 'notes'] as $column) {
            if (array_key_exists($column, $data)) {
                $row[$column] = $data[$column];
            }
        }
        foreach (['category_id', 'department_id', 'adviser_id'] as $column) {
            if (array_key_exists($column, $data)) {
                $row[$column] = ($data[$column] === '' || $data[$column] === null) ? null : (int) $data[$column];
            }
        }
        if ($row === []) {
            return;
        }
        $row['updated_at'] = Helpers::now();
        Database::update('organizations', $row, 'id = :id', ['id' => $id]);
        Audit::log('ORGANIZATION_UPDATED', 'organizations', 'Updated organization #' . $id, $id);
    }

    /** Builds a unique organization code from the acronym. */
    public static function nextCode(string $acronym): string
    {
        $slug = strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', $acronym), 0, 12)) ?: 'ORG';
        $base = 'ORG-' . $slug;
        $code = $base;
        $n    = 1;
        while (Database::count('organizations', 'organization_code = :c', ['c' => $code]) > 0) {
            $code = $base . '-' . (++$n);
        }
        return $code;
    }

    /**
     * Changes the registration status (approve, reject, suspend, archive…).
     * Writes an audit entry and notifies the organization's officers.
     */
    public static function setStatus(int $id, string $status, string $reason = ''): void
    {
        if (!in_array($status, AcademicRepo::ORGANIZATION_STATUSES, true)) {
            throw new InvalidArgumentException('Unknown organization status: ' . $status);
        }
        $org = Database::one('SELECT * FROM organizations WHERE id = :id', ['id' => $id]);
        if ($org === null) {
            throw new RuntimeException('Organization not found.');
        }

        $row = ['status' => $status, 'updated_at' => Helpers::now()];
        if ($status === 'active') {
            $row['approved_by']      = Auth::id();
            $row['approved_at']      = Helpers::now();
            $row['rejection_reason'] = '';
        }
        if ($status === 'rejected' || $status === 'suspended') {
            $row['rejection_reason'] = mb_substr($reason, 0, 400);
        }
        if ($status === 'archived') {
            $row['archived_at'] = Helpers::now();
        }
        Database::update('organizations', $row, 'id = :id', ['id' => $id]);
        Audit::log('ORGANIZATION_' . strtoupper($status), 'organizations', trim($status . ' — ' . $reason), $id);

        $message = match ($status) {
            'active'    => $org['name'] . ' was approved and is now active.',
            'rejected'  => $org['name'] . ' was rejected. ' . $reason,
            'suspended' => $org['name'] . ' was suspended. ' . $reason,
            'archived'  => $org['name'] . ' was archived.',
            default     => $org['name'] . ' is now ' . $status . '.',
        };
        Notifications::pushMany(
            Notifications::userIdsForOrganization($id, true),
            'Organization status: ' . ui_status($status),
            $message,
            $status === 'active' ? 'approval' : (in_array($status, ['rejected', 'suspended'], true) ? 'rejection' : 'info'),
            $id,
            'organization',
            'organization/profile.php'
        );
    }

    /** @return array<string,int> */
    public static function dashboardStats(): array
    {
        return [
            'total'             => Database::count('organizations'),
            'active'            => Database::count('organizations', 'status = :s', ['s' => 'active']),
            'pending'           => Database::count('organizations', 'status = :s', ['s' => 'pending']),
            'suspended'         => Database::count('organizations', 'status = :s', ['s' => 'suspended']),
            'archived'          => Database::count('organizations', 'status = :s', ['s' => 'archived']),
            'members'           => Database::count('organization_members', 'status = :s', ['s' => 'active']),
            'students'          => Database::count('students', 'status = :s', ['s' => 'active']),
            'upcoming_events'   => (int) Database::scalar("SELECT COUNT(*) FROM events WHERE status = 'approved' AND event_date >= CURDATE()"),
            'pending_proposals' => (int) Database::scalar("SELECT COUNT(*) FROM proposals WHERE status IN ('submitted','under_review')"),
            'pending_documents' => (int) Database::scalar("SELECT COUNT(*) FROM documents WHERE status = 'pending'"),
        ];
    }

    /** @return array<string,int> status => count */
    public static function statusBreakdown(): array
    {
        $out = array_fill_keys(AcademicRepo::ORGANIZATION_STATUSES, 0);
        foreach (Database::all('SELECT status, COUNT(*) AS c FROM organizations GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }
        return $out;
    }

    /** @return array<string,int> category name => active organizations */
    public static function categoryBreakdown(): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT COALESCE(c.name, 'Uncategorised') AS label, COUNT(*) AS c
               FROM organizations o
               LEFT JOIN organization_categories c ON c.id = o.category_id
              WHERE o.status = 'active' GROUP BY label ORDER BY c DESC"
        ) as $row) {
            $out[(string) $row['label']] = (int) $row['c'];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentlyRegistered(int $limit = 5): array
    {
        return Database::all(self::SELECT . ' ORDER BY o.created_at DESC LIMIT ' . (int) $limit);
    }

    public static function countForAdviser(int $adviserId): int
    {
        return Database::count('organizations', 'adviser_id = :a', ['a' => $adviserId]);
    }

    /** @return array<string,mixed>|null the latest accreditation application */
    public static function latestAccreditation(int $organizationId): ?array
    {
        return Database::one(
            'SELECT aa.*, ay.name AS academic_year_name, au.full_name AS reviewer_name
               FROM accreditation_applications aa
               LEFT JOIN academic_years ay ON ay.id = aa.academic_year_id
               LEFT JOIN users au ON au.id = aa.reviewed_by
              WHERE aa.organization_id = :o ORDER BY aa.submitted_at DESC LIMIT 1',
            ['o' => $organizationId]
        );
    }

    /** Submits a new accreditation application for an organization. */
    public static function submitAccreditation(int $organizationId): int
    {
        $id = Database::insert('accreditation_applications', [
            'organization_id'  => $organizationId,
            'academic_year_id' => AcademicRepo::activeYearId(),
            'submitted_by'     => Auth::id(),
            'status'           => 'pending',
            'submitted_at'     => Helpers::now(),
            'created_at'       => Helpers::now(),
        ]);
        Database::update('organizations', [
            'accreditation_status' => 'pending',
            'updated_at'           => Helpers::now(),
        ], 'id = :id', ['id' => $organizationId]);

        Audit::log('ACCREDITATION_SUBMITTED', 'accreditation', 'Submitted accreditation application', $organizationId);
        Notifications::notifyAdmins(
            'Accreditation application submitted',
            'A new accreditation application is waiting for review.',
            'info',
            $organizationId,
            'organization',
            'admin/organizations.php'
        );
        return $id;
    }

    /**
     * Records an accreditation decision and mirrors it on the organization.
     */
    public static function decideAccreditation(int $applicationId, string $decision, string $notes = '', ?string $expiresAt = null): void
    {
        if (!in_array($decision, ['under_review', 'approved', 'revision_required', 'rejected'], true)) {
            throw new InvalidArgumentException('Unknown accreditation decision.');
        }
        $app = Database::one('SELECT * FROM accreditation_applications WHERE id = :id', ['id' => $applicationId]);
        if ($app === null) {
            throw new RuntimeException('Accreditation application not found.');
        }

        Database::update('accreditation_applications', [
            'status'         => $decision,
            'reviewed_by'    => Auth::id(),
            'reviewed_at'    => Helpers::now(),
            'decision_notes' => mb_substr($notes, 0, 600),
            'expires_at'     => $expiresAt,
        ], 'id = :id', ['id' => $applicationId]);

        Database::update('organizations', [
            'accreditation_status'     => $decision,
            'accreditation_expires_at' => $expiresAt,
            'updated_at'               => Helpers::now(),
        ], 'id = :id', ['id' => (int) $app['organization_id']]);

        Audit::log('ACCREDITATION_' . strtoupper($decision), 'accreditation', trim($decision . ' — ' . $notes), (int) $app['organization_id']);
        Notifications::pushMany(
            Notifications::userIdsForOrganization((int) $app['organization_id'], true),
            'Accreditation ' . ui_status($decision),
            trim('The accreditation application was ' . $decision . '. ' . $notes),
            $decision === 'approved' ? 'approval' : ($decision === 'rejected' ? 'rejection' : 'info'),
            (int) $app['organization_id'],
            'organization',
            'organization/profile.php'
        );
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
            $where[] = '(o.name LIKE :q OR o.acronym LIKE :q2 OR o.organization_code LIKE :q3)';
            $params['q']  = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
        }
        foreach (['status' => 'o.status', 'category_id' => 'o.category_id', 'department_id' => 'o.department_id',
                  'adviser_id' => 'o.adviser_id', 'organization_type' => 'o.organization_type',
                  'academic_year_id' => 'o.academic_year_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]             = $column . ' = :f_' . $key;
                $params['f_' . $key] = $filters[$key];
            }
        }
        if (!empty($filters['adviser_user_id'])) {
            $where[]         = 'o.adviser_id = (SELECT id FROM advisers WHERE user_id = :adv_u)';
            $params['adv_u'] = (int) $filters['adviser_user_id'];
        }

        return [implode(' AND ', $where), $params];
    }
}
