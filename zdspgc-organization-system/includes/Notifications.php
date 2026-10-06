<?php
/**
 * Notifications.php — internal notification system.
 *
 * Notifications are rows in `notifications` addressed to a single user id.
 * The helpers below fan a message out to the right people: organization
 * officers, its adviser, the admins, or one student (resolved through
 * students.user_id).
 */

declare(strict_types=1);

final class Notifications
{
    public const TYPES = ['info', 'success', 'warning', 'error', 'approval', 'rejection'];

    public static function push(
        int $userId,
        string $title,
        string $message,
        string $type = 'info',
        ?int $referenceId = null,
        string $referenceType = '',
        string $url = ''
    ): void {
        if ($userId <= 0) {
            return;
        }
        try {
            Database::insert('notifications', [
                'user_id'        => $userId,
                'title'          => mb_substr($title, 0, 160),
                'message'        => mb_substr($message, 0, 480),
                'type'           => in_array($type, self::TYPES, true) ? $type : 'info',
                'reference_id'   => $referenceId,
                'reference_type' => mb_substr($referenceType, 0, 40),
                'url'            => mb_substr($url, 0, 255),
                'is_read'        => 0,
                'created_at'     => Helpers::now(),
            ]);
        } catch (Throwable $e) {
            // Notifications are best-effort and must never break an action.
        }
    }

    /** @param array<int,int> $userIds */
    public static function pushMany(array $userIds, string $title, string $message, string $type = 'info', ?int $referenceId = null, string $referenceType = '', string $url = ''): void
    {
        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            self::push($userId, $title, $message, $type, $referenceId, $referenceType, $url);
        }
    }

    /** @return array<int,int> */
    public static function userIdsForRole(string $role): array
    {
        $rows = Database::all("SELECT id FROM users WHERE role = :r AND status = 'active'", ['r' => $role]);
        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    public static function notifyRole(string $role, string $title, string $message, string $type = 'info', ?int $referenceId = null, string $referenceType = '', string $url = ''): void
    {
        self::pushMany(self::userIdsForRole($role), $title, $message, $type, $referenceId, $referenceType, $url);
    }

    public static function notifyAdmins(string $title, string $message, string $type = 'info', ?int $referenceId = null, string $referenceType = '', string $url = ''): void
    {
        self::notifyRole('admin', $title, $message, $type, $referenceId, $referenceType, $url);
    }

    /**
     * Active members of one organization.
     * @return array<int,int> user ids
     */
    public static function userIdsForOrganization(int $organizationId, bool $officersOnly = false): array
    {
        $sql = "SELECT DISTINCT u.id
                  FROM organization_members m
                  JOIN students s ON s.id = m.student_id
                  JOIN users u ON u.id = s.user_id
                 WHERE m.organization_id = :org AND m.status = 'active'";
        if ($officersOnly) {
            $sql .= ' AND m.position_id IS NOT NULL';
        }
        $rows = Database::all($sql, ['org' => $organizationId]);
        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    public static function userIdForStudent(int $studentId): int
    {
        return (int) Database::value('SELECT user_id FROM students WHERE id = :id', ['id' => $studentId], 0);
    }

    public static function notifyStudent(int $studentId, string $title, string $message, string $type = 'info', ?int $referenceId = null, string $referenceType = '', string $url = ''): void
    {
        self::push(self::userIdForStudent($studentId), $title, $message, $type, $referenceId, $referenceType, $url);
    }

    /** The adviser user account of one organization. */
    public static function notifyOrganizationAdviser(int $organizationId, string $title, string $message, string $type = 'info', ?int $referenceId = null, string $referenceType = '', string $url = ''): void
    {
        $rows = Database::all(
            "SELECT u.id FROM organizations o
               JOIN advisers a ON a.id = o.adviser_id
               JOIN users u ON u.id = a.user_id
              WHERE o.id = :org AND u.status = 'active'",
            ['org' => $organizationId]
        );
        self::pushMany(array_map(static fn ($r) => (int) $r['id'], $rows), $title, $message, $type, $referenceId, $referenceType, $url);
    }

    /** User ids of every student registered for one event (status = registered). */
    public static function userIdsForEventRegistrants(int $eventId): array
    {
        $rows = Database::all(
            'SELECT DISTINCT u.id
               FROM event_registrations r
               JOIN students s ON s.id = r.student_id
               JOIN users u ON u.id = s.user_id
              WHERE r.event_id = :e AND r.status = "registered"',
            ['e' => $eventId]
        );
        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    /**
     * Pushes one notification to many users, but resolves the target URL per
     * recipient's own role so the "Open" button always lands on a page that
     * exists for that role (students never get sent to an officer-only page).
     *
     * @param array<int,int> $userIds
     * @param string $section announcements|events|registrations|attendance
     */
    public static function pushForSection(array $userIds, string $section, string $title, string $message, string $type = 'info', ?int $referenceId = null, string $referenceType = ''): void
    {
        foreach (self::groupIdsByRole($userIds) as $role => $ids) {
            self::pushMany($ids, $title, $message, $type, $referenceId, $referenceType, self::sectionUrl($role, $section));
        }
    }

    /**
     * @param array<int,int> $userIds
     * @return array<string,array<int,int>> role => user ids
     */
    private static function groupIdsByRole(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $names  = [];
            $params = [];
            foreach ($chunk as $i => $uid) {
                $names[]        = ':u' . $i;
                $params['u' . $i] = $uid;
            }
            $rows = Database::all('SELECT id, role FROM users WHERE id IN (' . implode(',', $names) . ')', $params);
            foreach ($rows as $row) {
                $out[(string) $row['role']][] = (int) $row['id'];
            }
        }
        return $out;
    }

    /** The page a recipient's role should open for a given notification section. */
    private static function sectionUrl(string $role, string $section): string
    {
        $map = [
            'announcements' => [
                'student' => 'student/announcements.php',
                'officer' => 'organization/announcements.php',
                'adviser' => 'adviser/announcements.php',
                'admin'   => 'admin/announcements.php',
            ],
            'events' => [
                'student' => 'student/events.php',
                'officer' => 'organization/events.php',
                'adviser' => 'adviser/events.php',
                'admin'   => 'admin/events.php',
            ],
            'registrations' => [
                'student' => 'student/my-registrations.php',
                'officer' => 'organization/events.php',
                'adviser' => 'adviser/events.php',
                'admin'   => 'admin/events.php',
            ],
            'attendance' => [
                'student' => 'student/my-attendance.php',
                'officer' => 'organization/attendance.php',
                'adviser' => 'adviser/attendance.php',
                'admin'   => 'admin/attendance.php',
            ],
        ];
        return $map[$section][$role] ?? 'notifications.php';
    }

    /* ---------------------------------------------------------------------
     * Reading / marking
     * ------------------------------------------------------------------ */

    public static function unreadCount(?int $userId = null): int
    {
        $userId ??= Auth::id();
        if (!$userId) {
            return 0;
        }
        return (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0', ['u' => $userId]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function forUser(?int $userId = null, int $limit = 25, bool $unreadOnly = false): array
    {
        $userId ??= Auth::id();
        if (!$userId) {
            return [];
        }
        $sql = 'SELECT * FROM notifications WHERE user_id = :u';
        if ($unreadOnly) {
            $sql .= ' AND is_read = 0';
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit;
        return Database::all($sql, ['u' => $userId]);
    }

    public static function markRead(int $id, ?int $userId = null): bool
    {
        $userId ??= Auth::id();
        if (!$userId) {
            return false;
        }
        return Database::run(
            'UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :u',
            ['id' => $id, 'u' => $userId]
        )->rowCount() > 0;
    }

    public static function markAllRead(?int $userId = null): int
    {
        $userId ??= Auth::id();
        if (!$userId) {
            return 0;
        }
        return Database::run('UPDATE notifications SET is_read = 1 WHERE user_id = :u AND is_read = 0', ['u' => $userId])->rowCount();
    }
}
