<?php
/**
 * models/AcademicRepo.php — reference data: academic years, categories,
 * departments, officer positions, required document types and system settings.
 * These small tables are used by almost every page, so they live together.
 */

declare(strict_types=1);

final class AcademicRepo
{
    /* -------------------------------------------------------- academic years */

    /** @return array<int,array<string,mixed>> */
    public static function years(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM academic_years';
        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }
        return Database::all($sql . ' ORDER BY start_date DESC');
    }

    public static function year(int $id): ?array
    {
        return Database::one('SELECT * FROM academic_years WHERE id = :id', ['id' => $id]);
    }

    /** The academic year that new records are attached to. */
    public static function activeYear(): ?array
    {
        return Database::one("SELECT * FROM academic_years WHERE status = 'active' ORDER BY start_date DESC LIMIT 1")
            ?? Database::one('SELECT * FROM academic_years ORDER BY start_date DESC LIMIT 1');
    }

    public static function activeYearId(): int
    {
        $year = self::activeYear();
        return $year === null ? 0 : (int) $year['id'];
    }

    /** @return array<int,string> id => "2026–2027" */
    public static function yearOptions(bool $activeOnly = false): array
    {
        $out = [];
        foreach (self::years($activeOnly) as $year) {
            $out[(int) $year['id']] = (string) $year['name'];
        }
        return $out;
    }

    /* ---------------------------------------------------------- departments */

    /** @return array<int,array<string,mixed>> */
    public static function departments(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM departments';
        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }
        return Database::all($sql . ' ORDER BY name ASC');
    }

    /** @return array<int,string> */
    public static function departmentOptions(): array
    {
        $out = [];
        foreach (self::departments() as $row) {
            $out[(int) $row['id']] = (string) $row['name'];
        }
        return $out;
    }

    /* ------------------------------------------------------------ categories */

    /** @return array<int,array<string,mixed>> */
    public static function categories(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM organization_categories';
        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }
        return Database::all($sql . ' ORDER BY name ASC');
    }

    /** @return array<int,string> */
    public static function categoryOptions(): array
    {
        $out = [];
        foreach (self::categories() as $row) {
            $out[(int) $row['id']] = (string) $row['name'];
        }
        return $out;
    }


    public const ORGANIZATION_TYPES = [
        'Academic', 'Cultural', 'Sports', 'Religious', 'Community Service',
        'Special Interest', 'Departmental', 'Student Government', 'Student Council', 'Other',
    ];

    public const EVENT_TYPES = [
        'Seminar', 'Workshop', 'Meeting', 'Sports', 'Community Outreach',
        'Training', 'Competition', 'General Assembly', 'Orientation', 'Other',
    ];

    public const ORGANIZATION_STATUSES = ['pending', 'active', 'suspended', 'expired', 'archived', 'rejected'];
    public const ACCREDITATION_STATUSES = ['not_applied', 'pending', 'under_review', 'approved', 'revision_required', 'rejected', 'expired'];
    public const MEMBER_STATUSES       = ['pending', 'active', 'rejected', 'suspended', 'inactive', 'archived'];
    public const EVENT_STATUSES        = ['draft', 'pending_adviser', 'pending_admin', 'approved', 'rejected', 'ongoing', 'completed', 'cancelled'];
    public const PROPOSAL_STATUSES     = ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'revision_required', 'implemented', 'reported'];
    public const PROJECT_STATUSES      = ['proposed', 'approved', 'ongoing', 'completed', 'cancelled'];
    public const DOCUMENT_STATUSES     = ['pending', 'approved', 'rejected', 'revision_required', 'archived'];
    public const ATTENDANCE_STATUSES   = ['present', 'late', 'excused', 'absent'];
    public const AUDIENCES            = ['all', 'organization', 'department', 'officers', 'members'];

    /* ------------------------------------------------------------- positions */

    /** @return array<int,array<string,mixed>> */
    public static function positions(bool $officersOnly = false): array
    {
        $sql = "SELECT * FROM officer_positions WHERE status = 'active'";
        if ($officersOnly) {
            $sql .= ' AND is_officer = 1';
        }
        return Database::all($sql . ' ORDER BY sort_order ASC, name ASC');
    }

    /** @return array<int,string> */
    public static function positionOptions(bool $officersOnly = false): array
    {
        $out = [];
        foreach (self::positions($officersOnly) as $row) {
            $out[(int) $row['id']] = (string) $row['name'];
        }
        return $out;
    }

    /* ------------------------------------------------------- document types */

    /** @return array<int,array<string,mixed>> */
    public static function documentTypes(string $appliesTo = 'registration'): array
    {
        return Database::all(
            "SELECT * FROM required_document_types WHERE status = 'active' AND applies_to = :a ORDER BY sort_order ASC",
            ['a' => $appliesTo]
        );
    }

    /** @return array<int,string> name => "Name (required)" */
    public static function documentTypeNames(string $appliesTo = 'registration'): array
    {
        $out = [];
        foreach (self::documentTypes($appliesTo) as $row) {
            $out[(string) $row['name']] = (string) $row['name'] . ((int) $row['is_required'] === 1 ? ' (required)' : ' (optional)');
        }
        return $out;
    }

    /** Required documents an organization still has to upload. @return array<int,string> */
    public static function missingDocuments(int $organizationId, string $appliesTo = 'registration'): array
    {
        $required = Database::all(
            "SELECT name FROM required_document_types
              WHERE status = 'active' AND applies_to = :a AND is_required = 1",
            ['a' => $appliesTo]
        );
        if ($required === []) {
            return [];
        }
        $have = array_map(
            static fn ($r) => (string) $r['document_type'],
            Database::all(
                "SELECT DISTINCT document_type FROM documents WHERE organization_id = :o AND status <> 'archived'",
                ['o' => $organizationId]
            )
        );

        $missing = [];
        foreach ($required as $row) {
            if (!in_array((string) $row['name'], $have, true)) {
                $missing[] = (string) $row['name'];
            }
        }
        return $missing;
    }

    /* -------------------------------------------------------------- settings */

    /** Every document type (including inactive) for the settings screens. */
    /** @return array<int,array<string,mixed>> */
    public static function allDocumentTypes(): array
    {
        return Database::all('SELECT * FROM required_document_types ORDER BY applies_to ASC, sort_order ASC, name ASC');
    }

    /* --------------------------------------------- reference data: writers */

    /**
     * @param array<string,mixed> $data name, start_date, end_date, status
     */
    public static function createYear(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('The academic year needs a name (for example 2026-2027).');
        }
        if (Database::count('academic_years', 'name = :n', ['n' => $name]) > 0) {
            throw new RuntimeException('That academic year already exists.');
        }
        $id = Database::insert('academic_years', [
            'name'       => mb_substr($name, 0, 30),
            'start_date' => (string) ($data['start_date'] ?? ''),
            'end_date'   => (string) ($data['end_date'] ?? ''),
            'status'     => in_array((string) ($data['status'] ?? 'upcoming'), ['upcoming', 'active', 'closed', 'archived'], true)
                ? (string) $data['status'] : 'upcoming',
            'created_at' => Helpers::now(),
        ]);
        self::activateYearIfAsked($id, (string) ($data['status'] ?? 'upcoming'));
        Audit::log('ACADEMIC_YEAR_CREATED', 'academic', 'Created academic year ' . $name, $id);

        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function updateYear(int $id, array $data): void
    {
        $year = self::year($id);
        if ($year === null) {
            throw new RuntimeException('Academic year not found.');
        }
        $row = [];
        if (array_key_exists('name', $data) && trim((string) $data['name']) !== '') {
            $row['name'] = mb_substr(trim((string) $data['name']), 0, 30);
        }
        foreach (['start_date', 'end_date'] as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = (string) $data[$key];
            }
        }
        if (array_key_exists('status', $data)) {
            $status = (string) $data['status'];
            if (!in_array($status, ['upcoming', 'active', 'closed', 'archived'], true)) {
                throw new InvalidArgumentException('Unknown academic year status.');
            }
            $row['status'] = $status;
        }
        Database::update('academic_years', $row, 'id = :id', ['id' => $id]);
        if (isset($row['status'])) {
            self::activateYearIfAsked($id, (string) $row['status']);
        }
        Audit::log('ACADEMIC_YEAR_UPDATED', 'academic', 'Updated academic year #' . $id, $id);
    }

    /** Keeps at most one academic year in the "active" state. */
    private static function activateYearIfAsked(int $id, string $status): void
    {
        if ($status !== 'active') {
            return;
        }
        Database::run("UPDATE academic_years SET status = 'closed' WHERE id <> :id AND status = 'active'", ['id' => $id]);
    }

    /** @param array<string,mixed> $data name, description, status */
    public static function createCategory(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('The category needs a name.');
        }
        if (Database::count('organization_categories', 'name = :n', ['n' => $name]) > 0) {
            throw new RuntimeException('That category already exists.');
        }
        $id = Database::insert('organization_categories', [
            'name'        => mb_substr($name, 0, 80),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 255),
            'status'      => (string) ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'created_at'  => Helpers::now(),
        ]);
        Audit::log('CATEGORY_CREATED', 'academic', 'Created category ' . $name, $id);

        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function updateCategory(int $id, array $data): void
    {
        $row = ['status' => (string) ($data['status'] ?? '') === 'inactive' ? 'inactive' : 'active'];
        if (array_key_exists('name', $data) && trim((string) $data['name']) !== '') {
            $row['name'] = mb_substr(trim((string) $data['name']), 0, 80);
        }
        if (array_key_exists('description', $data)) {
            $row['description'] = mb_substr(trim((string) $data['description']), 0, 255);
        }
        Database::update('organization_categories', $row, 'id = :id', ['id' => $id]);
        Audit::log('CATEGORY_UPDATED', 'academic', 'Updated category #' . $id, $id);
    }

    /** @param array<string,mixed> $data code, name, head_name, status */
    public static function createDepartment(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($name === '' || $code === '') {
            throw new RuntimeException('A department needs a code and a name.');
        }
        if (Database::count('departments', 'code = :c OR name = :n', ['c' => $code, 'n' => $name]) > 0) {
            throw new RuntimeException('A department with that code or name already exists.');
        }
        $id = Database::insert('departments', [
            'code'      => mb_substr($code, 0, 30),
            'name'      => mb_substr($name, 0, 160),
            'head_name' => mb_substr(trim((string) ($data['head_name'] ?? '')), 0, 160),
            'status'    => (string) ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'created_at'=> Helpers::now(),
        ]);
        Audit::log('DEPARTMENT_CREATED', 'academic', 'Created department ' . $name, $id);

        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function updateDepartment(int $id, array $data): void
    {
        $row = ['status' => (string) ($data['status'] ?? '') === 'inactive' ? 'inactive' : 'active'];
        if (array_key_exists('code', $data) && trim((string) $data['code']) !== '') {
            $row['code'] = mb_substr(strtoupper(trim((string) $data['code'])), 0, 30);
        }
        if (array_key_exists('name', $data) && trim((string) $data['name']) !== '') {
            $row['name'] = mb_substr(trim((string) $data['name']), 0, 160);
        }
        if (array_key_exists('head_name', $data)) {
            $row['head_name'] = mb_substr(trim((string) $data['head_name']), 0, 160);
        }
        Database::update('departments', $row, 'id = :id', ['id' => $id]);
        Audit::log('DEPARTMENT_UPDATED', 'academic', 'Updated department #' . $id, $id);
    }

    /**
     * @param array<string,mixed> $data name, applies_to, is_required, sort_order, status
     */
    public static function createDocumentType(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('The document type needs a name.');
        }
        $appliesTo = (string) ($data['applies_to'] ?? 'general');
        if (!in_array($appliesTo, ['registration', 'accreditation', 'event', 'general'], true)) {
            throw new InvalidArgumentException('Unknown document type scope.');
        }
        $id = Database::insert('required_document_types', [
            'name'        => mb_substr($name, 0, 80),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 255),
            'applies_to'  => $appliesTo,
            'is_required' => !empty($data['is_required']) ? 1 : 0,
            'sort_order'  => (int) ($data['sort_order'] ?? 100),
            'status'      => (string) ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        ]);
        Audit::log('DOCUMENT_TYPE_CREATED', 'academic', 'Created required document type ' . $name, $id);

        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function updateDocumentType(int $id, array $data): void
    {
        $row = [];
        if (array_key_exists('name', $data) && trim((string) $data['name']) !== '') {
            $row['name'] = mb_substr(trim((string) $data['name']), 0, 80);
        }
        if (array_key_exists('is_required', $data)) {
            $row['is_required'] = !empty($data['is_required']) ? 1 : 0;
        }
        if (array_key_exists('sort_order', $data)) {
            $row['sort_order'] = (int) $data['sort_order'];
        }
        if (array_key_exists('status', $data)) {
            $row['status'] = (string) $data['status'] === 'inactive' ? 'inactive' : 'active';
        }
        if (array_key_exists('applies_to', $data)
            && in_array((string) $data['applies_to'], ['registration', 'accreditation', 'event', 'general'], true)) {
            $row['applies_to'] = (string) $data['applies_to'];
        }
        Database::update('required_document_types', $row, 'id = :id', ['id' => $id]);
        Audit::log('DOCUMENT_TYPE_UPDATED', 'academic', 'Updated document type #' . $id, $id);
    }

    /* -------------------------------------------------------------- settings */

    /** @return array<string,string> */
    public static function settings(): array
    {
        $out = [];
        foreach (Database::all('SELECT setting_key, setting_value FROM settings') as $row) {
            $out[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return $out;
    }

    public static function setting(string $key, string $default = ''): string
    {
        $value = Database::value('SELECT setting_value FROM settings WHERE setting_key = :k', ['k' => $key]);
        return $value === null ? $default : (string) $value;
    }

    public static function putSetting(string $key, string $value, string $group = 'general', string $label = ''): void
    {
        Database::upsert('settings', [
            'setting_key'   => $key,
            'setting_value' => $value,
            'setting_group' => $group,
            'label'         => $label !== '' ? $label : ucwords(str_replace('_', ' ', $key)),
            'updated_at'    => Helpers::now(),
        ], ['setting_key']);
    }
}
