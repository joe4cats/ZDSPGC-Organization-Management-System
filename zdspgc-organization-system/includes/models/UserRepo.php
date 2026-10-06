<?php
/**
 * models/UserRepo.php — accounts (all roles) and the adviser roster.
 *
 * Student accounts are created through StudentRepo::create(); this repository
 * covers everything the administration screens need for users and advisers.
 */

declare(strict_types=1);

final class UserRepo
{
    private const SELECT = 'SELECT u.*, d.name AS department_name, d.code AS department_code,
            a.id AS adviser_id, a.employee_no, a.specialization,
            s.id AS student_profile_id, s.student_id, s.course, s.year_level
            FROM users u
            LEFT JOIN advisers a ON a.user_id = u.id
            LEFT JOIN departments d ON d.id = a.department_id
            LEFT JOIN students s ON s.user_id = u.id';

    /**
     * @param array<string,mixed> $filters q, role, status
     * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int}
     */
    public static function list(array $filters, int $page = 1, int $perPage = 25): array
    {
        [$whereSql, $params] = self::where($filters);
        $where = ' WHERE ' . $whereSql;
        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM users u LEFT JOIN students s ON s.user_id = u.id' . $where,
            $params
        );
        $page = max(1, $page);

        return [
            'rows'  => Database::all(
                self::SELECT . ' ' . $where
                . ' ORDER BY FIELD(u.role, "admin","adviser","officer","student"), u.full_name ASC'
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
        return Database::one(self::SELECT . ' WHERE u.id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByUsername(string $username): ?array
    {
        return Database::one(self::SELECT . ' WHERE u.username = :u', ['u' => $username]);
    }

    /**
     * @param array<string,mixed> $data username, email, role, full_name, phone, password, status
     * @param string|null $password alternative to the password field in $data
     */
    public static function create(array $data, ?string $password = null): int
    {
        $username = strtolower(trim((string) ($data['username'] ?? '')));
        $email    = strtolower(trim((string) ($data['email'] ?? '')));
        $role     = (string) ($data['role'] ?? 'student');
        $fullName = trim((string) ($data['full_name'] ?? ''));
        $plain    = trim((string) ($data['password'] ?? ''));
        if ($plain === '' && $password !== null) {
            $plain = $password;
        }

        if ($username === '' || $fullName === '') {
            throw new RuntimeException('Username and full name are required.');
        }
        if (!in_array($role, ['admin', 'adviser', 'officer', 'student'], true)) {
            throw new InvalidArgumentException('Unknown role.');
        }
        if (!Security::validEmail($email)) {
            throw new RuntimeException('A valid e-mail address is required.');
        }
        if (Database::count('users', 'username = :u', ['u' => $username]) > 0) {
            throw new RuntimeException('That username is already taken.');
        }
        if (Database::count('users', 'email = :e', ['e' => $email]) > 0) {
            throw new RuntimeException('An account with that e-mail already exists.');
        }
        if ($plain !== '') {
            $problem = Security::passwordProblem($plain);
            if ($problem !== null) {
                throw new RuntimeException($problem);
            }
        } else {
            $plain = 'Zdspgc@' . bin2hex(random_bytes(4));
        }

        $id = Database::insert('users', [
            'username'      => mb_substr($username, 0, 60),
            'email'         => mb_substr($email, 0, 160),
            'password_hash' => Auth::hash($plain),
            'role'          => $role,
            'full_name'     => mb_substr($fullName, 0, 160),
            'phone'         => mb_substr(trim((string) ($data['phone'] ?? '')), 0, 30),
            'status'        => in_array((string) ($data['status'] ?? 'active'), ['active', 'inactive', 'suspended'], true)
                ? (string) $data['status'] : 'active',
            'created_at'    => Helpers::now(),
        ]);

        Audit::log('USER_CREATED', 'users', 'Created account ' . $username . ' (' . $role . ')', $id);

        return $id;
    }

    /**
     * @param array<string,mixed> $data
     * @param string|null $password when set the password is rotated
     */
    public static function updateUser(int $id, array $data, ?string $password = null): void
    {
        $user = self::find($id);
        if ($user === null) {
            throw new RuntimeException('Account not found.');
        }

        $row = ['updated_at' => Helpers::now()];
        foreach (['full_name', 'phone'] as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = mb_substr(trim((string) $data[$key]), 0, 160);
            }
        }
        if (isset($row['full_name']) && $row['full_name'] === '') {
            throw new RuntimeException('The full name cannot be empty.');
        }
        if (array_key_exists('email', $data)) {
            $email = strtolower(trim((string) $data['email']));
            if (!Security::validEmail($email)) {
                throw new RuntimeException('A valid e-mail address is required.');
            }
            $taken = Database::count('users', 'email = :e AND id <> :i', ['e' => $email, 'i' => $id]);
            if ($taken > 0) {
                throw new RuntimeException('Another account already uses that e-mail.');
            }
            $row['email'] = mb_substr($email, 0, 160);
        }
        if (array_key_exists('role', $data)
            && in_array((string) $data['role'], ['admin', 'adviser', 'officer', 'student'], true)) {
            $row['role'] = (string) $data['role'];
        }
        if (array_key_exists('status', $data)
            && in_array((string) $data['status'], ['active', 'inactive', 'suspended'], true)) {
            $row['status'] = (string) $data['status'];
        }
        if ($password !== null && $password !== '') {
            $problem = Security::passwordProblem($password);
            if ($problem !== null) {
                throw new RuntimeException($problem);
            }
            $row['password_hash'] = Auth::hash($password);
        }

        Database::update('users', $row, 'id = :id', ['id' => $id]);
        if ($password !== null && $password !== '') {
            Database::delete('remember_tokens', 'user_id = :u', ['u' => $id]);
        }
        Audit::log('USER_UPDATED', 'users', 'Updated account ' . (string) $user['username'], $id);
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
            throw new InvalidArgumentException('Unknown account status.');
        }
        $user = self::find($id);
        if ($user === null) {
            throw new RuntimeException('Account not found.');
        }
        if ((int) $id === (int) Auth::id() && $status !== 'active') {
            throw new RuntimeException('You cannot deactivate your own account.');
        }

        Database::update('users', ['status' => $status, 'updated_at' => Helpers::now()], 'id = :id', ['id' => $id]);
        if ($status !== 'active') {
            Database::delete('remember_tokens', 'user_id = :u', ['u' => $id]);
        }
        Audit::log('USER_' . strtoupper($status), 'users',
            'Account ' . (string) $user['username'] . ' is now ' . $status, $id);
    }

    /* ------------------------------------------------------------- advisers */

    /**
     * @param array<string,mixed> $filters q, status, department_id
     * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int}
     */
    public static function advisers(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where  = ['1'];
        $params = [];
        if (!empty($filters['q'])) {
            $where[] = '(u.full_name LIKE :q OR u.username LIKE :q2 OR a.employee_no LIKE :q3 OR u.email LIKE :q4)';
            foreach (['q', 'q2', 'q3', 'q4'] as $key) {
                $params[$key] = '%' . $filters['q'] . '%';
            }
        }
        if (!empty($filters['status'])) {
            $where[]         = 'a.status = :st';
            $params['st']    = (string) $filters['status'];
        }
        if (!empty($filters['department_id'])) {
            $where[]          = 'a.department_id = :d';
            $params['d']      = (int) $filters['department_id'];
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM advisers a JOIN users u ON u.id = a.user_id' . $whereSql,
            $params
        );
        $page = max(1, $page);

        return [
            'rows'  => Database::all(
                'SELECT a.*, u.username, u.email, u.full_name, u.status AS account_status,
                        d.name AS department_name,
                        (SELECT COUNT(*) FROM organizations o WHERE o.adviser_id = a.id) AS organization_count
                   FROM advisers a
                   JOIN users u ON u.id = a.user_id
                   LEFT JOIN departments d ON d.id = a.department_id'
                . $whereSql
                . ' ORDER BY u.full_name ASC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
                $params
            ),
            'total' => $total,
            'pages' => (int) max(1, ceil($total / max(1, $perPage))),
        ];
    }

    /**
     * Compact adviser list for select boxes: id => "Full name · employee no".
     *
     * @return array<int,string>
     */
    public static function adviserOptions(bool $activeOnly = true): array
    {
        $rows = Database::all(
            'SELECT a.id, u.full_name, a.employee_no FROM advisers a
               JOIN users u ON u.id = a.user_id'
            . ($activeOnly ? " WHERE a.status = 'active' AND u.status = 'active'" : '')
            . ' ORDER BY u.full_name ASC'
        );
        $options = [];
        foreach ($rows as $row) {
            $label = (string) $row['full_name'];
            if ((string) $row['employee_no'] !== '') {
                $label .= ' · ' . (string) $row['employee_no'];
            }
            $options[(int) $row['id']] = $label;
        }
        return $options;
    }

    /**
     * Creates the adviser account (user row + advisers row) in one step.
     *
     * @param array<string,mixed> $data full_name, email, username, password,
     *                                  department_id, employee_no, specialization
     */
    public static function createAdviser(array $data): int
    {
        $userId = 0;
        Database::transaction(static function () use ($data, &$userId): void {
            $userId = self::create([
                'username'  => (string) ($data['username'] ?? ''),
                'email'     => (string) ($data['email'] ?? ''),
                'role'      => 'adviser',
                'full_name' => (string) ($data['full_name'] ?? ''),
                'phone'     => (string) ($data['phone'] ?? ''),
                'password'  => (string) ($data['password'] ?? ''),
            ]);
            Database::insert('advisers', [
                'user_id'        => $userId,
                'employee_no'    => mb_substr(trim((string) ($data['employee_no'] ?? '')), 0, 40),
                'department_id'  => !empty($data['department_id']) ? (int) $data['department_id'] : null,
                'specialization' => mb_substr(trim((string) ($data['specialization'] ?? '')), 0, 160),
                'status'         => 'active',
                'created_at'     => Helpers::now(),
            ]);
        });

        Audit::log('ADVISER_CREATED', 'users', 'Created adviser account for ' . (string) $data['full_name'], $userId);

        return $userId;
    }

    /**
     * @param array<string,mixed> $data employee_no, department_id, specialization, status
     */
    public static function updateAdviser(int $adviserId, array $data): void
    {
        $row = [];
        if (array_key_exists('employee_no', $data)) {
            $row['employee_no'] = mb_substr(trim((string) $data['employee_no']), 0, 40);
        }
        if (array_key_exists('department_id', $data)) {
            $row['department_id'] = !empty($data['department_id']) ? (int) $data['department_id'] : null;
        }
        if (array_key_exists('specialization', $data)) {
            $row['specialization'] = mb_substr(trim((string) $data['specialization']), 0, 160);
        }
        if (array_key_exists('status', $data) && in_array((string) $data['status'], ['active', 'inactive'], true)) {
            $row['status'] = (string) $data['status'];
        }
        if ($row === []) {
            return;
        }
        Database::update('advisers', $row, 'id = :id', ['id' => $adviserId]);
        Audit::log('ADVISER_UPDATED', 'users', 'Updated adviser #' . $adviserId, $adviserId);
    }

    /** @return array<string,mixed>|null */
    public static function findAdviser(int $adviserId): ?array
    {
        return Database::one(
            'SELECT a.*, u.username, u.email, u.full_name, u.status AS account_status, d.name AS department_name
               FROM advisers a
               JOIN users u ON u.id = a.user_id
               LEFT JOIN departments d ON d.id = a.department_id
              WHERE a.id = :id',
            ['id' => $adviserId]
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
            $where[] = '(u.username LIKE :q OR u.full_name LIKE :q2 OR u.email LIKE :q3 OR s.student_id LIKE :q4)';
            foreach (['q', 'q2', 'q3', 'q4'] as $key) {
                $params[$key] = '%' . $filters['q'] . '%';
            }
        }
        foreach (['role' => 'u.role', 'status' => 'u.status'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]             = $column . ' = :f_' . $key;
                $params['f_' . $key] = $filters[$key];
            }
        }

        return [implode(' AND ', $where), $params];
    }
}
