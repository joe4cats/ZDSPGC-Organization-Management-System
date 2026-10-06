<?php
/**
 * Audit.php — append-only activity log (audit trail).
 *
 * Every meaningful action (sign-in, approval, upload, deletion…) is recorded
 * here with the acting user, module, IP address and a short description.
 * Logging never throws: an audit failure must not break a user action.
 */

declare(strict_types=1);

final class Audit
{
    public const MODULES = [
        'auth', 'students', 'organizations', 'members', 'officers', 'events',
        'attendance', 'projects', 'proposals', 'documents', 'announcements',
        'academic', 'users', 'settings', 'accreditation', 'system',
    ];

    public static function log(
        string $action,
        string $module,
        string $description = '',
        ?int $referenceId = null,
        ?int $userId = null
    ): void {
        try {
            $userId ??= Auth::id();
            Database::insert('activity_logs', [
                'user_id'      => $userId,
                'actor_name'   => Auth::userName() ?? 'guest',
                'actor_role'   => Auth::role() ?? '-',
                'action'       => mb_substr(strtoupper($action), 0, 60),
                'module'       => mb_substr($module, 0, 40),
                'reference_id' => $referenceId,
                'description'  => mb_substr($description, 0, 480),
                'ip_address'   => Security::ip(),
                'user_agent'   => Security::userAgent(),
                'created_at'   => Helpers::now(),
            ]);
        } catch (Throwable $e) {
            // Never let logging break the request.
        }
    }

    /**
     * @param array{user?:string,module?:string,action?:string,from?:string,to?:string,q?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int}
     */
    public static function search(array $filters, int $page = 1, int $perPage = 30): array
    {
        [$where, $params] = self::buildWhere($filters);
        $total = (int) Database::scalar('SELECT COUNT(*) FROM activity_logs WHERE ' . $where, $params);
        $page  = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $rows = Database::all(
            'SELECT * FROM activity_logs WHERE ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'pages' => (int) max(1, ceil($total / $perPage))];
    }

    /** @param array<string,string|null> $filters @return array{0:string,1:array<string,mixed>} */
    private static function buildWhere(array $filters): array
    {
        $where  = ['1'];
        $params = [];

        if (!empty($filters['module'])) {
            $where[] = 'module = :module';
            $params['module'] = (string) $filters['module'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params['action'] = strtoupper((string) $filters['action']);
        }
        if (!empty($filters['user'])) {
            $where[] = '(actor_name LIKE :user OR user_id = :user_id)';
            $params['user']    = '%' . $filters['user'] . '%';
            $params['user_id'] = (int) $filters['user'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'created_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'created_at <= :to';
            $params['to'] = $filters['to'] . ' 23:59:59';
        }
        if (!empty($filters['q'])) {
            $where[] = '(description LIKE :q OR action LIKE :q2 OR module LIKE :q3)';
            $params['q']  = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . strtoupper((string) $filters['q']) . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /** @return array<int,string> */
    public static function distinctActions(): array
    {
        $rows = Database::all('SELECT DISTINCT action FROM activity_logs ORDER BY action ASC LIMIT 80');
        return array_map(static fn ($r) => (string) $r['action'], $rows);
    }

    /** Latest entries for dashboards. @return array<int,array<string,mixed>> */
    public static function recent(int $limit = 8, ?string $module = null): array
    {
        if ($module !== null) {
            return Database::all(
                'SELECT * FROM activity_logs WHERE module = :m ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit,
                ['m' => $module]
            );
        }
        return Database::all('SELECT * FROM activity_logs ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit);
    }
}
