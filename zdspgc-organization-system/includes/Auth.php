<?php
/**
 * Auth.php — sessions, sign-in/sign-out, remember-me, role guards.
 *
 * Passwords are stored with password_hash()/password_verify() (bcrypt).
 * Sign-in accepts an e-mail address, a username, or a Student ID.
 * "Remember me" uses a selector + hashed validator pair stored in the
 * remember_tokens table, so a stolen cookie can be revoked server side.
 */

declare(strict_types=1);

final class Auth
{
    public const ROLES = ['admin', 'adviser', 'officer', 'student'];
    private const REMEMBER_COOKIE = 'zdspgc_org_remember';

    /* ---------------------------------------------------------------------
     * Sign-in
     * ------------------------------------------------------------------ */

    /** @return array{ok:bool,message:string,user:?array} */
    public static function attempt(string $identity, string $password, bool $remember = false): array
    {
        $limit = Security::rateLimit('login', LOGIN_MAX_ATTEMPTS, LOGIN_LOCKOUT_MINUTES * 60);
        if (!$limit['allowed']) {
            return [
                'ok'      => false,
                'message' => 'Too many attempts. Please wait ' . ceil($limit['retry_after'] / 60) . ' minute(s) and try again.',
                'user'    => null,
            ];
        }

        $identity = mb_strtolower(trim($identity));
        $user = Database::one(
            "SELECT u.* FROM users u
              LEFT JOIN students s ON s.user_id = u.id
             WHERE u.username = :id OR u.email = :id2 OR UPPER(s.student_id) = :id3
             LIMIT 1",
            ['id' => $identity, 'id2' => $identity, 'id3' => strtoupper(trim($identity))]
        );

        // Always verify a hash, so a missing account and a wrong password take
        // about the same time (blocks account enumeration by timing).
        $hash   = (string) ($user['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv');
        $passOk = password_verify($password, $hash);

        if ($user === null || !$passOk) {
            Security::audit('LOGIN_FAILED', 'auth', 'Failed sign-in for "' . $identity . '"');
            return ['ok' => false, 'message' => 'Invalid credentials. Please check your e-mail / Student ID and password.', 'user' => null];
        }

        if ((string) $user['status'] !== 'active') {
            Security::audit('LOGIN_BLOCKED', 'auth', 'Inactive account "' . $identity . '" tried to sign in', (int) $user['id']);
            return ['ok' => false, 'message' => 'This account is not active. Please contact the system administrator.', 'user' => null];
        }

        unset($_SESSION['rl']['login']);
        self::startSession($user);
        Database::update('users', ['last_login_at' => Helpers::now()], 'id = :id', ['id' => (int) $user['id']]);

        if ($remember) {
            self::issueRememberToken((int) $user['id']);
        }

        Security::audit('LOGIN', 'auth', 'Signed in as ' . $user['role'], (int) $user['id']);
        return ['ok' => true, 'message' => 'Welcome back, ' . (string) $user['full_name'] . '.', 'user' => $user];
    }

    /** @param array<string,mixed> $user */
    public static function startSession(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['uid']       = (int) $user['id'];
        $_SESSION['role']      = (string) $user['role'];
        $_SESSION['name']      = (string) $user['full_name'];
        $_SESSION['avatar']    = (string) ($user['avatar'] ?? '');
        $_SESSION['last_seen'] = time();
        unset($_SESSION['rl']['login']);
    }

    /* ---------------------------------------------------------------------
     * Remember me (selector + hashed validator)
     * ------------------------------------------------------------------ */

    private static function cookiePath(): string
    {
        return Helpers::basePath() === '' ? '/' : Helpers::basePath() . '/';
    }

    private static function issueRememberToken(int $userId): void
    {
        try {
            $selector  = bin2hex(random_bytes(9));
            $validator = bin2hex(random_bytes(32));

            Database::delete('remember_tokens', 'user_id = :u', ['u' => $userId]);
            Database::insert('remember_tokens', [
                'user_id'        => $userId,
                'selector'       => $selector,
                'validator_hash' => hash('sha256', $validator),
                'expires_at'     => date('Y-m-d H:i:s', time() + (REMEMBER_DAYS * 86400)),
                'created_at'     => Helpers::now(),
            ]);

            setcookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, [
                'expires'  => time() + (REMEMBER_DAYS * 86400),
                'path'     => self::cookiePath(),
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            ]);
        } catch (Throwable $e) {
            // A failing remember-me must never block the sign-in itself.
        }
    }

    /** Called from bootstrap on every request: signs the user back in. */
    public static function resumeFromCookie(): void
    {
        if (self::check() || empty($_COOKIE[self::REMEMBER_COOKIE])) {
            return;
        }
        $raw = (string) $_COOKIE[self::REMEMBER_COOKIE];
        if (!str_contains($raw, ':')) {
            self::forgetRememberCookie();
            return;
        }
        [$selector, $validator] = explode(':', $raw, 2);

        try {
            $row = Database::one(
                'SELECT * FROM remember_tokens WHERE selector = :s AND expires_at > :now',
                ['s' => $selector, 'now' => Helpers::now()]
            );
            if ($row === null || !hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
                self::forgetRememberCookie();
                return;
            }
            $user = Database::one("SELECT * FROM users WHERE id = :id AND status = 'active'", ['id' => (int) $row['user_id']]);
            if ($user === null) {
                self::forgetRememberCookie();
                return;
            }
            self::startSession($user);
            self::issueRememberToken((int) $user['id']);   // rotate the token
        } catch (Throwable $e) {
            self::forgetRememberCookie();
        }
    }

    private static function forgetRememberCookie(): void
    {
        if (!empty($_COOKIE[self::REMEMBER_COOKIE])) {
            setcookie(self::REMEMBER_COOKIE, '', [
                'expires'  => time() - 3600,
                'path'     => self::cookiePath(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            unset($_COOKIE[self::REMEMBER_COOKIE]);
        }
    }

    public static function logout(string $reason = 'User sign-out'): void
    {
        $userId = self::id();
        if ($userId !== null) {
            try {
                Database::delete('remember_tokens', 'user_id = :u', ['u' => $userId]);
            } catch (Throwable $e) {
                // ignore — the session is destroyed regardless
            }
            Security::audit('LOGOUT', 'auth', $reason);
        }
        self::forgetRememberCookie();

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /* ---------------------------------------------------------------------
     * Current user accessors
     * ------------------------------------------------------------------ */

    public static function check(): bool
    {
        return isset($_SESSION['uid']) && (int) $_SESSION['uid'] > 0;
    }

    public static function id(): ?int
    {
        return self::check() ? (int) $_SESSION['uid'] : null;
    }

    public static function role(): ?string
    {
        return self::check() ? (string) ($_SESSION['role'] ?? '') : null;
    }

    public static function userName(): ?string
    {
        return self::check() ? (string) ($_SESSION['name'] ?? '') : null;
    }

    public static function roleLabel(?string $role = null): string
    {
        $role ??= (string) self::role();
        return Permissions::ROLE_LABELS[$role] ?? ucfirst($role === '' ? 'guest' : $role);
    }

    /** Fresh user row (never contains the password hash). @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        return Database::one(
            'SELECT id, username, email, full_name, role, status, avatar, phone, last_login_at, created_at
               FROM users WHERE id = :id',
            ['id' => self::id()]
        );
    }

    /** The students row of the signed-in user (cached per request). */
    public static function studentProfile(): ?array
    {
        static $profile = null;
        static $loaded  = false;
        if ($loaded) {
            return $profile;
        }
        $loaded = true;
        if (!self::check()) {
            return $profile = null;
        }
        $profile = Database::one('SELECT * FROM students WHERE user_id = :u', ['u' => self::id()]);
        return $profile;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /* ---------------------------------------------------------------------
     * Guards
     * ------------------------------------------------------------------ */

    /** Idle-timeout enforcement; called once per request from bootstrap. */
    public static function touch(): void
    {
        if (!self::check()) {
            return;
        }
        $last = (int) ($_SESSION['last_seen'] ?? time());
        if (time() - $last > SESSION_IDLE_MINUTES * 60) {
            self::logout('Session expired (idle)');
            Helpers::flash('warning', 'You were signed out after ' . SESSION_IDLE_MINUTES . ' minutes of inactivity.');
            Helpers::redirect('login.php');
        }
        $_SESSION['last_seen'] = time();
    }

    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }
        if (defined('ZDSPGC_API_MODE')) {
            Helpers::jsonFail('Please sign in to continue.', 401, 'unauthenticated');
        }
        $_SESSION['intended'] = (string) ($_SERVER['REQUEST_URI'] ?? '');
        Helpers::flash('warning', 'Please sign in to continue.');
        Helpers::redirect('login.php');
    }

    /** Restricts a page to one or more roles. */
    public static function requireRole(array|string $roles): void
    {
        self::requireLogin();
        $roles = is_array($roles) ? $roles : [$roles];
        if (in_array((string) self::role(), $roles, true)) {
            return;
        }
        if (defined('ZDSPGC_API_MODE')) {
            Helpers::jsonFail('You do not have permission to perform this action.', 403, 'forbidden');
        }
        Helpers::flash('error', 'That area is not available for your role.');
        Helpers::redirect(Permissions::homeForRole());
    }
}
