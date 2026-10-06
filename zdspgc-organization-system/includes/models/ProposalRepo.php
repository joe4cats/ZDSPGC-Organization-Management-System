<?php
/**
 * models/ProposalRepo.php — activity/project proposals: submission, adviser
 * endorsement, administrator decision and the reviewer comment history.
 */

declare(strict_types=1);

final class ProposalRepo
{
    private const SELECT = 'SELECT pr.*, p.title AS project_title, p.budget AS project_budget,
            p.start_date AS project_start, p.end_date AS project_end,
            o.name AS organization_name, o.acronym, su.full_name AS submitted_by_name,
            ru.full_name AS reviewer_name, ay.name AS academic_year_name
            FROM proposals pr
            JOIN projects p ON p.id = pr.project_id
            JOIN organizations o ON o.id = pr.organization_id
            LEFT JOIN academic_years ay ON ay.id = p.academic_year_id
            LEFT JOIN users su ON su.id = pr.submitted_by
            LEFT JOIN users ru ON ru.id = pr.reviewed_by';

    /**
     * @param array<string,mixed> $filters q, organization_id, status, project_id, academic_year_id
     * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int}
     */
    public static function list(array $filters, int $page = 1, int $perPage = 20): array
    {
        [$whereSql, $params] = self::where($filters);
        $where = ' WHERE ' . $whereSql;
        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM proposals pr JOIN projects p ON p.id = pr.project_id
             JOIN organizations o ON o.id = pr.organization_id' . $where,
            $params
        );
        $page = max(1, $page);

        return [
            'rows'  => Database::all(
                self::SELECT . ' ' . $where
                . ' ORDER BY FIELD(pr.status, "submitted","under_review","revision_required","approved","rejected","implemented","reported","draft"),
                    pr.submitted_at DESC, pr.id DESC'
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
        return Database::one(self::SELECT . ' WHERE pr.id = :id', ['id' => $id]);
    }

    /**
     * Creates the proposal row for a project (status draft).
     *
     * @param array<string,mixed> $data project_id, organization_id, title
     */
    public static function create(array $data): int
    {
        $projectId = (int) ($data['project_id'] ?? 0);
        $project   = $projectId > 0 ? ProjectRepo::find($projectId) : null;
        if ($project === null) {
            throw new RuntimeException('The project this proposal belongs to was not found.');
        }

        $id = Database::insert('proposals', [
            'project_id'       => $projectId,
            'organization_id'  => (int) ($data['organization_id'] ?? $project['organization_id']),
            'title'            => mb_substr(trim((string) ($data['title'] ?? $project['title'])), 0, 180),
            'submitted_by'     => Auth::id(),
            'status'           => 'draft',
            'reviewer_comments'=> '',
            'current_version'  => 1,
            'created_at'       => Helpers::now(),
        ]);

        Audit::log('PROPOSAL_CREATED', 'proposals', 'Created proposal #' . $id, $id);

        return $id;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $proposal = self::find($id);
        if ($proposal === null) {
            throw new RuntimeException('Proposal not found.');
        }

        $row = ['updated_at' => Helpers::now()];
        if (array_key_exists('title', $data)) {
            $row['title'] = mb_substr(trim((string) $data['title']), 0, 180);
            if ($row['title'] === '') {
                throw new RuntimeException('The proposal needs a title.');
            }
        }

        Database::update('proposals', $row, 'id = :id', ['id' => $id]);
        Audit::log('PROPOSAL_UPDATED', 'proposals', 'Updated proposal #' . $id, $id);
    }

    /** Moves a draft / revision-required proposal into the review queue. */
    public static function submit(int $id): void
    {
        $proposal = self::find($id);
        if ($proposal === null) {
            throw new RuntimeException('Proposal not found.');
        }
        if (!in_array((string) $proposal['status'], ['draft', 'revision_required'], true)) {
            throw new RuntimeException('Only a draft or a revision can be submitted.');
        }

        $isRevision = (string) $proposal['status'] === 'revision_required';
        Database::update('proposals', [
            'status'         => 'submitted',
            'submitted_by'   => Auth::id(),
            'submitted_at'   => Helpers::now(),
            'current_version' => (int) $proposal['current_version'] + ($isRevision ? 1 : 0),
            'updated_at'     => Helpers::now(),
        ], 'id = :id', ['id' => $id]);

        Audit::log('PROPOSAL_SUBMITTED', 'proposals', 'Submitted proposal "' . $proposal['title'] . '"', $id);
        self::addComment($id, 'submit', $isRevision ? 'Resubmitted after revision.' : 'Submitted for review.');

        Notifications::pushMany(
            Notifications::userIdsForOrganization((int) $proposal['organization_id'], true),
            'Proposal submitted',
            'The proposal "' . $proposal['title'] . '" is now waiting for review.',
            'info',
            $id,
            'proposal',
            'organization/proposals.php'
        );
        if (!empty($proposal['organization_id'])) {
            Notifications::notifyOrganizationAdviser(
                (int) $proposal['organization_id'],
                'New proposal to review',
                '"' . $proposal['title'] . '" was submitted by ' . (string) $proposal['organization_name'] . '.',
                'info',
                $id,
                'proposal',
                'adviser/proposals.php'
            );
        }
    }

    /**
     * Records a decision on a proposal.
     *
     * $decision: under_review (adviser endorsement) · approved · rejected ·
     * revision_required · implemented · reported
     */
    public static function decide(int $id, string $decision, string $comments = ''): void
    {
        if (!in_array($decision, AcademicRepo::PROPOSAL_STATUSES, true)) {
            throw new InvalidArgumentException('Unknown proposal decision.');
        }
        $proposal = self::find($id);
        if ($proposal === null) {
            throw new RuntimeException('Proposal not found.');
        }
        if (!in_array((string) $proposal['status'], ['submitted', 'under_review'], true)
            && !in_array($decision, ['implemented', 'reported'], true)) {
            throw new RuntimeException('This proposal is not waiting for a decision.');
        }

        $row = [
            'status'            => $decision,
            'reviewer_comments' => mb_substr($comments, 0, 600),
            'updated_at'        => Helpers::now(),
        ];
        if (in_array($decision, ['under_review', 'approved', 'rejected', 'revision_required'], true)) {
            $row['reviewed_by'] = Auth::id();
            $row['reviewed_at'] = Helpers::now();
        }
        Database::update('proposals', $row, 'id = :id', ['id' => $id]);

        Audit::log('PROPOSAL_' . strtoupper($decision), 'proposals',
            'Proposal "' . $proposal['title'] . '" — ' . $decision
            . ($comments !== '' ? ': ' . $comments : ''), $id);
        self::addComment($id, $decision, $comments);

        $messages = [
            'approved'         => 'Your proposal "' . $proposal['title'] . '" was approved.',
            'rejected'         => 'Your proposal "' . $proposal['title'] . '" was rejected. ' . $comments,
            'revision_required'=> 'Revision requested on "' . $proposal['title'] . '". ' . $comments,
            'under_review'     => 'The adviser endorsed "' . $proposal['title'] . '" for administrator review.',
            'implemented'      => '"' . $proposal['title'] . '" was marked as implemented.',
            'reported'         => 'The post-activity report for "' . $proposal['title'] . '" was filed.',
        ];
        Notifications::pushMany(
            Notifications::userIdsForOrganization((int) $proposal['organization_id'], true),
            'Proposal ' . ui_status($decision),
            $messages[$decision] ?? ('Your proposal is now ' . $decision . '.'),
            $decision === 'approved' ? 'approval'
                : (in_array($decision, ['rejected', 'revision_required'], true) ? 'rejection' : 'info'),
            $id,
            'proposal',
            'organization/proposals.php'
        );
    }

    /** Appends a reviewer comment without changing the status. */
    public static function addComment(int $proposalId, string $decision, string $comments): int
    {
        return Database::insert('proposal_comments', [
            'proposal_id' => $proposalId,
            'author_id'   => Auth::id(),
            'author_role' => (string) Auth::role(),
            'decision'    => mb_substr($decision, 0, 30),
            'comments'    => mb_substr($comments, 0, 4000),
            'created_at'  => Helpers::now(),
        ]);
    }

    /**
     * Full comment history (newest first) with the author's display name.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function comments(int $proposalId): array
    {
        return Database::all(
            'SELECT pc.*, u.full_name AS author_name
               FROM proposal_comments pc
               LEFT JOIN users u ON u.id = pc.author_id
              WHERE pc.proposal_id = :p
              ORDER BY pc.created_at DESC, pc.id DESC',
            ['p' => $proposalId]
        );
    }

    /** @return array<string,int> */
    public static function statusCounts(?int $organizationId = null): array
    {
        $counts = [];
        foreach (AcademicRepo::PROPOSAL_STATUSES as $status) {
            $counts[$status] = $organizationId === null
                ? Database::count('proposals', 'status = :s', ['s' => $status])
                : Database::count('proposals', 'status = :s AND organization_id = :o', ['s' => $status, 'o' => $organizationId]);
        }
        $counts['total'] = array_sum($counts);

        return $counts;
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
            $where[] = '(pr.title LIKE :q OR p.title LIKE :q2 OR o.name LIKE :q3)';
            $params['q']  = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
        }
        foreach (['organization_id' => 'pr.organization_id', 'status' => 'pr.status',
                  'project_id' => 'pr.project_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]             = $column . ' = :f_' . $key;
                $params['f_' . $key] = $filters[$key];
            }
        }

        return [implode(' AND ', $where), $params];
    }
}
