<?php
/**
 * search.php - global, role-aware search for every signed-in user.
 *
 * One query box, grouped results: organizations and published announcements for
 * everyone, students for admin/adviser/officer, users and events for the
 * administrator, and events, members and documents limited to the organizations
 * the adviser/officer manages. An empty query shows the latest items instead.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireLogin();

$role    = (string) Auth::role();
$q       = trim((string) (Helpers::get('q') ?? ''));
$hasQ    = $q !== '';
$needle  = mb_strtolower($q);
$managed = Permissions::managedOrganizationIds();
$profile = Auth::studentProfile();

/** @var array<int,array{title:string,icon:string,note:string,items:array<int,array{title:string,context:string,href:string}>}> $groups */
$groups = [];

$push = static function (string $title, string $iconName, string $note, array $items) use (&$groups): void {
    if ($items !== []) {
        $groups[] = ['title' => $title, 'icon' => $iconName, 'note' => $note, 'items' => $items];
    }
};

$matches = static fn (string $text): bool => $needle === '' || str_contains(mb_strtolower($text), $needle);
$inScope = static fn (array $row): bool => in_array((int) ($row['organization_id'] ?? 0), $managed, true);
$line    = static fn (array $parts): string => trim(implode('  ·  ', array_filter($parts, static fn ($p): bool => (string) $p !== '')));

/* ---------------------------------------------------------- organizations */
$orgItems = [];
foreach (OrgRepo::publicList($hasQ ? ['q' => $q] : [], $hasQ ? 40 : 6) as $org) {
    $orgItems[] = [
        'title'   => (string) $org['name'] . ((string) $org['acronym'] !== '' ? ' · ' . (string) $org['acronym'] : ''),
        'context' => $line([
            (string) $org['organization_type'],
            (int) $org['member_count'] . ' members',
            Helpers::excerpt((string) $org['description'], 110),
        ]),
        'href'    => 'organization.php?id=' . (int) $org['id'],
    ];
}
$push('Organizations', 'org',
    $hasQ ? 'Active organizations matching your search' : 'Latest active organizations in the directory',
    $orgItems);

/* --------------------------------------------------------- announcements */
$annItems = [];
if ($role === 'admin' || $role === 'adviser') {
    $annRows = AnnouncementRepo::list(
        $hasQ ? ['q' => $q, 'status' => 'published'] : ['status' => 'published'],
        1,
        $hasQ ? 20 : 6
    )['rows'];
} else {
    $feedOrgIds = $managed;
    $departmentId = 0;
    if ($profile !== null) {
        $departmentId = (int) ($profile['department_id'] ?? 0);
        if ($role === 'student') {
            $feedOrgIds = [];
            foreach (MemberRepo::forStudent((int) $profile['id']) as $membership) {
                $feedOrgIds[] = (int) $membership['organization_id'];
            }
        }
    }
    $annRows = array_slice(array_filter(
        AnnouncementRepo::feed($feedOrgIds, $departmentId, false, 50),
        static fn (array $row): bool => $matches(
            (string) $row['title'] . ' ' . (string) $row['content'] . ' ' . (string) ($row['organization_name'] ?? '')
        )
    ), 0, $hasQ ? 20 : 6);
}
foreach ($annRows as $row) {
    $annItems[] = [
        'title'   => (string) $row['title'],
        'context' => $line([
            (string) ($row['organization_name'] ?? ''),
            (string) ($row['department_name'] ?? ''),
            ui_status((string) ($row['audience'] ?? '')),
            (string) ($row['author_name'] ?? ''),
            Helpers::fmtDate((string) ($row['publish_date'] ?? '')),
        ]),
        'href'    => 'notifications.php',
    ];
}
$push('Announcements', 'megaphone',
    $hasQ ? 'Published announcements matching your search' : 'Latest published announcements',
    $annItems);

/* -------------------------------------------------------------- students */
if ($hasQ && in_array($role, ['admin', 'adviser', 'officer'], true)) {
    $studentItems = [];
    foreach (StudentRepo::list(['q' => $q], 1, 20)['rows'] as $student) {
        $studentId = (string) $student['student_id'];
        if ($role === 'admin') {
            $href = 'admin/students.php?id=' . (int) $student['id'];
        } elseif ($role === 'adviser') {
            $href = 'adviser/members.php?q=' . rawurlencode($studentId);
        } else {
            $href = 'organization/members.php?q=' . rawurlencode($studentId);
        }
        $studentItems[] = [
            'title'   => trim((string) $student['first_name'] . ' ' . (string) $student['last_name']) . ' (' . $studentId . ')',
            'context' => $line([
                (string) $student['course'],
                (string) $student['year_level'],
                (string) $student['section'],
                (string) ($student['department_name'] ?? ''),
                (string) ($student['account_email'] ?? ''),
            ]),
            'href'    => $href,
        ];
    }
    $push('Students', 'users', 'Student records matching your search', $studentItems);
}

/* ----------------------------------------------------------------- users */
if ($hasQ && $role === 'admin') {
    $userItems = [];
    foreach (UserRepo::list(['q' => $q], 1, 20)['rows'] as $user) {
        $userItems[] = [
            'title'   => (string) $user['full_name'] . ' (' . (string) $user['username'] . ')',
            'context' => $line([
                Permissions::ROLE_LABELS[(string) $user['role']] ?? ucfirst((string) $user['role']),
                (string) $user['email'],
                ui_status((string) $user['status']),
            ]),
            'href'    => 'admin/users.php?id=' . (int) $user['id'],
        ];
    }
    $push('Accounts', 'shield', 'User accounts matching your search', $userItems);
}

/* --------------------------------------------------------------- events */
if ($hasQ && in_array($role, ['admin', 'adviser', 'officer'], true)) {
    $eventRows = EventRepo::list(['q' => $q], 1, 60)['rows'];
    if ($role !== 'admin') {
        $eventRows = array_values(array_filter($eventRows, $inScope));
    }
    $eventHref = match ($role) {
        'admin'   => 'admin/events.php?id=',
        'adviser' => 'adviser/events.php?id=',
        default   => 'organization/events.php?id=',
    };
    $eventItems = [];
    foreach (array_slice($eventRows, 0, 20) as $event) {
        $eventItems[] = [
            'title'   => (string) $event['title'],
            'context' => $line([
                (string) ($event['organization_name'] ?? ''),
                Helpers::fmtDate((string) ($event['event_date'] ?? ''), 'M j, Y'),
                (string) ($event['venue'] ?? ''),
                ui_status((string) ($event['status'] ?? '')),
            ]),
            'href'    => $eventHref . (int) $event['id'],
        ];
    }
    $push('Events', 'calendar',
        $role === 'admin' ? 'Events matching your search' : 'Events of the organizations you manage',
        $eventItems);
}

/* --------------------------------------------------- members + documents */
if ($hasQ && in_array($role, ['adviser', 'officer'], true)) {
    $membersHref = $role === 'adviser' ? 'adviser/members.php?q=' : 'organization/members.php?q=';
    $memberItems = [];
    $memberRows  = array_values(array_filter(MemberRepo::list(['q' => $q], 1, 60)['rows'], $inScope));
    foreach (array_slice($memberRows, 0, 20) as $member) {
        $memberItems[] = [
            'title'   => trim((string) $member['last_name'] . ', ' . (string) $member['first_name'])
                . ' (' . (string) $member['student_id'] . ')',
            'context' => $line([
                (string) $member['organization_name'],
                (string) (($member['position_name'] ?? '') !== '' ? $member['position_name'] : $member['course']),
                ui_status((string) $member['status']),
            ]),
            'href'    => $membersHref . rawurlencode((string) $member['student_id']),
        ];
    }
    $push('Members', 'user-check', 'Members of the organizations you manage', $memberItems);

    $documentsHref = $role === 'adviser' ? 'adviser/documents.php?id=' : 'organization/documents.php?id=';
    $documentItems = [];
    $documentRows  = array_values(array_filter(DocumentRepo::list(['q' => $q], 1, 60)['rows'], $inScope));
    foreach ($documentRows as $document) {
        if (!DocumentRepo::mayRead($document)) {
            continue;
        }
        $documentItems[] = [
            'title'   => (string) $document['title'],
            'context' => $line([
                (string) ($document['organization_name'] ?? ''),
                (string) $document['document_type'],
                (string) $document['file_name'],
                ui_status((string) $document['status']),
            ]),
            'href'    => $documentsHref . (int) $document['id'],
        ];
    }
    $push('Documents', 'folder', 'Documents of the organizations you manage', array_slice($documentItems, 0, 20));
}

/* ------------------------------------------------------------ page meta */
$PAGE_TITLE  = 'Search';
$PAGE_ACTIVE = '';
$PAGE_SUB    = $hasQ
    ? count($groups) . ' group(s) found for "' . Helpers::e($q) . '" for ' . Helpers::e(Auth::roleLabel())
    : 'Search students, organizations, events, documents and announcements.';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => Permissions::homeForRole()], ['label' => 'Search']];

require __DIR__ . '/includes/layout/header.php';
?>

<?= ui_filter_form('search.php', ui_filter_input('q', 'Search', $q), 'Search') ?>

<?php if (!$hasQ): ?>
  <p class="hint mb">Type a keyword above to search the whole system. The latest organizations and announcements are listed below.</p>
<?php endif; ?>

<?php if ($hasQ && $groups === []): ?>
  <section class="card">
    <?= ui_empty('No result matches "' . $q . '".', 'Try a shorter keyword or check the spelling.', 'search') ?>
  </section>
<?php endif; ?>

<?php foreach ($groups as $group): ?>
  <section class="card">
    <div class="card-head">
      <div>
        <h3><?= icon((string) $group['icon'], 16) ?> <?= Helpers::e((string) $group['title']) ?></h3>
        <p class="sub muted small"><?= Helpers::e((string) $group['note']) ?></p>
      </div>
      <span class="badge grey"><?= count((array) $group['items']) ?></span>
    </div>
    <ul class="timeline">
      <?php foreach ($group['items'] as $item): ?>
        <li>
          <div class="tl-title">
            <a href="<?= Helpers::e(Helpers::url((string) $item['href'])) ?>"><?= Helpers::e((string) $item['title']) ?></a>
          </div>
          <div class="tl-desc"><?= Helpers::e((string) $item['context']) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
