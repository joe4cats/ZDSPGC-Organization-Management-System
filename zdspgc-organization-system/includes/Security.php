<?php
/**
 * Security.php — everything that protects the system.
 *
 *   1. CSRF tokens for every state-changing form / API call.
 *   2. Session-scoped rate limiting (logins, QR check-ins).
 *   3. Input cleaning, CSV formula-injection guard, password policy.
 *   4. Upload validation (extension + real MIME + size + random name).
 *   5. Audit logging entry point (delegates to Audit.php).
 */

declare(strict_types=1);

final class Security
{
    /* ---------------------------------------------------------------------
     * CSRF
     * ------------------------------------------------------------------ */

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . Helpers::e(self::csrfToken()) . '">';
    }

    /** Accepts a form field, the X-CSRF-Token header, or a JSON body token. */
    public static function csrfSupplied(): string
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (is_string($header) && $header !== '') {
            return $header;
        }
        $field = $_POST['csrf_token'] ?? '';
        if (is_string($field) && $field !== '') {
            return $field;
        }
        $json = Helpers::jsonInput();
        return isset($json['csrf_token']) && is_string($json['csrf_token']) ? $json['csrf_token'] : '';
    }

    public static function csrfValid(?string $token = null): bool
    {
        $token ??= self::csrfSupplied();
        $known = $_SESSION['csrf_token'] ?? '';
        return is_string($known) && $known !== '' && is_string($token) && $token !== ''
            && hash_equals($known, $token);
    }

    /** Stops a request when the CSRF token is missing or invalid. */
    public static function requireCsrf(): void
    {
        if (self::csrfValid()) {
            return;
        }
        if (defined('ZDSPGC_API_MODE')) {
            Helpers::jsonFail('Your session expired. Please reload the page and try again.', 419, 'csrf');
        }
        Helpers::flash('error', 'Security token expired. Please try again.');
        Helpers::redirect('login.php');
    }

    /* ---------------------------------------------------------------------
     * Rate limiting (per session; cheap and effective for a single campus)
     * ------------------------------------------------------------------ */

    /** @return array{allowed:bool,hits:int,retry_after:int} */
    public static function rateLimit(string $key, int $max, int $windowSeconds): array
    {
        $now  = time();
        $hits = array_values(array_filter(
            (array) ($_SESSION['rl'][$key] ?? []),
            static fn ($t) => is_int($t) && ($now - $t) < $windowSeconds
        ));

        if (count($hits) >= $max) {
            $oldest = min($hits);
            return ['allowed' => false, 'hits' => count($hits), 'retry_after' => max(1, ($oldest + $windowSeconds) - $now)];
        }

        $hits[] = $now;
        $_SESSION['rl'][$key] = $hits;
        return ['allowed' => true, 'hits' => count($hits), 'retry_after' => 0];
    }

    /* ---------------------------------------------------------------------
     * Input cleaning / validation
     * ------------------------------------------------------------------ */

    public static function clean(?string $value, int $maxLength = 255): string
    {
        $value = (string) $value;
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        return mb_substr(trim($value), 0, $maxLength);
    }

    /** CSV/Excel formula-injection guard used by every CSV export. */
    public static function csvSafe(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    public static function validEmail(?string $email): bool
    {
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Password policy: at least 8 characters containing a letter and a number.
     * Returns a human-readable problem, or null when the password is fine.
     */
    public static function passwordProblem(string $password): ?string
    {
        if (mb_strlen($password) < 8) {
            return 'Password must be at least 8 characters long.';
        }
        if (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            return 'Password must contain at least one letter and one number.';
        }
        return null;
    }

    public static function ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return 'cli';
        }
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? 'unknown' : $ip;
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 190);
    }

    /** Convenience wrapper so callers do not need the Audit class. */
    public static function audit(string $action, string $module, string $description = '', ?int $referenceId = null): void
    {
        Audit::log($action, $module, $description, $referenceId);
    }

    /* ---------------------------------------------------------------------
     * Upload validation
     * ------------------------------------------------------------------ */

    /**
     * Validates one $_FILES entry against an extension => MIME allowlist.
     *
     * @param array<string,mixed>             $file      one entry of $_FILES
     * @param array<string,array<int,string>> $allowlist
     * @return array{ok:bool,message:string,ext:string,mime:string,original:string}
     */
    public static function validateUpload(array $file, array $allowlist, int $maxBytes): array
    {
        $fail = static fn (string $m, string $ext = '', string $mime = '', string $orig = '') =>
            ['ok' => false, 'message' => $m, 'ext' => $ext, 'mime' => $mime, 'original' => $orig];

        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_NO_FILE) {
            return $fail('No file was uploaded.');
        }
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            return $fail('The file is larger than the server limit (' . Helpers::fileSize($maxBytes) . ').');
        }
        if ($code !== UPLOAD_ERR_OK) {
            return $fail('Upload failed (error code ' . $code . '). Please try again.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return $fail('Upload rejected: invalid temporary file.');
        }

        $original = self::clean((string) ($file['name'] ?? 'file'), 160);
        $size     = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return $fail('The uploaded file is empty.');
        }
        if ($size > $maxBytes) {
            return $fail('The file exceeds the maximum size of ' . Helpers::fileSize($maxBytes) . '.');
        }

        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($ext === '' || !isset($allowlist[$ext])) {
            return $fail('File type ".' . $ext . '" is not allowed.', $ext, '', $original);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);
        if (!in_array($mime, $allowlist[$ext], true)) {
            return $fail('The file content (' . $mime . ') does not match its extension (.' . $ext . ').', $ext, $mime, $original);
        }

        return ['ok' => true, 'message' => 'OK', 'ext' => $ext, 'mime' => $mime, 'original' => $original];
    }

    /**
     * Moves a validated upload into $targetDir with a randomised, safe name.
     *
     * @param array<string,mixed> $file
     * @return array{ok:bool,message:string,name:string}
     */
    public static function storeUpload(array $file, string $targetDir, string $ext): array
    {
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true)) {
            return ['ok' => false, 'message' => 'Upload folder is not writable: ' . basename($targetDir), 'name' => ''];
        }
        $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            return ['ok' => false, 'message' => 'The file could not be saved on the server.', 'name' => ''];
        }
        @chmod($dest, 0644);
        return ['ok' => true, 'message' => 'Stored as ' . $name, 'name' => $name];
    }

    /** Never trust a stored file name in a path — keep only a safe basename. */
    public static function safeFileName(?string $name): string
    {
        $name = basename((string) $name);
        return preg_match('/^[A-Za-z0-9._-]{1,120}$/', $name) === 1 ? $name : '';
    }

    /* ------------------------------------------------------------------ */

    public static function b64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64UrlDecode(string $data): ?string
    {
        $data = strtr($data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($data, true);
        return $decoded === false ? null : $decoded;
    }
}
