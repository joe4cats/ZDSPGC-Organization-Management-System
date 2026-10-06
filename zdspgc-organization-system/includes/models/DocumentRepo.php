<?php
/**
 * models/DocumentRepo.php — organization documents: upload, versioning,
 * verification (approve / reject / revision) and archive.
 */

declare(strict_types=1);

final class DocumentRepo
{
    private const SELECT = 'SELECT d.*, o.name AS organization_name, o.acronym,
            uu.full_name AS uploaded_by_name, vu.full_name AS verified_by_name
            FROM documents d
            LEFT JOIN organizations o ON o.id = d.organization_id
            LEFT JOIN users uu ON uu.id = d.uploaded_by
            LEFT JOIN users vu ON vu.id = d.verified_by';

    /**
     * @param array<string,mixed> $filters q, organization_id, document_type, status, audience
     * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int}
     */
    public static function list(array $filters, int $page = 1, int $perPage = 20): array
    {
        [$whereSql, $params] = self::where($filters);
        $where = ' WHERE ' . $whereSql;
        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM documents d LEFT JOIN organizations o ON o.id = d.organization_id' . $where,
            $params
        );
        $page = max(1, $page);

        return [
            'rows'  => Database::all(
                self::SELECT . ' ' . $where
                . ' ORDER BY FIELD(d.status, "pending","revision_required","approved","rejected","archived"),
                    d.uploaded_at DESC'
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
        return Database::one(self::SELECT . ' WHERE d.id = :id', ['id' => $id]);
    }

    /**
     * Documents of one organization (newest first).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forOrganization(int $organizationId, int $limit = 100): array
    {
        return Database::all(
            self::SELECT . " WHERE d.organization_id = :o AND d.status <> 'archived'
                             ORDER BY d.uploaded_at DESC LIMIT " . (int) $limit,
            ['o' => $organizationId]
        );
    }

    /**
     * Records an uploaded file. The caller must have already stored the bytes
     * with Uploads::document() and passes its result fields.
     *
     * @param array<string,mixed> $data organization_id, document_type, title, file (Uploads::document result)
     */
    public static function create(array $data): int
    {
        $file = $data['file'] ?? [];
        if (empty($file['ok'])) {
            throw new RuntimeException((string) ($file['message'] ?? 'The file could not be uploaded.'));
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = (string) ($file['original'] ?? 'Untitled document');
        }

        $organizationId = !empty($data['organization_id']) ? (int) $data['organization_id'] : null;
        $existing = $organizationId === null ? null : Database::one(
            'SELECT id FROM documents WHERE organization_id = :o AND document_type = :t AND status <> "archived"',
            ['o' => $organizationId, 't' => (string) ($data['document_type'] ?? '')]
        );

        $id = Database::insert('documents', [
            'organization_id' => $organizationId,
            'document_type'   => mb_substr((string) ($data['document_type'] ?? 'general'), 0, 80),
            'title'           => mb_substr($title, 0, 180),
            'file_name'       => mb_substr((string) ($file['original'] ?? ''), 0, 200),
            'file_path'       => (string) $file['path'],
            'file_size'       => (int) ($file['size'] ?? 0),
            'mime_type'       => (string) ($file['mime'] ?? ''),
            'version'         => $existing === null ? 1 : 2,
            'parent_id'       => $existing === null ? null : (int) $existing['id'],
            'status'          => 'pending',
            'remarks'         => '',
            'is_public'       => !empty($data['is_public']) ? 1 : 0,
            'uploaded_by'     => Auth::id(),
            'uploaded_at'     => Helpers::now(),
        ]);

        Audit::log('DOCUMENT_UPLOADED', 'documents', 'Uploaded "' . $title . '" (' . (string) $file['original'] . ')', $id);

        if ($organizationId !== null) {
            Notifications::pushMany(
                Notifications::userIdsForOrganization($organizationId, true),
                'Document uploaded',
                '"' . $title . '" was uploaded and is waiting for verification.',
                'info',
                $id,
                'document',
                'organization/documents.php'
            );
            Notifications::notifyOrganizationAdviser(
                $organizationId,
                'Document to verify',
                '"' . $title . '" was uploaded and needs verification.',
                'info',
                $id,
                'document',
                'adviser/documents.php'
            );
        }

        return $id;
    }

    /** Approves, rejects or requests a revision on a document. */
    public static function verify(int $id, string $status, string $remarks = ''): void
    {
        if (!in_array($status, ['approved', 'rejected', 'revision_required'], true)) {
            throw new InvalidArgumentException('Unknown verification decision.');
        }
        $doc = self::find($id);
        if ($doc === null) {
            throw new RuntimeException('Document not found.');
        }

        Database::update('documents', [
            'status'      => $status,
            'remarks'     => mb_substr($remarks, 0, 400),
            'verified_by' => Auth::id(),
            'verified_at' => Helpers::now(),
        ], 'id = :id', ['id' => $id]);

        Audit::log('DOCUMENT_' . strtoupper($status), 'documents',
            'Document "' . $doc['title'] . '" ' . $status . ($remarks !== '' ? ': ' . $remarks : ''), $id);

        if (!empty($doc['organization_id'])) {
            Notifications::pushMany(
                Notifications::userIdsForOrganization((int) $doc['organization_id'], true),
                'Document ' . ui_status($status),
                '"' . $doc['title'] . '" was ' . $status . '.' . ($remarks !== '' ? ' ' . $remarks : ''),
                $status === 'approved' ? 'approval' : 'rejection',
                $id,
                'document',
                'organization/documents.php'
            );
        }
    }

    public static function archive(int $id): void
    {
        $doc = self::find($id);
        if ($doc === null) {
            throw new RuntimeException('Document not found.');
        }
        Database::update('documents', [
            'status'      => 'archived',
            'archived_at' => Helpers::now(),
            'updated_at'  => Helpers::now(),
        ], 'id = :id', ['id' => $id]);
        Audit::log('DOCUMENT_ARCHIVED', 'documents', 'Archived "' . $doc['title'] . '"', $id);
    }

    /** @return array<string,int> */
    public static function statusCounts(?int $organizationId = null): array
    {
        $counts = [];
        foreach (AcademicRepo::DOCUMENT_STATUSES as $status) {
            $counts[$status] = $organizationId === null
                ? Database::count('documents', 'status = :s', ['s' => $status])
                : Database::count('documents', 'status = :s AND organization_id = :o', ['s' => $status, 'o' => $organizationId]);
        }
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * True when this user may open the file behind a document row.
     * Administrators, the uploader, the officers of the owning organization and
     * its adviser may read it; anything marked is_public is readable by anyone
     * signed in.
     */
    public static function mayRead(array $doc): bool
    {
        if (!Auth::check()) {
            return false;
        }
        if ((int) ($doc['is_public'] ?? 0) === 1) {
            return true;
        }
        $role = Auth::role();
        if ($role === 'admin') {
            return true;
        }
        if (empty($doc['organization_id'])) {
            return false;
        }
        if ((int) ($doc['uploaded_by'] ?? 0) === (int) Auth::id()) {
            return true;
        }
        return Permissions::canManageOrganization((int) $doc['organization_id'])
            || Permissions::isAdviserOf((int) $doc['organization_id']);
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
            $where[] = '(d.title LIKE :q OR d.file_name LIKE :q2 OR d.document_type LIKE :q3 OR o.name LIKE :q4)';
            $params['q']  = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
            $params['q4'] = '%' . $filters['q'] . '%';
        }
        foreach (['organization_id' => 'd.organization_id', 'document_type' => 'd.document_type',
                  'status' => 'd.status', 'uploaded_by' => 'd.uploaded_by'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]             = $column . ' = :f_' . $key;
                $params['f_' . $key] = $filters[$key];
            }
        }

        return [implode(' AND ', $where), $params];
    }
}
