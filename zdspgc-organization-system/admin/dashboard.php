<?php
/**
 * admin/dashboard.php — system administrator dashboard.
 *
 * Live totals, charts, the approval queue that needs a decision, upcoming
 * events, recent registrations and the latest audit entries.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('admin');

/* ---- approval actions (approve / reject an organization registration) ---- */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');
    $orgId  = Helpers::postInt('organization_id');

    if (in_array($action, ['approve', 'reject', 'archive', 'restore'], true) && $orgId > 0) {
        $org = OrgRepo::find($orgId);
        if ($org === null) {
            Helpers::flash('error', 'That organization no longer exists.');
        } else {
            $status = ['approve' => 'active', 'reject' => 'rejected', 'archive' => 'archived', 'restore' => 'active'][$action];
            $reason = (string) (Helpers::post('reason') ?? '');
            if ($action === 'reject' && $reason === '') {
                $reason = 'The application did not meet the registration requirements.';
            }
            try {
                OrgRepo::setStatus($orgId, $status, $reason);
                Helpers::flash('success', $org['name'] . ' is now ' . ui_status($status) . '.');
            } catch (Throwable $e) {
                Helpers::flash('error', $e->getMessage());
            }
        }
    }
    Helpers::redirect('admin/dashboard.php');
}

$stats     = OrgRepo::dashboardStats();
$breakdown = array_filter(OrgRepo::statusBreakdown(), static fn (int $n): bool => $n > 0);
$byCategory = OrgRepo::categoryBreakdown();
$pending   = OrgRepo::list(['status' => 'pending'], 1, 5)['rows'];
$upcoming  = EventRepo::upcoming(5);
$recent    = OrgRepo::recentlyRegistered(5);
$activity  = Audit::recent(8);
$year      = AcademicRepo::activeYear();

$PAGE_TITLE  = 'Dashboard';
$PAGE_ACTIVE = 'dashboard';
$PAGE_SUB    = 'System overview · Academic Year ' . (string) ($year['name'] ?? '—') . ' · ' . date('l, F j, Y');
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('admin/organizations.php')) . '">' . icon('org', 16)
    . '<span>Manage organizations</span></a>';
$PAGE_CHARTS = true;

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Total organizations', $stats['total'], 'org') ?>
  <?= ui_stat('Active', $stats['active'], 'check-circle') ?>
  <?= ui_stat('Pending applications', $stats['pending'], 'clock', $stats['pending'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Student members', $stats['members'], 'users') ?>
  <?= ui_stat('Active students', $stats['students'], 'user-check') ?>
  <?= ui_stat('Upcoming events', $stats['upcoming_events'], 'calendar') ?>
  <?= ui_stat('Pending proposals', $stats['pending_proposals'], 'clipboard', $stats['pending_proposals'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Documents to verify', $stats['pending_documents'], 'doc-check', $stats['pending_documents'] > 0 ? 'amber' : 'grey') ?>
</div>

<div class="grid cols-2">
  <?= Chart::card('chart-org-status', 'Organizations by status', 'doughnut', array_keys($breakdown), [Chart::dataset('Organizations', array_values($breakdown))], '240px') ?>
  <?= Chart::card('chart-org-category', 'Active organizations per category', 'bar', array_keys($byCategory), [Chart::dataset('Organizations', array_values($byCategory))], '240px') ?>
</div>

<div class="grid cols-2">
  <section class="card">
    <div class="card-head">
      <h3>Pending organization registrations</h3>
      <span class="badge amber"><?= (int) $stats['pending'] ?></span>
    </div>
    <?php if ($pending === []): ?>
      <?= ui_empty('No registration is waiting for a decision.', 'New applications appear here automatically.', 'check-circle') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Organization</th><th>Type</th><th>Submitted</th><th class="actions">Decision</th></tr></thead>
          <tbody>
            <?php foreach ($pending as $org): ?>
              <tr>
                <td>
                  <div class="org-cell">
                    <?= ui_org_badge($org, 36) ?>
                    <div>
                      <strong><?= Helpers::e((string) $org['name']) ?></strong>
                      <small><?= Helpers::e((string) $org['organization_code']) ?></small>
                    </div>
                  </div>
                </td>
                <td class="small"><?= Helpers::e((string) $org['organization_type']) ?></td>
                <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $org['submitted_at'])) ?></td>
                <td class="actions">
                  <form method="post" class="inline" data-confirm="Approve <?= Helpers::e((string) $org['name']) ?> as an active organization?">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="organization_id" value="<?= (int) $org['id'] ?>">
                    <button class="btn sm" type="submit" name="action" value="approve">Approve</button>
                    <button class="btn sm danger" type="submit" name="action" value="reject"
                            onclick="return confirm('Reject this registration? The reason is stored on the record.')">Reject</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head">
      <h3>Upcoming approved events</h3>
      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/events.php')) ?>">All events</a>
    </div>
    <?php if ($upcoming === []): ?>
      <?= ui_empty('No approved event is scheduled yet.', '', 'calendar') ?>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>Event</th><th>Organization</th><th>Date</th><th class="num">Registered</th></tr></thead>
        <tbody>
          <?php foreach ($upcoming as $event): ?>
            <tr>
              <td><strong><?= Helpers::e(Helpers::excerpt((string) $event['title'], 46)) ?></strong><br>
                  <span class="muted small"><?= Helpers::e((string) $event['venue']) ?></span></td>
              <td class="small"><?= Helpers::e((string) $event['organization_acronym']) ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $event['event_date'], 'M j, Y')) ?><br>
                  <span class="muted small"><?= Helpers::e(Helpers::fmtTime((string) $event['start_time'])) ?></span></td>
              <td class="num"><?= (int) $event['registered_count'] ?><?= (int) $event['max_participants'] > 0 ? ' / ' . (int) $event['max_participants'] : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</div>

<div class="grid cols-2">
  <section class="card">
    <div class="card-head"><h3>Recently registered organizations</h3></div>
    <?php if ($recent === []): ?>
      <?= ui_empty('No organization has been registered yet.', '', 'org') ?>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>Organization</th><th>Status</th><th>Accreditation</th><th>Registered</th></tr></thead>
        <tbody>
          <?php foreach ($recent as $org): ?>
            <tr>
              <td>
                <div class="org-cell">
                  <?= ui_org_badge($org, 32) ?>
                  <div>
                    <strong><?= Helpers::e(Helpers::excerpt((string) $org['name'], 32)) ?></strong>
                    <small><?= Helpers::e((string) $org['acronym']) ?></small>
                  </div>
                </div>
              </td>
              <td><?= ui_status_badge((string) $org['status']) ?></td>
              <td><?= ui_status_badge((string) $org['accreditation_status']) ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $org['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head">
      <h3>Recent system activity</h3>
      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/activity-logs.php')) ?>">Full log</a>
    </div>
    <?php if ($activity === []): ?>
      <?= ui_empty('No activity recorded yet.', '', 'log') ?>
    <?php else: ?>
      <ul class="timeline">
        <?php foreach ($activity as $entry): ?>
          <li>
            <div class="tl-time"><?= Helpers::e(Helpers::fmtDateTime((string) $entry['created_at'])) ?> · <?= Helpers::e((string) $entry['actor_name']) ?></div>
            <div class="tl-title"><?= ui_badge((string) $entry['action'], 'blue') ?> <?= Helpers::e((string) $entry['module']) ?></div>
            <div class="tl-desc"><?= Helpers::e((string) $entry['description']) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
