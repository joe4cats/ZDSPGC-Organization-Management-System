<?php
/**
 * api/attendance/scan.php — QR check-in endpoint.
 *
 * Accepts the signed token produced by the event poster QR (event token) or by
 * a student ID card (student token) and re-validates everything on the server:
 * signature, nonce, event window, duplicate attendance. The browser never
 * decides whether a check-in is valid, and never sends an attendance status.
 */

declare(strict_types=1);

define('ZDSPGC_API_MODE', true);
require_once __DIR__ . '/../../includes/bootstrap.php';

if (!Helpers::isPost()) {
    Helpers::jsonFail('This endpoint accepts POST requests only.', 405, 'method');
}

Auth::requireLogin();
Security::requireCsrf();

$rate = Security::rateLimit('checkin', CHECKIN_RATE_PER_MIN, 60);
if (!$rate['allowed']) {
    Helpers::jsonFail('Too many check-in attempts. Please wait a moment and try again.', 429, 'rate_limited');
}

$raw = (string) (Helpers::input('token') ?? '');
if (trim($raw) === '') {
    Helpers::jsonFail('No QR code was provided.', 422, 'empty_token');
}

/* ---- 1. event token: the student scanned the poster at the venue ---- */
$event = Qr::eventFromToken(Qr::tokenFromScan($raw));
if ($event !== null) {
    if (Auth::role() !== 'student') {
        Helpers::jsonFail('Sign in with a student account to check in.', 403, 'not_student');
    }
    $result = AttendanceRepo::selfCheckIn((int) $event['id'], Permissions::studentId());
    if (!$result['ok']) {
        Helpers::jsonFail($result['message'], 422, 'checkin_refused', [
            'event' => [
                'id' => (int) $event['id'],
                'title' => (string) $event['title'],
                'date' => (string) $event['event_date'],
                'start_time' => (string) $event['start_time'],
                'venue' => (string) $event['venue'],
            ],
        ]);
    }
    Helpers::jsonOk($result['message'], [
        'status'  => (string) $result['status'],
        'event'   => [
            'id'    => (int) $event['id'],
            'title' => (string) $event['title'],
            'date'  => Helpers::fmtDate((string) $event['event_date'], 'l, F j, Y'),
            'time'  => Helpers::fmtTime((string) $event['start_time']),
            'venue' => (string) $event['venue'],
        ],
    ]);
}

/* ---- 2. student token: staff scanned the student's own ID card ---- */
$student = Qr::studentFromToken(Qr::tokenFromScan($raw));
if ($student !== null) {
    if (!Permissions::can('record_attendance')) {
        Helpers::jsonFail('You are not allowed to record attendance.', 403, 'forbidden');
    }
    $eventId = Helpers::inputInt('event_id');
    if ($eventId <= 0) {
        Helpers::jsonFail('Select the event before scanning a student ID.', 422, 'event_required');
    }
    $eventRow = EventRepo::find($eventId);
    if ($eventRow === null || !Permissions::canManageOrganization((int) $eventRow['organization_id'])) {
        Helpers::jsonFail('You cannot record attendance for that event.', 403, 'forbidden');
    }

    $window = EventRepo::eventWindow($eventRow);
    $now    = time();
    if ($now < $window['start_ts'] - (EARLY_CHECKIN_MINUTES * 60) || $now > $window['end_ts'] + (LATE_CHECKIN_HOURS * 3600)) {
        Helpers::jsonFail('The check-in window for that event is not open.', 422, 'window_closed');
    }

    $status = $now <= $window['start_ts'] + ($window['grace_minutes'] * 60) ? 'present' : 'late';
    $record = AttendanceRepo::record($eventId, (int) $student['id'], $status, 'qr_student');
    if (!$record['ok']) {
        Helpers::jsonFail($record['message'], 422, 'duplicate_or_invalid');
    }
    Helpers::jsonOk($record['message'] . ' ' . (string) $student['first_name'] . ' ' . (string) $student['last_name'], [
        'status'  => $status,
        'student' => [
            'id'         => (int) $student['id'],
            'student_id' => (string) $student['student_id'],
            'name'       => (string) $student['first_name'] . ' ' . (string) $student['last_name'],
            'course'     => (string) $student['course'],
        ],
    ]);
}

/* ---- 3. unknown, tampered or re-issued code ---- */
Helpers::jsonFail(
    'This QR code is not recognised. It may have been re-issued — ask an officer for the current code.',
    422,
    'unrecognised_token'
);
