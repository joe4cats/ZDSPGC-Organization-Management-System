<?php
/**
 * models/EventRepo.php — events, the approval workflow and registrations.
 */

declare(strict_types=1);

final class EventRepo
{
    private const SELECT = 'SELECT ev.*, o.name AS organization_name, o.acronym AS organization_acronym,
            au.full_name AS adviser_name, ay.name AS academic_year_name,
            (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = ev.id AND r.status = "registered") AS registered_count,
            (SELECT COUNT(*) FROM attendance a WHERE a.event_id = ev.id) AS attendance_count
            FROM events ev
            LEFT JOIN organizations o ON o.id = ev.organization_id
            LEFT JOIN advisers ad ON ad.id = ev.adviser_id
            LEFT JOIN users au ON au.id = ad.user_id
            LEFT JOIN academic_years ay ON ay.id = ev.academic_year_id';

    /**
     * @param array<string,mixed> $filters q, status, organization_id, event_type, from, to, upcoming
     * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int}
     */
    public static function list(array $filters, int $page = 1, int $perPage = 20): array
    {
        [$whereSql, $params] = self::where($filters);
        $where = ' WHERE ' . $whereSql;
        $total = (int) Database::scalar('SELECT COUNT(*) FROM events ev ' . $where, $params);
        $page  = max(1, $page);
        return [
            'rows'  => Database::all(
                self::SELECT . ' ' . $where . ' ORDER BY ev.event_date DESC, ev.start_time DESC'
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
        return Database::one(self::SELECT . ' WHERE ev.id = :id', ['id' => $id]);
    }

    /** Approved events that have not happened yet. @return array<int,array<string,mixed>> */
    public static function upcoming(int $limit = 8, ?int $organizationId = null): array
    {
        $params = [];
        $sql    = self::SELECT . " WHERE ev.status = 'approved' AND ev.event_date >= CURDATE()";
        if ($organizationId !== null) {
            $sql           .= ' AND ev.organization_id = :org';
            $params['org'] = $organizationId;
        }
        return Database::all(
            $sql . ' ORDER BY ev.event_date ASC, ev.start_time ASC LIMIT ' . (int) max(1, min(100, $limit)),
            $params
        );
    }

    /** Events inside a date range (calendar). @return array<int,array<string,mixed>> */
    public static function forCalendar(string $fromYmd, string $toYmd, ?int $organizationId = null): array
    {
        $params = ['from' => $fromYmd, 'to' => $toYmd];
        $sql    = self::SELECT . " WHERE ev.status NOT IN ('draft','cancelled') AND ev.event_date BETWEEN :from AND :to";
        if ($organizationId !== null) {
            $sql           .= ' AND ev.organization_id = :org';
            $params['org'] = $organizationId;
        }
        return Database::all($sql . ' ORDER BY ev.event_date ASC, ev.start_time ASC', $params);
    }

    /**
     * @param array<string,mixed> $data
     * @return int the new id
     */
    public static function create(array $data): int
    {
        $organizationId = (int) ($data['organization_id'] ?? 0);
        $id = Database::insert('events', [
            'event_code'            => self::nextCode($organizationId),
            'organization_id'       => $organizationId,
            'academic_year_id'      => empty($data['academic_year_id']) ? AcademicRepo::activeYearId() : (int) $data['academic_year_id'],
            'title'                 => (string) $data['title'],
            'description'           => (string) ($data['description'] ?? ''),
            'event_type'            => (string) ($data['event_type'] ?? 'Seminar'),
            'venue'                 => (string) ($data['venue'] ?? ''),
            'event_date'            => (string) $data['event_date'],
            'start_time'            => (string) $data['start_time'],
            'end_time'              => (string) $data['end_time'],
            'registration_deadline' => empty($data['registration_deadline']) ? null : (string) $data['registration_deadline'],
            'max_participants'      => max(0, (int) ($data['max_participants'] ?? 0)),
            'organizer'             => (string) ($data['organizer'] ?? ''),
            'adviser_id'            => empty($data['adviser_id']) ? null : (int) $data['adviser_id'],
            'status'                => 'draft',
            'requires_registration' => empty($data['requires_registration']) ? 1 : (int) $data['requires_registration'],
            'grace_minutes'         => max(0, (int) ($data['grace_minutes'] ?? DEFAULT_GRACE_MINUTES)),
            'created_by'            => Auth::id(),
            'created_at'            => Helpers::now(),
        ]);
        Audit::log('EVENT_CREATED', 'events', 'Created event: ' . (string) $data['title'], $id);
        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $row = [];
        foreach (['title', 'description', 'event_type', 'venue', 'event_date', 'start_time', 'end_time', 'organizer', 'banner'] as $column) {
            if (array_key_exists($column, $data)) {
                $row[$column] = (string) $data[$column];
            }
        }
        foreach (['registration_deadline', 'max_participants', 'grace_minutes', 'requires_registration', 'adviser_id'] as $column) {
            if (array_key_exists($column, $data)) {
                $row[$column] = ($data[$column] === '' || $data[$column] === null) ? null : (int) $data[$column];
            }
        }
        if ($row === []) {
            return;
        }
        $row['updated_at'] = Helpers::now();
        Database::update('events', $row, 'id = :id', ['id' => $id]);
        Audit::log('EVENT_UPDATED', 'events', 'Updated event #' . $id, $id);
    }

    /** Next sequential event code, e.g. EVT-SITE-2026-002. */
    public static function nextCode(int $organizationId): string
    {
        $org  = Database::one('SELECT acronym, name FROM organizations WHERE id = :id', ['id' => $organizationId]);
        $acro = strtoupper(substr((string) ($org['acronym'] ?? ''), 0, 10));
        if ($acro === '') {
            $acro = strtoupper(substr((string) preg_replace('/[^A-Za-z]/', '', (string) ($org['name'] ?? 'ORG')), 0, 10));
        }
        $year  = date('Y');
        $count = Database::count('events', 'organization_id = :o AND event_code LIKE :p', ['o' => $organizationId, 'p' => 'EVT-' . $acro . '-' . $year . '-%']);
        return 'EVT-' . $acro . '-' . $year . '-' . str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT);
    }

    /** Draft becomes pending_adviser (the adviser is notified). */
    public static function submit(int $id): void
    {
        $event = self::find($id);
        if ($event === null) {
            throw new RuntimeException('Event not found.');
        }
        Database::update('events', [
            'status'       => 'pending_adviser',
            'submitted_at' => Helpers::now(),
            'updated_at'   => Helpers::now(),
        ], 'id = :id', ['id' => $id]);
        Audit::log('EVENT_SUBMITTED', 'events', 'Submitted event for approval: ' . $event['title'], $id);
        Notifications::notifyOrganizationAdviser(
            (int) $event['organization_id'],
            'Event proposal awaiting endorsement',
            $event['title'] . ' was submitted for adviser endorsement.',
            'info',
            $id,
            'event',
            'adviser/events.php'
        );
    }

    /** Adviser decision: approve moves it to pending_admin, reject closes it. */
    public static function advise(int $id, string $decision, string $comments = ''): void
    {
        $event = self::find($id);
        if ($event === null) {
            throw new RuntimeException('Event not found.');
        }
        $approved = $decision === 'approve';
        Database::update('events', [
            'status'              => $approved ? 'pending_admin' : 'rejected',
            'adviser_approved_by' => Auth::id(),
            'adviser_approved_at' => Helpers::now(),
            'rejection_reason'    => $approved ? '' : mb_substr($comments, 0, 400),
            'updated_at'          => Helpers::now(),
        ], 'id = :id', ['id' => $id]);

        Audit::log('EVENT_' . strtoupper($decision), 'events', trim('Adviser decision on ' . $event['title'] . ' — ' . $comments), $id);
        if ($approved) {
            Notifications::notifyAdmins(
                'Event proposal awaiting decision',
                $event['title'] . ' was endorsed by the adviser and needs the administrator decision.',
                'warning',
                $id,
                'event',
                'admin/events.php'
            );
        } else {
            Notifications::push(
                (int) $event['created_by'],
                'Event rejected',
                $event['title'] . ' was not endorsed. ' . $comments,
                'rejection',
                $id,
                'event',
                'organization/events.php'
            );
        }
    }

    /** Administrator decision: approve publishes the event, reject closes it. */
    public static function decide(int $id, string $decision, string $reason = ''): void
    {
        $event = self::find($id);
        if ($event === null) {
            throw new RuntimeException('Event not found.');
        }
        $approved = $decision === 'approve';
        Database::update('events', [
            'status'           => $approved ? 'approved' : 'rejected',
            'approved_by'      => $approved ? Auth::id() : null,
            'approved_at'      => $approved ? Helpers::now() : null,
            'rejection_reason' => $approved ? '' : mb_substr($reason, 0, 400),
            'updated_at'       => Helpers::now(),
        ], 'id = :id', ['id' => $id]);

        Audit::log('EVENT_' . strtoupper($decision), 'events', trim('Administrator decision on ' . $event['title'] . ' — ' . $reason), $id);
        $officers = Notifications::userIdsForOrganization((int) $event['organization_id'], true);
        if ($approved) {
            Notifications::pushMany(
                $officers,
                'Event approved',
                $event['title'] . ' was approved and published.',
                'approval',
                $id,
                'event',
                'organization/events.php'
            );
            Notifications::pushForSection(
                array_values(array_diff(Notifications::userIdsForOrganization((int) $event['organization_id']), $officers)),
                'events',
                'New event published',
                $event['title'] . ' was approved and is now open for participation.',
                'success',
                $id,
                'event'
            );
        } else {
            Notifications::pushMany(
                $officers,
                'Event rejected',
                $event['title'] . ' was rejected. ' . $reason,
                'rejection',
                $id,
                'event',
                'organization/events.php'
            );
        }
    }

    /** Marks the event as completed. */
    public static function complete(int $id): void
    {
        Database::update('events', [
            'status'       => 'completed',
            'completed_at' => Helpers::now(),
            'updated_at'   => Helpers::now(),
        ], 'id = :id', ['id' => $id]);
        Audit::log('EVENT_COMPLETED', 'events', 'Event #' . $id . ' marked as completed', $id);
    }

    /** Generic status change (ongoing, cancelled, back to draft). */
    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, AcademicRepo::EVENT_STATUSES, true)) {
            throw new InvalidArgumentException('Unknown event status.');
        }
        $event = self::find($id);
        Database::update('events', ['status' => $status, 'updated_at' => Helpers::now()], 'id = :id', ['id' => $id]);
        Audit::log('EVENT_' . strtoupper($status), 'events', 'Event status set to ' . $status, $id);

        if ($status === 'cancelled' && $event !== null) {
            Notifications::pushForSection(
                Notifications::userIdsForEventRegistrants($id),
                'registrations',
                'Event cancelled',
                $event['title'] . ' was cancelled. Any attendance or registration for it will not be counted.',
                'warning',
                $id,
                'event'
            );
        }
    }

    /** @return array<string,int> status => count */
    public static function statusCounts(?int $organizationId = null): array
    {
        $out    = array_fill_keys(AcademicRepo::EVENT_STATUSES, 0);
        $sql    = 'SELECT status, COUNT(*) AS c FROM events';
        $params = [];
        if ($organizationId !== null) {
            $sql         .= ' WHERE organization_id = :o';
            $params['o'] = $organizationId;
        }
        foreach (Database::all($sql . ' GROUP BY status', $params) as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Registers a student for an approved event.
     *
     * @return array{ok:bool,message:string}
     */
    public static function register(int $eventId, int $studentId): array
    {
        $event = self::find($eventId);
        if ($event === null) {
            return ['ok' => false, 'message' => 'Event not found.'];
        }
        if ($event['status'] !== 'approved') {
            return ['ok' => false, 'message' => 'This event is not open for registration yet.'];
        }
        if (empty($event['requires_registration'])) {
            return ['ok' => false, 'message' => 'This event does not require registration — just attend and scan the QR code.'];
        }
        if (!empty($event['registration_deadline']) && strtotime((string) $event['registration_deadline']) < time()) {
            return ['ok' => false, 'message' => 'The registration deadline for this event has passed.'];
        }

        $existing = Database::one(
            'SELECT * FROM event_registrations WHERE event_id = :e AND student_id = :s',
            ['e' => $eventId, 's' => $studentId]
        );
        if ($existing !== null) {
            return $existing['status'] === 'registered'
                ? ['ok' => false, 'message' => 'You are already registered for this event.']
                : ['ok' => false, 'message' => 'Your previous registration was cancelled.'];
        }

        $max = (int) $event['max_participants'];
        if ($max > 0 && (int) $event['registered_count'] >= $max) {
            return ['ok' => false, 'message' => 'This event has reached its maximum number of participants.'];
        }

        Database::insert('event_registrations', [
            'event_id'      => $eventId,
            'student_id'    => $studentId,
            'status'        => 'registered',
            'registered_at' => Helpers::now(),
        ]);
        Audit::log('EVENT_REGISTERED', 'events', 'Registered for ' . $event['title'], $eventId);
        Notifications::notifyStudent(
            $studentId,
            'Registration confirmed',
            'You are registered for ' . $event['title'] . ' (' . Helpers::fmtDate((string) $event['event_date']) . ').',
            'success',
            $eventId,
            'event',
            'student/my-registrations.php'
        );
        return ['ok' => true, 'message' => 'You are registered for ' . $event['title'] . '.'];
    }

    /** Cancels a registration. */
    public static function cancelRegistration(int $eventId, int $studentId): void
    {
        Database::update('event_registrations', [
            'status'       => 'cancelled',
            'cancelled_at' => Helpers::now(),
        ], 'event_id = :e AND student_id = :s', ['e' => $eventId, 's' => $studentId]);
        Audit::log('EVENT_REGISTRATION_CANCELLED', 'events', 'Cancelled a registration for event #' . $eventId, $eventId);
    }

    public static function isRegistered(int $eventId, int $studentId): bool
    {
        return Database::count(
            'event_registrations',
            'event_id = :e AND student_id = :s AND status = "registered"',
            ['e' => $eventId, 's' => $studentId]
        ) > 0;
    }

    /**
     * Event start/end timestamps used by the attendance window checks.
     *
     * @param array<string,mixed> $event
     * @return array{start_ts:int,end_ts:int,grace_minutes:int}
     */
    public static function eventWindow(array $event): array
    {
        $date  = (string) $event['event_date'];
        $start = strtotime($date . ' ' . (string) $event['start_time']);
        $end   = strtotime($date . ' ' . (string) $event['end_time']);
        if ($end === false || $end < $start) {
            $end = $start === false ? time() : $start + 3600;
        }
        return [
            'start_ts'      => (int) $start,
            'end_ts'        => (int) $end,
            'grace_minutes' => (int) ($event['grace_minutes'] ?? DEFAULT_GRACE_MINUTES),
        ];
    }

    /**
     * Every event a student registered for (newest first) with its registration state.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function registrationsForStudent(int $studentId, int $limit = 100): array
    {
        return Database::all(
            'SELECT r.*, e.title, e.event_code, e.venue, e.event_date, e.start_time, e.end_time,
                    e.status AS event_status, e.requires_registration, e.max_participants,
                    o.name AS organization_name, o.acronym
               FROM event_registrations r
               JOIN events e ON e.id = r.event_id
               JOIN organizations o ON o.id = e.organization_id
              WHERE r.student_id = :s
              ORDER BY e.event_date DESC, e.start_time DESC
              LIMIT ' . (int) $limit,
            ['s' => $studentId]
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
            $where[] = '(ev.title LIKE :q OR ev.venue LIKE :q2 OR ev.event_code LIKE :q3)';
            $params['q']  = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
        }
        foreach (['status' => 'ev.status', 'organization_id' => 'ev.organization_id',
                  'event_type' => 'ev.event_type', 'academic_year_id' => 'ev.academic_year_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]             = $column . ' = :f_' . $key;
                $params['f_' . $key] = $filters[$key];
            }
        }
        if (!empty($filters['from'])) {
            $where[]        = 'ev.event_date >= :from';
            $params['from'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]      = 'ev.event_date <= :to';
            $params['to'] = (string) $filters['to'];
        }
        if (!empty($filters['upcoming'])) {
            $where[] = "ev.status = 'approved' AND ev.event_date >= CURDATE()";
        }

        return [implode(' AND ', $where), $params];
    }
}
