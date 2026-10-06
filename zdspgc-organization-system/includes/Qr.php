<?php
/**
 * Qr.php — signed, revocable QR tokens for attendance.
 *
 * The token is derived, never stored as a secret:
 *     payload = 1|E|<event_code>|<nonce>     event poster / venue QR
 *     payload = 1|S|<student_id>|<nonce>     student attendance ID card
 *     token   = base64url(payload) . "." . base64url(hmac_sha256(payload)[0..15])
 *
 * The nonce lives in the database (events.qr_nonce / students.qr_nonce), so
 * re-issuing a QR code immediately voids every old printout. The format is the
 * same one used by the standalone "ZDSPGC Event QR Attendance" system, so both
 * systems can verify each other's codes when APP_SECRET is shared
 * (see INTEGRATE_ATTENDANCE_SHARE in config/config.php).
 */

declare(strict_types=1);

final class Qr
{
    public const VERSION      = '1';
    public const TYPE_EVENT   = 'E';
    public const TYPE_STUDENT = 'S';

    public static function newNonce(): string
    {
        return bin2hex(random_bytes(6));   // 12 hex characters
    }

    public static function makeToken(string $type, string $key, string $nonce): string
    {
        $payload = implode('|', [self::VERSION, $type, $key, $nonce]);
        $sig     = substr(hash_hmac('sha256', $payload, APP_SECRET, true), 0, 16);
        return Security::b64UrlEncode($payload) . '.' . Security::b64UrlEncode($sig);
    }

    /**
     * Verifies the signature of a scanned token.
     *
     * @return array{type:string,key:string,nonce:string}|null
     */
    public static function readToken(?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '' || strlen($raw) > 512 || !str_contains($raw, '.')) {
            return null;
        }
        [$encodedPayload, $encodedSig] = explode('.', $raw, 2);
        $payload = Security::b64UrlDecode($encodedPayload);
        $sig     = Security::b64UrlDecode($encodedSig);
        if ($payload === null || $sig === null) {
            return null;
        }

        $expected = substr(hash_hmac('sha256', $payload, APP_SECRET, true), 0, 16);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $parts = explode('|', $payload);
        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            return null;
        }
        [, $type, $key, $nonce] = $parts;
        if (!in_array($type, [self::TYPE_EVENT, self::TYPE_STUDENT], true) || $key === '' || $nonce === '') {
            return null;
        }

        return ['type' => $type, 'key' => $key, 'nonce' => $nonce];
    }

    /* ---------------------------------------------------------------------
     * Event QR
     * ------------------------------------------------------------------ */

    /** Ensures the event has a nonce and returns its signed token. */
    public static function eventToken(array $event, bool $reissue = false): string
    {
        $nonce = (string) ($event['qr_nonce'] ?? '');
        if ($nonce === '' || $reissue) {
            $nonce = self::newNonce();
            Database::update('events', ['qr_nonce' => $nonce, 'updated_at' => Helpers::now()], 'id = :id', ['id' => (int) $event['id']]);
        }
        return self::makeToken(self::TYPE_EVENT, (string) $event['event_code'], $nonce);
    }

    /** Raw QR payload: the token for in-app scanners, or a URL for phone cameras. */
    public static function eventPayload(array $event, string $mode = 'url'): string
    {
        $token = self::eventToken($event);
        return $mode === 'token' ? $token : Helpers::absoluteUrl('scan.php?t=' . urlencode($token));
    }

    /** Resolves a scanned event token back to the events row. */
    public static function eventFromToken(?string $raw): ?array
    {
        $parsed = self::readToken($raw);
        if ($parsed === null || $parsed['type'] !== self::TYPE_EVENT) {
            return null;
        }
        $event = Database::one('SELECT * FROM events WHERE event_code = :c', ['c' => $parsed['key']]);
        if ($event === null || !hash_equals((string) $event['qr_nonce'], $parsed['nonce'])) {
            return null;   // re-issued or unknown event code
        }
        return $event;
    }

    /* ---------------------------------------------------------------------
     * Student QR ID
     * ------------------------------------------------------------------ */

    /** Ensures the student has a nonce and returns the signed ID token. */
    public static function studentToken(array $student, bool $reissue = false): string
    {
        $nonce = (string) ($student['qr_nonce'] ?? '');
        if ($nonce === '' || $reissue) {
            $nonce = self::newNonce();
            Database::update('students', ['qr_nonce' => $nonce, 'updated_at' => Helpers::now()], 'id = :id', ['id' => (int) $student['id']]);
        }
        return self::makeToken(self::TYPE_STUDENT, (string) $student['student_id'], $nonce);
    }

    /** Resolves a scanned student ID token back to the students row. */
    public static function studentFromToken(?string $raw): ?array
    {
        $parsed = self::readToken($raw);
        if ($parsed === null || $parsed['type'] !== self::TYPE_STUDENT) {
            return null;
        }
        $student = Database::one('SELECT * FROM students WHERE student_id = :s', ['s' => $parsed['key']]);
        if ($student === null || !hash_equals((string) $student['qr_nonce'], $parsed['nonce'])) {
            return null;
        }
        return $student;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Extracts a token from whatever a scanner produced: a raw token, a full
     * scan URL, or a JSON payload from a third-party scanner application.
     */
    public static function tokenFromScan(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        if (str_starts_with($raw, '{')) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                foreach (['token', 'code', 'value', 'data'] as $key) {
                    if (isset($json[$key]) && is_string($json[$key])) {
                        return self::tokenFromScan($json[$key]);
                    }
                }
            }
        }
        if (str_contains($raw, 't=')) {
            $query = parse_url($raw, PHP_URL_QUERY);
            if (is_string($query)) {
                parse_str($query, $params);
                if (isset($params['t']) && is_string($params['t'])) {
                    return $params['t'];
                }
            }
        }
        return $raw;
    }
}
