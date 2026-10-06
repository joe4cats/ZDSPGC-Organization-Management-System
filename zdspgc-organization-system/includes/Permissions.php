<?php
/**
 * Permissions.php — centralised Role-Based Access Control.
 *
 * Every protected page and API endpoint asks this class:
 *   1. Is the user signed in?                          (via Auth)
 *   2. Does the role hold the capability?              Permissions::can()
 *   3. Does the user own / advise this organisation?
 *      Permissions::canManageOrganization()
 *
 * Roles: admin | adviser | officer | student
 */

declare(strict_types=1);

final class Permissions
{
    public const ROLE_LABELS = [
        'admin'   => 'System Administrator',
        'adviser' => 'Organization Adviser',
        'officer' => 'Organization Officer',
        'student' => 'Student / Member',
    ];

    /** Capabilities granted per role. */
    private const CAPS = [
        'admin' => [
            'manage_users', 'manage_organizations', 'approve_organizations', 'archive_records',
            'manage_categories', 'manage_academic_years', 'manage_departments',
            'manage_students', 'manage_advisers', 'manage_members', 'manage_officers',
            'manage_events', 'approve_events', 'manage_projects', 'approve_proposals',
            'manage_documents', 'review_documents', 'verify_accreditation',
            'manage_announcements', 'post_announcements', 'manage_settings',
            'view_all_events', 'view_all_attendance', 'record_attendance', 'generate_qr',
            'view_reports', 'view_logs', 'view_organizations',
        ],
        'adviser' => [
            'view_organizations', 'review_officers', 'review_members', 'review_events',
            'review_proposals', 'review_documents', 'recommend_activities',
            'post_announcements', 'view_attendance', 'view_reports', 'generate_qr',
            'import_students',
        ],
        'officer' => [
            'view_organizations', 'edit_organization', 'manage_membership_applications',
            'manage_members', 'manage_officers', 'manage_events', 'submit_events',
            'submit_proposals', 'manage_projects', 'manage_documents', 'record_attendance',
            'generate_qr', 'post_announcements', 'view_attendance', 'org_reports',
        ],
        'student' => [
            'view_organizations', 'apply_membership', 'register_events', 'scan_attendance',
            'view_own_attendance', 'view_announcements',
        ],
    ];

    /* ---------------------------------------------------------------------
     * Capability checks
     * ------------------------------------------------------------------ */

    public static function can(string $capability, ?string $role = null): bool
    {
        $role ??= Auth::role();
        if ($role === null) {
            return false;
        }
        if (in_array($capability, ['view_organizations', 'view_announcements'], true)) {
            return true;   // available to every signed-in role
        }
        return in_array($capability, self::CAPS[$role] ?? [], true);
    }

    /** Web guard: flash + redirect when the capability is missing. */
    public static function requireCapability(string $capability): void
    {
        Auth::requireLogin();
        if (self::can($capability)) {
            return;
        }
        Security::audit('ACCESS_DENIED', 'authz', 'Blocked capability ' . $capability . ' on ' . Helpers::currentPage());
        Helpers::flash('error', 'Your role (' . Auth::roleLabel() . ') is not allowed to open that page.');
        Helpers::redirect(self::homeForRole());
    }

    /** API guard: 401/403 JSON when the capability is missing. */
    public static function apiRequire(string $capability): void
    {
        if (!Auth::check()) {
            Helpers::jsonFail('Please sign in to continue.', 401, 'unauthenticated');
        }
        if (!self::can($capability)) {
            Security::audit('ACCESS_DENIED', 'authz', 'API blocked for capability ' . $capability);
            Helpers::jsonFail('You do not have permission to perform this action.', 403, 'forbidden');
        }
    }

    public static function homeForRole(?string $role = null): string
    {
        return match ($role ?? Auth::role()) {
            'admin'   => 'admin/dashboard.php',
            'adviser' => 'adviser/dashboard.php',
            'officer' => 'organization/dashboard.php',
            'student' => 'student/dashboard.php',
            default   => 'index.php',
        };
    }

    /* ---------------------------------------------------------------------
     * Scope: which students / organizations does this user own?
     * ------------------------------------------------------------------ */

    public static function studentId(): int
    {
        $profile = Auth::studentProfile();
        return $profile === null ? 0 : (int) $profile['id'];
    }

    public static function adviserId(): int
    {
        return (int) Database::value('SELECT id FROM advisers WHERE user_id = :u', ['u' => Auth::id()], 0);
    }

    /**
     * Organizations the current user may act on.
     * admin = every organization, adviser = assigned, officer = active membership
     * that holds an officer position.
     *
     * @return array<int,int>
     */
    public static function managedOrganizationIds(): array
    {
        $role = Auth::role();
        if ($role === 'admin') {
            return array_map('intval', array_column(Database::all('SELECT id FROM organizations'), 'id'));
        }
        if ($role === 'adviser') {
            $rows = Database::all('SELECT id FROM organizations WHERE adviser_id = :a', ['a' => self::adviserId()]);
            return array_map(static fn ($r) => (int) $r['id'], $rows);
        }
        if ($role === 'officer') {
            $rows = Database::all(
                "SELECT organization_id FROM organization_members
                  WHERE student_id = :s AND status = 'active' AND position_id IS NOT NULL",
                ['s' => self::studentId()]
            );
            return array_map(static fn ($r) => (int) $r['organization_id'], $rows);
        }
        return [];
    }

    public static function isOfficerOf(int $organizationId): bool
    {
        return Auth::role() === 'officer' && in_array($organizationId, self::managedOrganizationIds(), true);
    }

    public static function isAdviserOf(int $organizationId): bool
    {
        return Auth::role() === 'adviser' && in_array($organizationId, self::managedOrganizationIds(), true);
    }

    /** Any role that may open the organization workspace for this organization. */
    public static function canManageOrganization(int $organizationId): bool
    {
        return $organizationId > 0 && in_array($organizationId, self::managedOrganizationIds(), true);
    }

    /**
     * Web guard for organization workspaces. Returns the organization row,
     * or redirects the visitor with an explanatory flash message.
     *
     * @return array<string,mixed>
     */
    public static function requireOrganization(int $organizationId): array
    {
        Auth::requireLogin();
        $org = Database::one('SELECT * FROM organizations WHERE id = :id', ['id' => $organizationId]);
        if ($org === null) {
            Helpers::flash('error', 'That organization does not exist.');
            Helpers::redirect(self::homeForRole());
        }
        if (!self::canManageOrganization($organizationId)) {
            Security::audit('ACCESS_DENIED', 'authz', 'Blocked from organization #' . $organizationId);
            Helpers::flash('error', 'You are not authorized to manage that organization.');
            Helpers::redirect(self::homeForRole());
        }
        return $org;
    }

    /**
     * The organization currently opened in the workspace (switchable from the
     * sidebar when the user manages more than one).
     *
     * @return array<string,mixed>|null
     */
    public static function activeOrganization(): ?array
    {
        $allowed = self::managedOrganizationIds();
        if ($allowed === []) {
            return null;
        }
        $current = (int) ($_SESSION['active_org'] ?? 0);
        if (!in_array($current, $allowed, true)) {
            $current = (int) $allowed[0];
            $_SESSION['active_org'] = $current;
        }
        return Database::one('SELECT * FROM organizations WHERE id = :id', ['id' => $current]);
    }

    /** Switch the workspace to another organization (validated against access). */
    public static function setActiveOrganization(int $organizationId): bool
    {
        if (!in_array($organizationId, self::managedOrganizationIds(), true)) {
            return false;
        }
        $_SESSION['active_org'] = $organizationId;
        return true;
    }

    /** Guards pages that only make sense for a student account. */
    public static function requireStudentProfile(): array
    {
        Auth::requireRole(['student']);
        $profile = Auth::studentProfile();
        if ($profile === null) {
            Helpers::flash('error', 'Your account has no student profile yet. Please contact the registrar or an administrator.');
            Helpers::redirect('logout.php');
        }
        return $profile;
    }
}
