<?php
/**
 * models/AttendanceRepo.php — QR attendance records and statistics.
 *
 * The present/late decision is always made on the server from the event start
 * time and its grace period; nothing the browser sends can influence it.
 */

declare(strict_types=1);

final class AttendanceRepo
{
    public const METHODS = ['qr_event', 'qr_student', 'manual', 'import', 'self_checkin'];

    private const SELECT = 'SELECT at.*, s.student_id, s.first_name, s.middle_name, s.last_name, s.course, s.year_level,
            e.title AS event_title, e.event_date, e.start_time, e.organization_id,
            o.name AS organization_name, o.acronym AS organization_acronym, u.full_name AS recorded_by_name
            FROM attendance at
            JOIN students s ON s.id = at.student_id
            JOIN events e ON e.id = at.event_id
            JOIN organizations o ON o.id = at.organization_id
            LEFT JOIN users u ON u.id = at.recorded_by';

    /**
     * Records one attendance row.
     *
     * $attendanceTime overrides the stamp — used when a check-in request is
     * approved later, so the student's requested moment is what gets stored.
     *
     * @return array{ok:bool,message:string,id?:int,existing?:array<string,mixed>}
     */
    public static function record(int $eventId, int $studentId, string $status, string $method = 'manual', string $remark = '', ?int $recordedBy = null, ?string $attendanceTime = null): array
    {
        $event = EventRepo::find($eventId);
        if ($event === null) {
            return ['ok' => false, 'message' => 'Event not found.'];
        }
        if (!in_array($status, AcademicRepo::ATTENDANCE_STATUSES, true)) {
            return ['ok' => false, 'message' => 'Unknown attendance status.'];
        }
        if (!in_array($method, self::METHODS, true)) {
            return ['ok' => false, 'message' => 'Unknown verification method.'];
        }

        $existing = Database::one('SELECT * FROM attendance WHERE event_id = :e AND student_id = :s', ['e' => $eventId, 's' => $studentId]);
        if ($existing !== null) {
            return ['ok' => false, 'message' => 'Attendance was already recorded for this student.', 'existing' => $existing];
        }

        $now   = Helpers::now();
        $stamp = $attendanceTime ?? $now;
        $id    = Database::insert('attendance', [
            'event_id'            => $eventId,
            'student_id'          => $studentId,
            'organization_id'     => (int) $event['organization_id'],
            'attendance_date'     => substr($stamp, 0, 10),
            'attendance_time'     => $stamp,
            'status'              => $status,
            'verification_method' => $method,
            'ip_address'          => Security::ip(),
            'device_info'         => Security::userAgent(),
            'recorded_by'         => $recordedBy ?? Auth::id(),
            'remark'              => mb_substr($remark, 0, 255),
            'created_at'          => $now,
        ]);

        Database::update(
            'event_registrations',
            ['status' => $status === 'absent' ? 'no_show' : 'attended'],
            'event_id = :e AND student_id = :s',
            ['e' => $eventId, 's' => $studentId]
        );

        Audit::log('ATTENDANCE_RECORDED', 'attendance', $status . ' via ' . $method . ' for event #' . $eventId, $id);
        return ['ok' => true, 'message' => 'Attendance recorded as ' . $status . '.', 'id' => $id];
    }

    /**
     * Student self check-in: validates the event window and decides the status.
     *
     * @return array{ok:bool,message:string,status?:string,event?:array<string,mixed>}
     */
    public static function selfCheckIn(int $eventId, int $studentId): array
    {
        $event = EventRepo::find($eventId);
        if ($event === null) {
            return ['ok' => false, 'message' => 'Event not found.'];
        }
        if (!in_array($event['status'], ['approved', 'ongoing', 'completed'], true)) {
            return ['ok' => false, 'message' => 'This event is not open for check-in.', 'event' => $event];
        }

        $window = EventRepo::eventWindow($event);
        $now    = time();

        if ($now < $window['start_ts'] - (EARLY_CHECKIN_MINUTES * 60)) {
            return [
                'ok'      => false,
                'message' => 'Check-in opens ' . Helpers::fmtTime((string) $event['start_time'])
                    . ' on ' . Helpers::fmtDate((string) $event['event_date']) . '.',
                'event'   => $event,
            ];
        }
        if ($now > $window['end_ts'] + (LATE_CHECKIN_HOURS * 3600)) {
            return ['ok' => false, 'message' => 'Check-in for this event has already closed.', 'event' => $event];
        }

        $status = $now <= $window['start_ts'] + ($window['grace_minutes'] * 60) ? 'present' : 'late';
        $result = self::record($eventId, $studentId, $status, 'self_checkin');

        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message'], 'event' => $event];
        }

        $message = $status === 'present'
            ? 'Check-in successful. You are marked as PRESENT.'
            : 'Check-in successful, but the grace period has passed — you are marked as LATE.';
        Notifications::push(
            Notifications::userIdForStudent($studentId),
            'Attendance recorded',
            $message . ' (' . $event['title'] . ')',
            $status === 'present' ? 'success' : 'warning',
            $eventId,
            'event',
            'student/my-attendance.php'
        );

        return ['ok' => true, 'message' => $message, 'status' => $status, 'event' => $event];
    }

    /**
     * Student sends a check-in request from the QR check-in page (camera
     * unavailable alternative). The staff approves it later; requested_at is
     * what will become the attendance time, so the student's moment never
     * gets lost while the approval waits.
     *
     * @return array{ok:bool,message:string}
     */
    public static function requestCheckIn(int $eventId, int $studentId): array
    {
        $event = EventRepo::find($eventId);
        if ($event === null) {
            return ['ok' => false, 'message' => 'Event not found.'];
        }
        if (!in_array($event['status'], ['approved', 'ongoing', 'completed'], true)) {
            return ['ok' => false, 'message' => 'This event is not open for check-in.'];
        }

        $window = EventRepo::eventWindow($event);
        $now    = time();
        if ($now < $window['start_ts'] - (EARLY_CHECKIN_MINUTES * 60)) {
            return [
                'ok'      => false,
                'message' => 'Check-in opens ' . Helpers::fmtTime((string) $event['start_time'])
                    . ' on ' . Helpers::fmtDate((string) $event['event_date']) . '.',
            ];
        }
        if ($now > $window['end_ts'] + (LATE_CHECKIN_HOURS * 3600)) {
            return ['ok' => false, 'message' => 'Check-in for this event has already closed.'];
        }
        if (Database::count('attendance', 'event_id = :e AND student_id = :s', ['e' => $eventId, 's' => $studentId]) > 0) {
            return ['ok' => false, 'message' => 'Your attendance for this event was already recorded.'];
        }

        $existing = Database::one(
            'SELECT * FROM checkin_requests WHERE event_id = :e AND student_id = :s ORDER BY id DESC',
            ['e' => $eventId, 's' => $studentId]
        );
        if ($existing !== null && $existing['status'] === 'pending') {
            return ['ok' => false, 'message' => 'Your check-in request is already waiting for confirmation.'];
        }

        $nowStr = Helpers::now();
        if ($existing !== null) {
            Database::update('checkin_requests', [
                'status'       => 'pending',
                'requested_at' => $nowStr,
                'decided_by'   => null,
                'decided_at'   => null,
                'remark'       => '',
            ], 'id = :id', ['id' => (int) $existing['id']]);
            $requestId = (int) $existing['id'];
        } else {
            $requestId = Database::insert('checkin_requests', [
                'event_id'     => $eventId,
                'student_id'   => $studentId,
                'status'       => 'pending',
                'requested_at' => $nowStr,
                'created_at'   => $nowStr,
            ]);
        }
        Audit::log('CHECKIN_REQUESTED', 'attendance', 'Requested check-in for event #' . $eventId, $requestId);

        $student = Database::one('SELECT first_name, last_name FROM students WHERE id = :id', ['id' => $studentId]);
        $name    = $student === null ? 'A student' : ((string) $student['first_name'] . ' ' . (string) $student['last_name']);
        $orgId   = (int) $event['organization_id'];
        $message = $name . ' requested a check-in for ' . $event['title'] . ' at ' . date('g:i A') . '.';
        Notifications::pushMany(
            Notifications::userIdsForOrganization($orgId, true),
            'Check-in request',
            $message,
            'warning',
            $eventId,
            'event',
            'scan.php'
        );
        Notifications::notifyOrganizationAdviser($orgId, 'Check-in request', $message, 'warning', $eventId, 'event', 'adviser/attendance.php');
        Notifications::notifyAdmins('Check-in request', $message, 'warning', $eventId, 'event', 'scan.php');

        return ['ok' => true, 'message' => 'Check-in request sent. An officer or your adviser will confirm it — your time is already recorded as ' . date('g:i A') . '.'];
    }

    /**
     * Pending check-in requests, optionally limited to the given organizations.
     * An empty organization list returns nothing (never everyone's requests).
     *
     * @param array<int,int> $orgIds
     * @return array<int,array<string,mixed>>
     */
    public static function pendingRequests(array $orgIds): array
    {
        if ($orgIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $orgIds));
        return Database::all(
            'SELECT cr.id, cr.requested_at, cr.student_id, e.title AS event_title, e.event_date, e.start_time,
                    s.student_id AS sid, s.first_name, s.last_name, s.course, s.year_level
               FROM checkin_requests cr
               JOIN events e ON e.id = cr.event_id
               JOIN students s ON s.id = cr.student_id
              WHERE cr.status = "pending" AND e.organization_id IN (' . $in . ')
              ORDER BY cr.requested_at ASC LIMIT 50'
        );
    }

    /**
     * Approves or rejects one pending check-in request.
     *
     * On approval the present/late decision is made from the REQUESTED time
     * against the event window, and that same moment becomes the attendance
     * time — so a student who asked during the grace period is never downgraded
     * just because the approval arrived later.
     *
     * @return array{ok:bool,message:string,status?:string}
     */
    public static function decideRequest(int $requestId, string $decision): array
    {
        $request = Database::one(
            'SELECT cr.*, e.title AS event_title, e.event_date, e.start_time, e.end_time, e.grace_minutes,
                    e.organization_id, e.status AS event_status
               FROM checkin_requests cr
               JOIN events e ON e.id = cr.event_id
              WHERE cr.id = :id',
            ['id' => $requestId]
        );
        if ($request === null) {
            return ['ok' => false, 'message' => 'That check-in request no longer exists.'];
        }
        if ($request['status'] !== 'pending') {
            return ['ok' => false, 'message' => 'That request was already ' . $request['status'] . '.'];
        }

        $studentId = (int) $request['student_id'];
        $title     = (string) $request['event_title'];

        if ($decision !== 'approve') {
            Database::update('checkin_requests', [
                'status'     => 'rejected',
                'decided_by' => Auth::id(),
                'decided_at' => Helpers::now(),
            ], 'id = :id', ['id' => $requestId]);
            Audit::log('CHECKIN_REJECTED', 'attendance', 'Rejected check-in request #' . $requestId, $requestId);
            Notifications::notifyStudent(
                $studentId,
                'Check-in request rejected',
                'Your check-in request for ' . $title . ' was not approved. Please see the event desk or your adviser.',
                'rejection',
                $requestId,
                'checkin_request',
                'student/my-attendance.php'
            );
            return ['ok' => true, 'message' => 'Request rejected — the student has been notified.'];
        }

        if ($request['event_status'] === 'cancelled') {
            return ['ok' => false, 'message' => 'That event was cancelled — the request cannot be approved.'];
        }

        $window  = EventRepo::eventWindow((array) $request);
        $reqTs   = (int) strtotime((string) $request['requested_at']);
        $status  = $reqTs <= $window['start_ts'] + ($window['grace_minutes'] * 60) ? 'present' : 'late';
        $record  = self::record((int) $request['event_id'], $studentId, $status, 'self_checkin', 'Check-in request approved.', Auth::id(), (string) $request['requested_at']);
        if (!$record['ok']) {
            return ['ok' => false, 'message' => $record['message']];
        }

        Database::update('checkin_requests', [
            'status'     => 'approved',
            'decided_by' => Auth::id(),
            'decided_at' => Helpers::now(),
        ], 'id = :id', ['id' => $requestId]);
        Audit::log('CHECKIN_APPROVED', 'attendance', 'Approved check-in request #' . $requestId . ' as ' . $status, $requestId);

        Notifications::notifyStudent(
            $studentId,
            'Check-in approved',
            'You are marked ' . strtoupper($status) . ' for ' . $title . ' (your request time ' . Helpers::fmtTime((string) $request['requested_at']) . ' was kept).',
            $status === 'present' ? 'success' : 'warning',
            (int) $request['event_id'],
            'event',
            'student/my-attendance.php'
        );

        return ['ok' => true, 'message' => 'Approved — the student is marked ' . strtoupper($status) . '.', 'status' => $status];
    }

    /** Corrects a recorded status. */
    public static function setStatus(int $attendanceId, string $status, string $remark = ''): void
    {
        if (!in_array($status, AcademicRepo::ATTENDANCE_STATUSES, true)) {
            throw new InvalidArgumentException('Unknown attendance status.');
        }
        Database::update('attendance', [
            'status'     => $status,
            'remark'     => mb_substr($remark, 0, 255),
            'created_at' => Helpers::now(),
        ], 'id = :id', ['id' => $attendanceId]);
        Audit::log('ATTENDANCE_UPDATED', 'attendance', 'Attendance status changed to ' . $status, $attendanceId);
    }

    /** Inserts an 'absent' row when a registered student never checked in. */
    public static function markAbsent(int $eventId, int $studentId, string $remark = 'No check-in recorded.'): void
    {
        if (Database::count('attendance', 'event_id = :e AND student_id = :s', ['e' => $eventId, 's' => $studentId]) > 0) {
            return;
        }
        $event = EventRepo::find($eventId);
        if ($event === null) {
            return;
        }
        $now = Helpers::now();
        Database::insert('attendance', [
            'event_id'            => $eventId,
            'student_id'          => $studentId,
            'organization_id'     => (int) $event['organization_id'],
            'attendance_date'     => (string) $event['event_date'],
            'attendance_time'     => $now,
            'status'              => 'absent',
            'verification_method' => 'import',
            'remark'              => mb_substr($remark, 0, 255),
            'created_at'          => $now,
        ]);
    }

    /**
     * Statistics for one event.
     *
     * @return array{registered:int,present:int,late:int,excused:int,absent:int,total:int,percentage:float}
     */
    public static function stats(int $eventId): array
    {
        $counts     = array_fill_keys(AcademicRepo::ATTENDANCE_STATUSES, 0);
        $registered = Database::count('event_registrations', 'event_id = :e AND status <> "cancelled"', ['e' => $eventId]);
        foreach (Database::all('SELECT status, COUNT(*) AS c FROM attendance WHERE event_id = :e GROUP BY status', ['e' => $eventId]) as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return self::summarise($counts, $registered);
    }

    /**
     * Aggregated statistics for a whole organization.
     *
     * @return array{registered:int,present:int,late:int,excused:int,absent:int,total:int,percentage:float}
     */
    public static function statsForOrganization(int $organizationId): array
    {
        $counts = array_fill_keys(AcademicRepo::ATTENDANCE_STATUSES, 0);
        foreach (Database::all('SELECT status, COUNT(*) AS c FROM attendance WHERE organization_id = :o GROUP BY status', ['o' => $organizationId]) as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        $registered = (int) Database::scalar(
            'SELECT COUNT(*) FROM event_registrations r JOIN events e ON e.id = r.event_id
              WHERE e.organization_id = :o AND r.status <> "cancelled"',
            ['o' => $organizationId]
        );
        return self::summarise($counts, $registered);
    }

    /**
     * @param array<string,int> $counts
     * @return array{registered:int,present:int,late:int,excused:int,absent:int,total:int,percentage:float}
     */
    private static function summarise(array $counts, int $registered): array
    {
        $present = $counts['present'] + $counts['late'];
        $total   = max($registered, array_sum($counts));
        return [
            'registered' => $registered,
            'present'    => $counts['present'],
            'late'       => $counts['late'],
            'excused'    => $counts['excused'],
            'absent'     => $counts['absent'],
            'total'      => $total,
            'percentage' => Helpers::percent($present, $total),
        ];
    }

    /**
     * @param array<string,mixed> $filters event_id, organization_id, student_id, status, method, from, to, q
     * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int}
     */
    public static function list(array $filters, int $page = 1, int $perPage = 25): array
    {
        [$whereSql, $params] = self::where($filters);
        $where = ' WHERE ' . $whereSql;
        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM attendance at JOIN students s ON s.id = at.student_id ' . $where,
            $params
        );
        $page  = max(1, $page);
        return [
            'rows'  => Database::all(
                self::SELECT . ' ' . $where . ' ORDER BY at.attendance_time DESC'
                . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
                $params
            ),
            'total' => $total,
            'pages' => (int) max(1, ceil($total / max(1, $perPage))),
        ];
    }

    /**
     * A student's own attendance history.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forStudent(int $studentId, int $limit = 100): array
    {
        return Database::all(
            self::SELECT . ' WHERE at.student_id = :s ORDER BY at.attendance_time DESC LIMIT ' . (int) max(1, min(500, $limit)),
            ['s' => $studentId]
        );
    }

    /**
     * A student's totals across all events.
     *
     * @return array{present:int,late:int,excused:int,absent:int,events:int,percentage:float}
     */
    public static function studentTotals(int $studentId): array
    {
        $counts = array_fill_keys(AcademicRepo::ATTENDANCE_STATUSES, 0);
        foreach (Database::all('SELECT status, COUNT(*) AS c FROM attendance WHERE student_id = :s GROUP BY status', ['s' => $studentId]) as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        $total   = array_sum($counts);
        $present = $counts['present'] + $counts['late'];
        return [
            'present'    => $counts['present'],
            'late'       => $counts['late'],
            'excused'    => $counts['excused'],
            'absent'     => $counts['absent'],
            'events'     => $total,
            'percentage' => Helpers::percent($present, $total),
        ];
    }

    /** @return array<string,int> verification_method => count */
    public static function methodBreakdown(): array
    {
        $out = array_fill_keys(self::METHODS, 0);
        foreach (Database::all('SELECT verification_method, COUNT(*) AS c FROM attendance GROUP BY verification_method') as $row) {
            $out[(string) $row['verification_method']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Monthly attendance trend for the charts.
     *
     * @return array{labels:array<int,string>,values:array<int,int>}
     */
    public static function trend(int $organizationId, int $months = 6): array
    {
        $rows = Database::all(
            'SELECT DATE_FORMAT(attendance_date, "%Y-%m") AS ym, COUNT(*) AS c
               FROM attendance WHERE organization_id = :o AND status IN ("present","late")
              GROUP BY ym ORDER BY ym DESC LIMIT ' . (int) max(1, min(24, $months)),
            ['o' => $organizationId]
        );
        $labels = [];
        $values = [];
        foreach (array_reverse($rows) as $row) {
            $labels[] = date('M Y', strtotime((string) $row['ym'] . '-01'));
            $values[] = (int) $row['c'];
        }
        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Flat rows for the CSV export.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function exportRows(array $filters = []): array
    {
        [$whereSql, $params] = self::where($filters);
        $where = ' WHERE ' . $whereSql;
        return Database::all(self::SELECT . ' ' . $where . ' ORDER BY at.attendance_time DESC LIMIT 10000', $params);
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
        foreach (['event_id' => 'at.event_id', 'organization_id' => 'at.organization_id',
                  'student_id' => 'at.student_id', 'status' => 'at.status',
                  'verification_method' => 'at.verification_method'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]             = $column . ' = :f_' . $key;
                $params['f_' . $key] = $filters[$key];
            }
        }
        if (!empty($filters['from'])) {
            $where[]        = 'at.attendance_date >= :from';
            $params['from'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]      = 'at.attendance_date <= :to';
            $params['to'] = (string) $filters['to'];
        }

        return [implode(' AND ', $where), $params];
    }
}
