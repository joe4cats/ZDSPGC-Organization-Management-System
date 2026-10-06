<?php
/**
 * models/AnnouncementRepo.php — announcements: create, publish and the
 * audience-aware feed each role sees.
 */

declare(strict_types=1);

final class AnnouncementRepo
{
    private const SELECT = 'SELECT a.*, u.full_name AS author_name,
            o.name AS organization_name, o.acronym, d.name AS department_name
            FROM announcements a
            LEFT JOIN users u ON u.id = a.author_id
            LEFT JOIN organizations o ON o.id = a.organization_id
            LEFT JOIN departments d ON d.id = a.audience_department_id';

    /**
     * @param array<string,mixed> $filters q, organization_id, audience, status, author_id
     * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int}
     */
    public static function list(array $filters, int $page = 1, int $perPage = 20): array
    {
        [$whereSql, $params] = self::where($filters);
        $where = ' WHERE ' . $whereSql;
        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM announcements a LEFT JOIN organizations o ON o.id = a.organization_id' . $where,
            $params
        );
        $page = max(1, $page);

        return [
            'rows'  => Database::all(
                self::SELECT . ' ' . $where
                . ' ORDER BY a.is_pinned DESC, a.publish_date DESC, a.id DESC'
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
        return Database::one(self::SELECT . ' WHERE a.id = :id', ['id' => $id]);
    }

    /**
     * Published announcements addressed to a viewer.
     *
     * $organizationIds = organizations the viewer belongs to / manages,
     * $departmentId    = the viewer's department (0 = none),
     * $seeAll          = true for administrators and advisers.
     *
     * @param array<int,int> $organizationIds
     * @return array<int,array<string,mixed>>
     */
    public static function feed(
        array $organizationIds = [],
        int $departmentId = 0,
        bool $seeAll = false,
        int $limit = 50
    ): array {
        $where = [
            "a.status = 'published'",
            'a.publish_date <= NOW()',
            '(a.expiration_date IS NULL OR a.expiration_date >= NOW())',
        ];
        $params = [];

        if (!$seeAll) {
            $parts = ['a.audience = "all"'];
            if ($departmentId > 0) {
                $parts[] = 'a.audience = "department" AND a.audience_department_id = :dept';
                $params['dept'] = $departmentId;
            }
            if ($organizationIds !== []) {
                $in = [];
                foreach (array_values(array_unique($organizationIds)) as $i => $orgId) {
                    $key                 = ':org' . $i;
                    $in[]                = $key;
                    $params[$key]        = (int) $orgId;
                }
                $parts[] = 'a.audience IN ("organization","officers","members") AND a.organization_id IN ('
                    . implode(', ', $in) . ')';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }

        return Database::all(
            self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY a.is_pinned DESC, a.publish_date DESC LIMIT ' . (int) $limit,
            $params
        );
    }

    /**
     * @param array<string,mixed> $data title, content, audience, organization_id,
     *                                   audience_department_id, publish_date,
     *                                   expiration_date, status, is_pinned
     */
    public static function create(array $data): int
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('An announcement needs a title.');
        }
        $audience = (string) ($data['audience'] ?? 'all');
        if (!in_array($audience, AcademicRepo::AUDIENCES, true)) {
            throw new InvalidArgumentException('Unknown audience.');
        }
        $organizationId = $audience === 'all' ? null
            : (!empty($data['organization_id']) ? (int) $data['organization_id'] : null);
        if (in_array($audience, ['organization', 'officers', 'members'], true) && $organizationId === null) {
            throw new RuntimeException('Choose the organization this announcement is for.');
        }
        $departmentId = $audience === 'department'
            ? (!empty($data['audience_department_id']) ? (int) $data['audience_department_id'] : 0) : 0;
        if ($audience === 'department' && $departmentId === 0) {
            throw new RuntimeException('Choose the department this announcement is for.');
        }

        $id = Database::insert('announcements', [
            'title'                 => mb_substr($title, 0, 180),
            'content'               => (string) ($data['content'] ?? ''),
            'author_id'             => Auth::id(),
            'organization_id'       => $organizationId,
            'audience'              => $audience,
            'audience_department_id' => $departmentId > 0 ? $departmentId : null,
            'publish_date'          => !empty($data['publish_date']) ? (string) $data['publish_date'] : Helpers::now(),
            'expiration_date'       => !empty($data['expiration_date']) ? (string) $data['expiration_date'] : null,
            'status'                => in_array((string) ($data['status'] ?? 'published'), ['draft', 'published', 'archived'], true)
                ? (string) $data['status'] : 'published',
            'is_pinned'             => !empty($data['is_pinned']) ? 1 : 0,
            'created_at'            => Helpers::now(),
        ]);

        Audit::log('ANNOUNCEMENT_CREATED', 'announcements', 'Posted announcement "' . $title . '"', $id);
        self::notify($id, 'New announcement', $title);

        return $id;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $row = ['updated_at' => Helpers::now()];
        if (array_key_exists('title', $data)) {
            $row['title'] = mb_substr(trim((string) $data['title']), 0, 180);
            if (trim((string) $row['title']) === '') {
                throw new RuntimeException('An announcement needs a title.');
            }
        }
        foreach (['content', 'publish_date'] as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = (string) $data[$key];
            }
        }
        if (array_key_exists('expiration_date', $data)) {
            $row['expiration_date'] = ($data['expiration_date'] ?? '') !== '' ? (string) $data['expiration_date'] : null;
        }
        if (array_key_exists('is_pinned', $data)) {
            $row['is_pinned'] = !empty($data['is_pinned']) ? 1 : 0;
        }

        Database::update('announcements', $row, 'id = :id', ['id' => $id]);
        Audit::log('ANNOUNCEMENT_UPDATED', 'announcements', 'Edited announcement #' . $id, $id);
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new InvalidArgumentException('Unknown announcement status.');
        }
        $announcement = self::find($id);
        if ($announcement === null) {
            throw new RuntimeException('Announcement not found.');
        }

        Database::update('announcements', [
            'status'     => $status,
            'updated_at' => Helpers::now(),
        ], 'id = :id', ['id' => $id]);

        Audit::log('ANNOUNCEMENT_' . strtoupper($status), 'announcements',
            'Announcement "' . $announcement['title'] . '" is now ' . $status, $id);

        if ($status === 'published') {
            self::notify($id, 'New announcement', (string) $announcement['title']);
        }
    }

    /** Fans the announcement out to the accounts its audience selects. */
    private static function notify(int $id, string $title, string $message): void
    {
        $row = self::find($id);
        if ($row === null || $row['status'] !== 'published') {
            return;
        }

        $userIds = [];
        switch ((string) $row['audience']) {
            case 'all':
                $userIds = array_merge(
                    Notifications::userIdsForRole('student'),
                    Notifications::userIdsForRole('officer')
                );
                break;
            case 'department':
                $userIds = array_map('intval', array_column(Database::all(
                    'SELECT user_id FROM students WHERE department_id = :d AND status = "active"',
                    ['d' => (int) $row['audience_department_id']]
                ), 'user_id'));
                break;
            default:
                if (!empty($row['organization_id'])) {
                    $userIds = Notifications::userIdsForOrganization((int) $row['organization_id'],
                        (string) $row['audience'] === 'officers');
                }
                break;
        }
        if ($userIds === []) {
            return;
        }
        Notifications::pushForSection($userIds, 'announcements', $title, $message, 'info', $id, 'announcement');
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
            $where[] = '(a.title LIKE :q OR a.content LIKE :q2 OR o.name LIKE :q3)';
            $params['q']  = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
        }
        foreach (['organization_id' => 'a.organization_id', 'audience' => 'a.audience',
                  'status' => 'a.status', 'author_id' => 'a.author_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]             = $column . ' = :f_' . $key;
                $params['f_' . $key] = $filters[$key];
            }
        }

        return [implode(' AND ', $where), $params];
    }
}
