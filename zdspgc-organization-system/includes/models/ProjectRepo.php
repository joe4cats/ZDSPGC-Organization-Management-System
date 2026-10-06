<?php
/**
 * models/ProjectRepo.php — organization projects (activities) and their lifecycle.
 */

declare(strict_types=1);

final class ProjectRepo
{
    private const SELECT = 'SELECT p.*, o.name AS organization_name, o.acronym,
            ay.name AS academic_year_name, cu.full_name AS created_by_name,
            au.full_name AS adviser_name
            FROM projects p
            JOIN organizations o ON o.id = p.organization_id
            LEFT JOIN academic_years ay ON ay.id = p.academic_year_id
            LEFT JOIN users cu ON cu.id = p.created_by
            LEFT JOIN advisers ad ON ad.id = p.adviser_id
            LEFT JOIN users au ON au.id = ad.user_id';

    /**
     * @param array<string,mixed> $filters q, organization_id, status, academic_year_id, from, to
     * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int}
     */
    public static function list(array $filters, int $page = 1, int $perPage = 20): array
    {
        [$whereSql, $params] = self::where($filters);
        $where = ' WHERE ' . $whereSql;
        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM projects p JOIN organizations o ON o.id = p.organization_id' . $where,
            $params
        );
        $page = max(1, $page);

        return [
            'rows'  => Database::all(
                self::SELECT . ' ' . $where
                . ' ORDER BY FIELD(p.status, "proposed","approved","ongoing","completed","cancelled"), p.start_date DESC'
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
        return Database::one(self::SELECT . ' WHERE p.id = :id', ['id' => $id]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forOrganization(int $organizationId, int $limit = 100): array
    {
        return Database::all(
            self::SELECT . ' WHERE p.organization_id = :o ORDER BY p.start_date DESC LIMIT ' . (int) $limit,
            ['o' => $organizationId]
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        $row = [
            'organization_id'     => (int) $data['organization_id'],
            'academic_year_id'    => (int) ($data['academic_year_id'] ?? AcademicRepo::activeYearId()),
            'title'               => mb_substr(trim((string) ($data['title'] ?? '')), 0, 180),
            'description'         => (string) ($data['description'] ?? ''),
            'objectives'          => (string) ($data['objectives'] ?? ''),
            'target_participants' => mb_substr(trim((string) ($data['target_participants'] ?? '')), 0, 160),
            'budget'              => (string) ($data['budget'] ?? 0),
            'funding_source'      => mb_substr(trim((string) ($data['funding_source'] ?? '')), 0, 160),
            'start_date'          => ($data['start_date'] ?? null) !== '' && !empty($data['start_date']) ? (string) $data['start_date'] : null,
            'end_date'            => ($data['end_date'] ?? null) !== '' && !empty($data['end_date']) ? (string) $data['end_date'] : null,
            'status'              => in_array((string) ($data['status'] ?? 'proposed'), AcademicRepo::PROJECT_STATUSES, true)
                ? (string) $data['status'] : 'proposed',
            'adviser_id'          => !empty($data['adviser_id']) ? (int) $data['adviser_id'] : null,
            'created_by'          => Auth::id(),
            'created_at'          => Helpers::now(),
        ];
        if ($row['title'] === '') {
            throw new RuntimeException('A project needs a title.');
        }

        $id = Database::insert('projects', $row);
        Audit::log('PROJECT_CREATED', 'projects', 'Created project "' . $row['title'] . '"', $id);

        return $id;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $project = self::find($id);
        if ($project === null) {
            throw new RuntimeException('Project not found.');
        }

        $row = ['updated_at' => Helpers::now()];
        foreach (['title', 'target_participants', 'funding_source'] as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = mb_substr(trim((string) $data[$key]), 0, 180);
            }
        }
        foreach (['description', 'objectives'] as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = (string) $data[$key];
            }
        }
        if (array_key_exists('budget', $data)) {
            $row['budget'] = (string) $data['budget'];
        }
        foreach (['start_date', 'end_date'] as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = ($data[$key] ?? '') !== '' ? (string) $data[$key] : null;
            }
        }
        if (isset($row['title']) && trim((string) $row['title']) === '') {
            throw new RuntimeException('A project needs a title.');
        }

        Database::update('projects', $row, 'id = :id', ['id' => $id]);
        Audit::log('PROJECT_UPDATED', 'projects', 'Updated project #' . $id . ' — ' . (string) $project['title'], $id);
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, AcademicRepo::PROJECT_STATUSES, true)) {
            throw new InvalidArgumentException('Unknown project status.');
        }
        $project = self::find($id);
        if ($project === null) {
            throw new RuntimeException('Project not found.');
        }

        Database::update('projects', [
            'status'     => $status,
            'updated_at' => Helpers::now(),
        ], 'id = :id', ['id' => $id]);

        Audit::log('PROJECT_' . strtoupper($status), 'projects',
            'Project "' . $project['title'] . '" is now ' . $status, $id);

        Notifications::pushMany(
            Notifications::userIdsForOrganization((int) $project['organization_id'], true),
            'Project ' . ui_status($status),
            'The project "' . $project['title'] . '" is now ' . $status . '.',
            $status === 'approved' ? 'approval' : ($status === 'cancelled' ? 'rejection' : 'info'),
            $id,
            'project',
            'organization/projects.php'
        );
    }

    /** @return array<string,int> */
    public static function statusCounts(?int $organizationId = null): array
    {
        $counts = [];
        foreach (AcademicRepo::PROJECT_STATUSES as $status) {
            $counts[$status] = $organizationId === null
                ? Database::count('projects', 'status = :s', ['s' => $status])
                : Database::count('projects', 'status = :s AND organization_id = :o', ['s' => $status, 'o' => $organizationId]);
        }
        $counts['total'] = array_sum($counts);

        return $counts;
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
            $where[] = '(p.title LIKE :q OR p.description LIKE :q2 OR o.name LIKE :q3)';
            $params['q']  = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
        }
        foreach (['organization_id' => 'p.organization_id', 'status' => 'p.status',
                  'academic_year_id' => 'p.academic_year_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]             = $column . ' = :f_' . $key;
                $params['f_' . $key] = $filters[$key];
            }
        }
        if (!empty($filters['from'])) {
            $where[]        = 'p.start_date >= :from';
            $params['from'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]      = 'p.start_date <= :to';
            $params['to'] = (string) $filters['to'];
        }

        return [implode(' AND ', $where), $params];
    }
}
