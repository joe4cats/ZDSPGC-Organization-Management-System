<?php
/**
 * models/StudentRepo.php — student records: listing, profile, status changes.
 */

declare(strict_types=1);

final class StudentRepo
{
    private const SELECT = 'SELECT s.*, d.name AS department_name, u.email AS account_email,
            u.status AS account_status, u.last_login_at
            FROM students s
            LEFT JOIN departments d ON d.id = s.department_id
            LEFT JOIN users u ON u.id = s.user_id';

    /**
     * @param array<string,mixed> $filters q, department_id, course, year_level, status
     * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int}
     */
    public static function list(array $filters, int $page = 1, int $perPage = 20): array
    {
        [$whereSql, $params] = self::where($filters);
        $where = ' WHERE ' . $whereSql;
        $total = (int) Database::scalar('SELECT COUNT(*) FROM students s ' . $where, $params);
        $page  = max(1, $page);
        return [
            'rows'  => Database::all(
                self::SELECT . ' ' . $where . ' ORDER BY s.last_name ASC, s.first_name ASC'
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
        return Database::one(self::SELECT . ' WHERE s.id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByStudentId(string $studentId): ?array
    {
        return Database::one(self::SELECT . ' WHERE UPPER(s.student_id) = :s', ['s' => strtoupper(trim($studentId))]);
    }

    /**
     * Creates a student and, when a password is given, the sign-in account.
     *
     * @param array<string,mixed> $data
     * @return int the new id
     */
    public static function create(array $data, ?string $password = null): int
    {
        $studentId = strtoupper(trim((string) ($data['student_id'] ?? '')));
        if (preg_match('/^[0-9]{4}-[0-9]{5}$/', $studentId) !== 1) {
            throw new RuntimeException('Student ID must look like 2024-00412.');
        }
        if (self::findByStudentId($studentId) !== null) {
            throw new RuntimeException('Student ID ' . $studentId . ' already exists.');
        }

        $userId = null;
        if ($password !== null && $password !== '') {
            $email = trim((string) ($data['email'] ?? ''));
            if (Security::validEmail($email) === false) {
                throw new RuntimeException('A valid e-mail address is required to create an account.');
            }
            if (Database::one('SELECT id FROM users WHERE email = :e', ['e' => $email]) !== null) {
                throw new RuntimeException('That e-mail address is already registered.');
            }
            $userId = Database::insert('users', [
                'username'      => self::uniqueUsername((string) ($data['username'] ?? $studentId), $studentId),
                'email'         => $email,
                'password_hash' => Auth::hash($password),
                'role'          => 'student',
                'full_name'     => trim((string) ($data['first_name'] ?? '') . ' ' . (string) ($data['last_name'] ?? '')),
                'phone'         => (string) ($data['contact_number'] ?? ''),
                'status'        => 'active',
                'created_at'    => Helpers::now(),
            ]);
        }

        $id = Database::insert('students', [
            'user_id'         => $userId,
            'student_id'      => $studentId,
            'first_name'      => trim((string) ($data['first_name'] ?? '')),
            'middle_name'     => trim((string) ($data['middle_name'] ?? '')),
            'last_name'       => trim((string) ($data['last_name'] ?? '')),
            'suffix'          => trim((string) ($data['suffix'] ?? '')),
            'gender'          => in_array($data['gender'] ?? '', ['male', 'female', 'other'], true) ? (string) $data['gender'] : 'other',
            'birthdate'       => empty($data['birthdate']) ? null : (string) $data['birthdate'],
            'email'           => trim((string) ($data['email'] ?? '')),
            'contact_number'  => trim((string) ($data['contact_number'] ?? '')),
            'address'         => trim((string) ($data['address'] ?? '')),
            'course'          => trim((string) ($data['course'] ?? '')),
            'year_level'      => trim((string) ($data['year_level'] ?? '')),
            'section'         => trim((string) ($data['section'] ?? '')),
            'department_id'   => empty($data['department_id']) ? null : (int) $data['department_id'],
            'profile_picture' => trim((string) ($data['profile_picture'] ?? '')),
            'qr_nonce'        => Qr::newNonce(),
            'status'          => 'active',
            'created_at'      => Helpers::now(),
        ]);

        Audit::log('STUDENT_CREATED', 'students', 'Added student ' . $studentId, $id);
        return $id;
    }

    /**
     * Updates the editable student fields.
     *
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $row = [];
        foreach (['first_name', 'middle_name', 'last_name', 'suffix', 'address', 'contact_number',
                  'email', 'course', 'year_level', 'section', 'profile_picture'] as $column) {
            if (array_key_exists($column, $data)) {
                $row[$column] = trim((string) $data[$column]);
            }
        }
        foreach (['gender', 'birthdate'] as $column) {
            if (array_key_exists($column, $data)) {
                $row[$column] = $data[$column] === '' ? null : $data[$column];
            }
        }
        if (array_key_exists('department_id', $data)) {
            $row['department_id'] = $data['department_id'] === '' ? null : (int) $data['department_id'];
        }
        if ($row === []) {
            return;
        }
        $row['updated_at'] = Helpers::now();
        Database::update('students', $row, 'id = :id', ['id' => $id]);
        Audit::log('STUDENT_UPDATED', 'students', 'Updated student #' . $id, $id);
    }

    /** Activates, deactivates or archives a student record. */
    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'inactive', 'archived'], true)) {
            throw new InvalidArgumentException('Unknown student status.');
        }
        Database::update('students', ['status' => $status, 'updated_at' => Helpers::now()], 'id = :id', ['id' => $id]);
        Database::run(
            "UPDATE users SET status = :s, updated_at = :t WHERE id = (SELECT user_id FROM students WHERE id = :id)",
            ['s' => $status === 'archived' ? 'inactive' : $status, 't' => Helpers::now(), 'id' => $id]
        );
        Audit::log('STUDENT_' . strtoupper($status), 'students', 'Student status set to ' . $status, $id);
    }

    /** @return array<string,int> */
    public static function counts(): array
    {
        return [
            'total'        => Database::count('students'),
            'active'       => Database::count('students', 'status = :s', ['s' => 'active']),
            'with_account' => (int) Database::scalar('SELECT COUNT(*) FROM students WHERE user_id IS NOT NULL'),
            'archived'     => Database::count('students', 'status = :s', ['s' => 'archived']),
        ];
    }

    /** @return array<string,int> department name => student count */
    public static function byDepartment(): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT COALESCE(d.name, 'Unassigned') AS label, COUNT(*) AS c
               FROM students s LEFT JOIN departments d ON d.id = s.department_id
              GROUP BY label ORDER BY c DESC"
        ) as $row) {
            $out[(string) $row['label']] = (int) $row['c'];
        }
        return $out;
    }

    /** @return array<int,string> */
    public static function courseOptions(): array
    {
        $out = [];
        foreach (Database::all("SELECT DISTINCT course FROM students WHERE course <> '' ORDER BY course") as $row) {
            $out[(string) $row['course']] = (string) $row['course'];
        }
        return $out;
    }

    /** @return array<int,string> */
    public static function yearLevelOptions(): array
    {
        $out = [];
        foreach (Database::all("SELECT DISTINCT year_level FROM students WHERE year_level <> '' ORDER BY year_level") as $row) {
            $out[(string) $row['year_level']] = (string) $row['year_level'];
        }
        return $out;
    }

    /** Makes sure a username is free. */
    public static function uniqueUsername(string $wanted, string $fallback = 'student'): string
    {
        $base = strtolower(substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', $wanted), 0, 50)) ?: 'student';
        $name = $base;
        $n    = 1;
        while (Database::count('users', 'username = :u', ['u' => $name]) > 0) {
            $name = $base . (++$n);
        }
        return $name;
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
                         OR CONCAT(s.first_name, " ", s.last_name) LIKE :q4 OR s.email LIKE :q5)';
            foreach (['q', 'q2', 'q3', 'q4', 'q5'] as $key) {
                $params[$key] = '%' . $filters['q'] . '%';
            }
        }
        foreach (['department_id' => 's.department_id', 'course' => 's.course',
                  'year_level' => 's.year_level', 'status' => 's.status'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]             = $column . ' = :f_' . $key;
                $params['f_' . $key] = $filters[$key];
            }
        }

        return [implode(' AND ', $where), $params];
    }
}
