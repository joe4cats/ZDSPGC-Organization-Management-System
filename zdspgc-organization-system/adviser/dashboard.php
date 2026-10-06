<?php
/**
 * adviser/dashboard.php — adviser dashboard.
 *
 * Covers every organization assigned to the signed-in adviser, with the
 * review queues (events, proposals, documents) that need their endorsement.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('adviser');

$adviserId = Permissions::adviserId();
$myOrgs    = OrgRepo::list(['adviser_id' => $adviserId], 1, 100)['rows'];
$myOrgIds  = array_map(static fn (array $o): int => (int) $o['id'], $myOrgs);
$inList    = $myOrgIds === [] ? 'NULL' : implode(',', array_map('intval', $myOrgIds));

$eventsAwaiting = $myOrgIds === [] ? [] : Database::all(
    'SELECT ev.*, o.name AS organization_name, o.acronym AS organization_acronym
       FROM events ev JOIN organizations o ON o.id = ev.organization_id
      WHERE ev.status = "pending_adviser" AND ev.organization_id IN (' . $inList . ')
      ORDER BY ev.submitted_at ASC LIMIT 6'
);

$proposalsAwaiting = $myOrgIds === [] ? [] : Database::all(
    'SELECT p.*, pr.title AS project_title, o.acronym AS organization_acronym
       FROM proposals p
       JOIN projects pr ON pr.id = p.project_id
       JOIN organizations o ON o.id = p.organization_id
      WHERE p.status = "submitted" AND p.organization_id IN (' . $inList . ')
      ORDER BY p.submitted_at ASC LIMIT 6'
);

$pendingDocs = $myOrgIds === [] ? [] : Database::all(
    'SELECT d.*, o.acronym AS organization_acronym FROM documents d
       JOIN organizations o ON o.id = d.organization_id
      WHERE d.status = "pending" AND d.organization_id IN (' . $inList . ')
      ORDER BY d.uploaded_at ASC LIMIT 6'
);

$upcoming = $myOrgIds === [] ? [] : Database::all(
    'SELECT ev.*, o.acronym AS organization_acronym FROM events ev
       JOIN organizations o ON o.id = ev.organization_id
      WHERE ev.status = "approved" AND ev.event_date >= CURDATE()
        AND ev.organization_id IN (' . $inList . ')
      ORDER BY ev.event_date ASC LIMIT 6'
);

$totalMembers  = $myOrgIds === [] ? 0 : (int) Database::scalar(
    'SELECT COUNT(*) FROM organization_members
      WHERE status = "active" AND organization_id IN (' . $inList . ')'
);
$totalOfficers = $myOrgIds === [] ? 0 : (int) Database::scalar(
    'SELECT COUNT(*) FROM organization_members
      WHERE status = "active" AND position_id IS NOT NULL AND organization_id IN (' . $inList . ')'
);

$perOrg = [];
foreach ($myOrgs as $org) {
    $stats = AttendanceRepo::statsForOrganization((int) $org['id']);
    $label = (string) ($org['acronym'] !== '' ? $org['acronym'] : $org['name']);
    $perOrg[Helpers::excerpt($label, 18)] = (int) $stats['percentage'];
}

$PAGE_TITLE   = 'Adviser Dashboard';
$PAGE_ACTIVE  = 'dashboard';
$PAGE_SUB     = count($myOrgs) . ' assigned organization(s) · ' . date('l, F j, Y');
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('organizations.php')) . '">' . icon('org', 16)
    . '<span>My organizations</span></a>';
$PAGE_CHARTS  = count($perOrg) > 0;

require __DIR__ . '/../includes/layout/header.php';
?>

<?php if ($myOrgs === []): ?>
  <section class="card">
    <?= ui_empty('No organization is assigned to you yet.',
        'The Office of Student Affairs assigns an adviser when an organization registration is approved.', 'user-check') ?>
  </section>
<?php else: ?>
  <div class="stat-grid">
    <?= ui_stat('Organizations advised', count($myOrgs), 'org') ?>
    <?= ui_stat('Members supervised', $totalMembers, 'users') ?>
    <?= ui_stat('Officers supervised', $totalOfficers, 'award') ?>
    <?= ui_stat('Events awaiting review', count($eventsAwaiting), 'clock', $eventsAwaiting !== [] ? 'amber' : 'grey') ?>
    <?= ui_stat('Proposals awaiting review', count($proposalsAwaiting), 'clipboard', $proposalsAwaiting !== [] ? 'amber' : 'grey') ?>
    <?= ui_stat('Documents to verify', count($pendingDocs), 'doc-check', $pendingDocs !== [] ? 'amber' : 'grey') ?>
  </div>

  <?php if ($perOrg !== []): ?>
    <div class="grid cols-2">
      <?= Chart::card('chart-advised', 'Attendance rate per organization (%)', 'bar', array_keys($perOrg), [Chart::dataset('Attendance %', array_values($perOrg))], '240px') ?>
      <section class="card">
        <div class="card-head">
          <h3>Upcoming events of my organizations</h3>
          <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/events.php')) ?>">All</a>
        </div>
        <?php if ($upcoming === []): ?>
          <?= ui_empty('No approved event scheduled.', '', 'calendar') ?>
        <?php else: ?>
          <ul class="timeline">
            <?php foreach ($upcoming as $event): ?>
              <li>
                <div class="tl-time"><?= Helpers::e(Helpers::fmtDate((string) $event['event_date'], 'l, M j, Y')) ?>
                  · <?= Helpers::e(Helpers::fmtTime((string) $event['start_time'])) ?></div>
                <div class="tl-title"><?= Helpers::e((string) $event['title']) ?></div>
                <div class="tl-desc">
                  <?= ui_badge((string) $event['organization_acronym'], 'grey') ?>
                  <span class="muted small"> · <?= Helpers::e((string) $event['venue']) ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </div>
  <?php endif; ?>

  <div class="grid cols-2">
    <section class="card">
      <div class="card-head">
        <h3>Events awaiting my endorsement</h3>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/events.php')) ?>">Review</a>
      </div>
      <?php if ($eventsAwaiting === []): ?>
        <?= ui_empty('No event is waiting for review.', '', 'check-circle') ?>
      <?php else: ?>
        <table class="tbl compact">
          <tbody>
            <?php foreach ($eventsAwaiting as $event): ?>
              <tr>
                <td><strong><?= Helpers::e(Helpers::excerpt((string) $event['title'], 46)) ?></strong><br>
                    <span class="muted small"><?= Helpers::e((string) $event['organization_acronym']) ?> · <?= Helpers::e(Helpers::fmtDate((string) $event['event_date'])) ?></span></td>
                <td class="right"><?= ui_status_badge((string) $event['status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head">
        <h3>Proposals awaiting my endorsement</h3>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/proposals.php')) ?>">Review</a>
      </div>
      <?php if ($proposalsAwaiting === []): ?>
        <?= ui_empty('No proposal is waiting for review.', '', 'check-circle') ?>
      <?php else: ?>
        <table class="tbl compact">
          <tbody>
            <?php foreach ($proposalsAwaiting as $proposal): ?>
              <tr>
                <td><strong><?= Helpers::e(Helpers::excerpt((string) $proposal['title'], 46)) ?></strong><br>
                    <span class="muted small"><?= Helpers::e((string) $proposal['organization_acronym']) ?> · <?= Helpers::e(Helpers::fmtDate((string) $proposal['submitted_at'])) ?></span></td>
                <td class="right"><?= ui_status_badge((string) $proposal['status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  </div>

  <section class="card">
    <div class="card-head">
      <h3>Documents waiting for verification</h3>
      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/documents.php')) ?>">Review</a>
    </div>
    <?php if ($pendingDocs === []): ?>
      <?= ui_empty('No document is waiting for verification.', '', 'doc-check') ?>
    <?php else: ?>
      <table class="tbl compact">
        <thead><tr><th>Document</th><th>Organization</th><th>Uploaded</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($pendingDocs as $doc): ?>
            <tr>
              <td><strong><?= Helpers::e(Helpers::excerpt((string) $doc['title'], 48)) ?></strong><br>
                  <span class="muted small"><?= Helpers::e((string) $doc['document_type']) ?></span></td>
              <td class="small"><?= Helpers::e((string) $doc['organization_acronym']) ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $doc['uploaded_at'])) ?></td>
              <td><?= ui_status_badge((string) $doc['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
