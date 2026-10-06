<?php
/**
 * layout/sidebar.php — role aware navigation (menu definition + rendering).
 *
 * The menu is declared once per role and every item is filtered through
 * Permissions::can(), so a link never appears for a user who may not open it.
 *
 * Expects: $PAGE_ACTIVE (current nav key).
 */

declare(strict_types=1);

/**
 * @return array<int,array{group:string,items:array<int,array{key:string,label:string,href:string,icon:string,cap?:string}>}>
 */
function layout_nav(string $role): array
{
    $menu = match ($role) {
        'admin' => [
            ['group' => 'Overview', 'items' => [
                ['key' => 'dashboard',    'label' => 'Dashboard',     'href' => 'admin/dashboard.php',    'icon' => 'dashboard'],
                ['key' => 'organizations', 'label' => 'Organizations', 'href' => 'admin/organizations.php', 'icon' => 'org',  'cap' => 'manage_organizations'],
                ['key' => 'calendar',      'label' => 'Calendar',      'href' => 'admin/calendar.php',      'icon' => 'calendar'],
                ['key' => 'reports',       'label' => 'Reports',       'href' => 'admin/reports.php',       'icon' => 'chart', 'cap' => 'view_reports'],
            ]],
            ['group' => 'People', 'items' => [
                ['key' => 'students', 'label' => 'Students', 'href' => 'admin/students.php', 'icon' => 'users',     'cap' => 'manage_students'],
                ['key' => 'advisers', 'label' => 'Advisers', 'href' => 'admin/advisers.php', 'icon' => 'briefcase', 'cap' => 'manage_advisers'],
                ['key' => 'members',  'label' => 'Members',  'href' => 'admin/members.php',  'icon' => 'user-check', 'cap' => 'manage_members'],
                ['key' => 'officers', 'label' => 'Officers', 'href' => 'admin/officers.php', 'icon' => 'award',     'cap' => 'manage_officers'],
            ]],
            ['group' => 'Activities', 'items' => [
                ['key' => 'events',     'label' => 'Events',     'href' => 'admin/events.php',     'icon' => 'calendar',  'cap' => 'view_all_events'],
                ['key' => 'projects',   'label' => 'Projects',   'href' => 'admin/projects.php',   'icon' => 'layers',    'cap' => 'manage_projects'],
                ['key' => 'proposals',  'label' => 'Proposals',  'href' => 'admin/proposals.php',  'icon' => 'clipboard', 'cap' => 'approve_proposals'],
                ['key' => 'attendance', 'label' => 'Attendance', 'href' => 'admin/attendance.php', 'icon' => 'qr',        'cap' => 'view_all_attendance'],
            ]],
            ['group' => 'Records', 'items' => [
                ['key' => 'documents',     'label' => 'Documents',     'href' => 'admin/documents.php',     'icon' => 'folder',    'cap' => 'review_documents'],
                ['key' => 'announcements', 'label' => 'Announcements', 'href' => 'admin/announcements.php', 'icon' => 'megaphone', 'cap' => 'manage_announcements'],
                ['key' => 'logs',          'label' => 'Activity Logs', 'href' => 'admin/activity-logs.php', 'icon' => 'log',        'cap' => 'view_logs'],
            ]],
            ['group' => 'Configuration', 'items' => [
                ['key' => 'academic-years', 'label' => 'Academic Years', 'href' => 'admin/academic-years.php', 'icon' => 'hash',     'cap' => 'manage_academic_years'],
                ['key' => 'categories',     'label' => 'Categories',     'href' => 'admin/categories.php',     'icon' => 'layers',   'cap' => 'manage_categories'],
                ['key' => 'departments',    'label' => 'Departments',    'href' => 'admin/departments.php',    'icon' => 'org',      'cap' => 'manage_departments'],
                ['key' => 'users',          'label' => 'Users',          'href' => 'admin/users.php',          'icon' => 'shield',   'cap' => 'manage_users'],
                ['key' => 'settings',       'label' => 'Settings',       'href' => 'admin/settings.php',       'icon' => 'settings', 'cap' => 'manage_settings'],
            ]],
        ],
        'adviser' => [
            ['group' => 'Overview', 'items' => [
                ['key' => 'dashboard',     'label' => 'Dashboard',     'href' => 'adviser/dashboard.php',     'icon' => 'dashboard'],
                ['key' => 'organizations', 'label' => 'Organizations', 'href' => 'adviser/organizations.php', 'icon' => 'org'],
                ['key' => 'calendar',      'label' => 'Calendar',      'href' => 'adviser/calendar.php',      'icon' => 'calendar'],
                ['key' => 'reports',       'label' => 'Reports',       'href' => 'adviser/reports.php',       'icon' => 'chart', 'cap' => 'view_reports'],
            ]],
            ['group' => 'Monitoring', 'items' => [
                ['key' => 'members',       'label' => 'Members',       'href' => 'adviser/members.php',       'icon' => 'user-check', 'cap' => 'review_members'],
                ['key' => 'officers',      'label' => 'Officers',      'href' => 'adviser/officers.php',      'icon' => 'award',      'cap' => 'review_officers'],
                ['key' => 'events',        'label' => 'Events',        'href' => 'adviser/events.php',        'icon' => 'calendar',   'cap' => 'review_events'],
                ['key' => 'proposals',     'label' => 'Proposals',     'href' => 'adviser/proposals.php',     'icon' => 'clipboard',  'cap' => 'review_proposals'],
                ['key' => 'documents',     'label' => 'Documents',     'href' => 'adviser/documents.php',     'icon' => 'folder',     'cap' => 'review_documents'],
                ['key' => 'attendance',    'label' => 'Attendance',    'href' => 'adviser/attendance.php',    'icon' => 'qr',         'cap' => 'view_attendance'],
                ['key' => 'students',      'label' => 'Import Students', 'href' => 'adviser/students.php',     'icon' => 'users',      'cap' => 'import_students'],
                ['key' => 'announcements', 'label' => 'Announcements', 'href' => 'adviser/announcements.php', 'icon' => 'megaphone',  'cap' => 'post_announcements'],
            ]],
        ],
        'officer' => [
            ['group' => 'Organization', 'items' => [
                ['key' => 'dashboard', 'label' => 'Dashboard',           'href' => 'organization/dashboard.php', 'icon' => 'dashboard'],
                ['key' => 'profile',   'label' => 'Organization Profile', 'href' => 'organization/profile.php',   'icon' => 'org',      'cap' => 'edit_organization'],
                ['key' => 'members',   'label' => 'Members',             'href' => 'organization/members.php',   'icon' => 'users',    'cap' => 'manage_members'],
                ['key' => 'officers',  'label' => 'Officers',            'href' => 'organization/officers.php',  'icon' => 'award',    'cap' => 'manage_officers'],
            ]],
            ['group' => 'Activities', 'items' => [
                ['key' => 'events',     'label' => 'Events',     'href' => 'organization/events.php',     'icon' => 'calendar',  'cap' => 'manage_events'],
                ['key' => 'attendance', 'label' => 'Attendance', 'href' => 'organization/attendance.php', 'icon' => 'qr',        'cap' => 'view_attendance'],
                ['key' => 'projects',   'label' => 'Projects',   'href' => 'organization/projects.php',   'icon' => 'layers',    'cap' => 'manage_projects'],
                ['key' => 'proposals',  'label' => 'Proposals',  'href' => 'organization/proposals.php',  'icon' => 'clipboard', 'cap' => 'submit_proposals'],
                ['key' => 'documents',  'label' => 'Documents',  'href' => 'organization/documents.php',  'icon' => 'folder',    'cap' => 'manage_documents'],
            ]],
            ['group' => 'Communication', 'items' => [
                ['key' => 'announcements', 'label' => 'Announcements', 'href' => 'organization/announcements.php', 'icon' => 'megaphone', 'cap' => 'post_announcements'],
                ['key' => 'calendar',      'label' => 'Calendar',      'href' => 'organization/calendar.php',      'icon' => 'calendar'],
                ['key' => 'reports',       'label' => 'Reports',       'href' => 'organization/reports.php',       'icon' => 'chart',      'cap' => 'org_reports'],
                ['key' => 'settings',      'label' => 'Settings',      'href' => 'organization/settings.php',      'icon' => 'settings',   'cap' => 'edit_organization'],
            ]],
        ],
        'student' => [
            ['group' => 'Overview', 'items' => [
                ['key' => 'dashboard',       'label' => 'Dashboard',        'href' => 'student/dashboard.php',       'icon' => 'dashboard'],
                ['key' => 'organizations',   'label' => 'Organizations',    'href' => 'student/organizations.php',   'icon' => 'org'],
                ['key' => 'my-organizations', 'label' => 'My Organizations', 'href' => 'student/my-organizations.php', 'icon' => 'layers'],
            ]],
            ['group' => 'Events & Attendance', 'items' => [
                ['key' => 'events',           'label' => 'Events',           'href' => 'student/events.php',           'icon' => 'calendar',   'cap' => 'register_events'],
                ['key' => 'my-registrations', 'label' => 'My Registrations', 'href' => 'student/my-registrations.php', 'icon' => 'clipboard'],
                ['key' => 'scan',             'label' => 'QR Check-In',      'href' => 'scan.php',                      'icon' => 'qr',          'cap' => 'scan_attendance'],
                ['key' => 'my-attendance',    'label' => 'My Attendance',    'href' => 'student/my-attendance.php',     'icon' => 'doc-check',   'cap' => 'view_own_attendance'],
            ]],
            ['group' => 'Information', 'items' => [
                ['key' => 'announcements', 'label' => 'Announcements', 'href' => 'student/announcements.php', 'icon' => 'megaphone', 'cap' => 'view_announcements'],
                ['key' => 'calendar',      'label' => 'Calendar',      'href' => 'student/calendar.php',      'icon' => 'calendar'],
            ]],
        ],
        default => [],
    };

    // Every role also gets its notifications inbox and its own account page.
    $menu[] = ['group' => 'Account', 'items' => [
        ['key' => 'notifications', 'label' => 'Notifications', 'href' => 'notifications.php', 'icon' => 'bell'],
        ['key' => 'account',       'label' => 'My Profile',    'href' => 'account.php',       'icon' => 'user'],
    ]];

    return $menu;
}

/** Renders one navigation link (hidden when the role lacks the capability). */
function layout_nav_link(array $item, string $active): void
{
    if (isset($item['cap']) && !Permissions::can((string) $item['cap'])) {
        return;
    }
    $isActive = (string) $item['key'] === $active;
    $badge    = '';
    if (($item['key'] ?? '') === 'notifications' && ($unread = Notifications::unreadCount()) > 0) {
        $badge = '<span class="nav-badge">' . ($unread > 99 ? '99+' : $unread) . '</span>';
    }
    echo '<a href="' . Helpers::e(Helpers::url((string) $item['href'])) . '"'
        . ($isActive ? ' class="active" aria-current="page"' : '')
        . ' title="' . Helpers::e((string) $item['label']) . '">'
        . icon((string) $item['icon'], 18)
        . '<span class="nav-text">' . Helpers::e((string) $item['label']) . '</span>'
        . $badge . '</a>';
}

/** Organization switcher for users managing more than one organization. */
function layout_org_switcher(): string
{
    $managed = Permissions::managedOrganizationIds();
    if (count($managed) < 2) {
        return '';
    }
    $active = Permissions::activeOrganization();
    if ($active === null) {
        return '';
    }

    $options = '';
    foreach ($managed as $orgId) {
        $row   = Database::one('SELECT id, name, acronym FROM organizations WHERE id = :id', ['id' => $orgId]);
        $label = $row === null ? ('#' . $orgId) : (($row['acronym'] !== '' ? $row['acronym'] . ' — ' : '') . $row['name']);
        $options .= '<option value="' . (int) $orgId . '"' . ((int) $orgId === (int) $active['id'] ? ' selected' : '') . '>'
            . Helpers::e($label) . '</option>';
    }

    return '<form class="org-switch" method="post" action="' . Helpers::e(Helpers::url('switch-organization.php')) . '">'
        . Security::csrfField()
        . '<label class="org-switch-label" for="org_switch">' . icon('layers', 15) . '<span>Workspace</span></label>'
        . '<select id="org_switch" name="organization_id" onchange="this.form.submit()">' . $options . '</select>'
        . '<noscript><button class="btn sm" type="submit">Switch</button></noscript>'
        . '</form>';
}

/** Shared identity block at the bottom of the sidebar. */
function layout_sidebar_user(): string
{
    $user   = Auth::user();
    $avatar = ui_avatar(
        (string) Auth::userName(),
        Uploads::url((string) ($user['avatar'] ?? '')),
        38
    );

    return '<div class="side-user">' . $avatar
        . '<span class="user-meta"><strong>' . Helpers::e((string) Auth::userName()) . '</strong>'
        . '<small>' . Helpers::e(Auth::roleLabel()) . '</small></span>'
        . '<form method="post" action="' . Helpers::e(Helpers::url('logout.php')) . '" class="side-logout">'
        . Security::csrfField()
        . '<button class="icon-btn" type="submit" title="Sign out" aria-label="Sign out">' . icon('logout', 18) . '</button>'
        . '</form></div>';
}
