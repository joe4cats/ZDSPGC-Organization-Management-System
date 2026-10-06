<?php
/**
 * Helpers.php — small, boring, reusable functions used by every page.
 * Output escaping, request input, URLs, flash messages, dates, formatting.
 */

declare(strict_types=1);

final class Helpers
{
    /* ---------------------------------------------------------------------
     * Output / escaping
     * ------------------------------------------------------------------ */

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Very small safe-HTML allowlist for announcement bodies (line breaks, bold). */
    public static function rich(?string $text): string
    {
        $safe = self::e((string) $text);
        $safe = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe) ?? $safe;
        return nl2br($safe, true);
    }

    public static function excerpt(?string $text, int $length = 140): string
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text) ?? '');
        return mb_strlen($text) <= $length ? $text : rtrim(mb_substr($text, 0, $length - 1)) . '…';
    }

    public static function initials(?string $name, int $max = 2): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $out   = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $out .= mb_strtoupper(mb_substr($part, 0, 1));
            if (mb_strlen($out) >= $max) {
                break;
            }
        }
        return $out === '' ? '?' : $out;
    }

    /* ---------------------------------------------------------------------
     * Request input
     * ------------------------------------------------------------------ */

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = $_GET[$key] ?? null;
        return is_string($v) && $v !== '' ? trim($v) : $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $v = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
        return $v === false || $v === null ? $default : (int) $v;
    }

    public static function post(string $key, ?string $default = null): ?string
    {
        $v = $_POST[$key] ?? null;
        return is_string($v) && $v !== '' ? trim($v) : $default;
    }

    public static function postInt(string $key, int $default = 0): int
    {
        $v = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
        return $v === false || $v === null ? $default : (int) $v;
    }

    /** Reads and caches a JSON request body (used by api/*). @return array<string,mixed> */
    public static function jsonInput(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $raw   = file_get_contents('php://input') ?: '';
        $data  = json_decode($raw, true);
        $cache = is_array($data) ? $data : [];
        return $cache;
    }

    /** Boolean form/JSON flag ("1", "on", "true"). */
    public static function inputBool(string $key): bool
    {
        $v = $_POST[$key] ?? '';
        if (!is_string($v) || $v === '') {
            $json = self::jsonInput();
            $v = isset($json[$key]) ? (string) json_encode($json[$key]) : '';
        }
        return in_array(strtolower((string) $v), ['1', 'on', 'true', 'yes'], true);
    }

    /** Form field or JSON body — whichever the client sent. */
    public static function input(string $key, ?string $default = null): ?string
    {
        $v = $_POST[$key] ?? null;
        if (!is_string($v) || $v === '') {
            $json = self::jsonInput();
            if (isset($json[$key]) && is_scalar($json[$key])) {
                $v = (string) $json[$key];
            }
        }
        return is_string($v) && $v !== '' ? trim($v) : $default;
    }

    public static function inputInt(string $key, int $default = 0): int
    {
        $v = self::input($key);
        return $v === null || !is_numeric($v) ? $default : (int) $v;
    }

    public static function isPost(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    }

    public static function isAjax(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || (string) ($_SERVER['HTTP_ACCEPT'] ?? '') !== '' && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json');
    }

    /* ---------------------------------------------------------------------
     * JSON responses (consistent envelope for api/*)
     * ------------------------------------------------------------------ */

    /** @param array<string,mixed> $data */
    public static function jsonOk(string $message = 'OK', array $data = [], int $status = 200): never
    {
        self::jsonOut(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    public static function jsonFail(string $message, int $status = 400, string $code = '', array $extra = []): never
    {
        self::jsonOut(['success' => false, 'message' => $message, 'code' => $code] + $extra, $status);
    }

    /** @param array<string,mixed> $payload */
    public static function jsonOut(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---------------------------------------------------------------------
     * URLs / redirects
     * ------------------------------------------------------------------ */

    /** Web path of the project root, e.g. "" on php -S or "/zdspgc-organization-system" in htdocs. */
    public static function basePath(): string
    {
        static $base = null;
        if (is_string($base)) {
            return $base;
        }
        $base    = '';
        $docRoot = str_replace('\\', '/', rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
        $appRoot = str_replace('\\', '/', rtrim(APP_ROOT, '/'));
        if ($docRoot !== '' && $appRoot !== '' && str_starts_with($appRoot, $docRoot)) {
            $base = rtrim(substr($appRoot, strlen($docRoot)), '/');
        }
        return $base;
    }

    /** Absolute web URL of a file inside the project ("admin/events.php"). */
    public static function url(string $path = ''): string
    {
        return self::basePath() . '/' . ltrim($path, '/');
    }

    /** Absolute URL usable from QR links, e-mails and external systems. */
    public static function absoluteUrl(string $path = ''): string
    {
        $path = ltrim($path, '/');
        if (PUBLIC_BASE_URL !== '') {
            return rtrim(PUBLIC_BASE_URL, '/') . '/' . $path;
        }
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $base   = self::basePath();
        $root   = $base !== '' ? $base : ($dir === '/' || $dir === '.' ? '' : $dir);
        return ($https ? 'https' : 'http') . '://' . $host . $root . '/' . $path;
    }

    public static function redirect(string $path): never
    {
        $target = preg_match('#^(https?:)?//#', $path) === 1 ? $path : self::url($path);
        if (!headers_sent()) {
            header('Location: ' . $target);
            exit;
        }
        echo '<script>location.replace(' . json_encode($target) . ');</script>';
        exit;
    }

    /** Redirect back to the page the user came from (with a safe fallback). */
    public static function redirectBack(string $fallback = 'index.php'): never
    {
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref !== '' && str_contains($ref, (string) ($_SERVER['HTTP_HOST'] ?? ''))) {
            self::redirect($ref);
        }
        self::redirect($fallback);
    }

    /** File name of the running script ("events.php"). */
    public static function currentPage(): string
    {
        return basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    }

    /* ---------------------------------------------------------------------
     * Flash messages
     * ------------------------------------------------------------------ */

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function takeFlashes(): array
    {
        $flashes = (array) ($_SESSION['flash'] ?? []);
        unset($_SESSION['flash']);
        return array_values(array_filter($flashes, static fn ($f) => is_array($f) && isset($f['type'], $f['message'])));
    }

    /* ---------------------------------------------------------------------
     * Dates (all app times are in APP_TIMEZONE, stored as 'Y-m-d H:i:s')
     * ------------------------------------------------------------------ */

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return date('Y-m-d');
    }

    public static function fmtDate(?string $value, string $format = 'M j, Y'): string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000-00-00')) {
            return '—';
        }
        $ts = strtotime($value);
        return $ts === false ? '—' : date($format, $ts);
    }

    public static function fmtDateTime(?string $value): string
    {
        return self::fmtDate($value, 'M j, Y · g:i A');
    }

    public static function fmtTime(?string $value): string
    {
        return self::fmtDate($value, 'g:i A');
    }

    public static function humanAgo(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return '—';
        }
        $sec    = time() - $ts;
        $suffix = ' ago';
        if ($sec < 0) {
            $sec    = -$sec;
            $suffix = ' from now';
        }
        if ($sec < 60)        return $sec . 's' . $suffix;
        if ($sec < 3600)      return (string) floor($sec / 60) . 'm' . $suffix;
        if ($sec < 86400)     return (string) floor($sec / 3600) . 'h' . $suffix;
        if ($sec < 2592000)   return (string) floor($sec / 86400) . 'd' . $suffix;
        return self::fmtDate($value);
    }

    /** Days between now and a date (negative = already past). */
    public static function daysUntil(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : (int) ceil(($ts - time()) / 86400);
    }

    /* ---------------------------------------------------------------------
     * Numbers / formatting
     * ------------------------------------------------------------------ */

    public static function percent(int|float $part, int|float $whole, int $decimals = 1): float
    {
        return $whole <= 0 ? 0.0 : round(($part / $whole) * 100, $decimals);
    }

    public static function money(float|int|string|null $amount): string
    {
        return '₱' . number_format((float) $amount, 2);
    }

    public static function fileSize(int|float|null $bytes): string
    {
        $bytes = (float) $bytes;
        if ($bytes < 1024)       return number_format($bytes) . ' B';
        if ($bytes < 1048576)    return number_format($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return number_format($bytes / 1048576, 1) . ' MB';
        return number_format($bytes / 1073741824, 2) . ' GB';
    }

    public static function page(): int
    {
        $p = self::getInt('page', 1);
        return $p < 1 ? 1 : $p;
    }
}
