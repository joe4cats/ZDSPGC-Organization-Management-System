<?php
/**
 * student/my-organizations.php — every membership record of the signed-in student.
 *
 * Grouped by status: active memberships with position and joining date, pending
 * applications waiting for a decision, and the rejected/archived history. The
 * screen is read-only; membership decisions belong to the organization officers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$student   = Permissions::requireStudentProfile();
$studentId = (int) $student['id'];

$memberships = MemberRepo::forStudent($studentId);

$active  = [];
$pending = [];
$history = [];
foreach ($memberships as $membership) {
    $status = (string) $membership['status'];
    if ($status === 'active') {
        $active[] = $membership;
    } elseif ($status === 'pending') {
        $pending[] = $membership;
    } else {
        $history[] = $membership;
    }
}

$PAGE_TITLE  = 'My Organizations';
$PAGE_ACTIVE = 'my-organizations';
$PAGE_SUB    = 'Your membership records for ' . SCHOOL_NAME . ' — applications, active memberships and history.';
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('student/organizations.php')) . '">'
    . icon('org', 16) . '<span>Browse directory</span></a>';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'student/dashboard.php'], ['label' => 'My Organizations']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Active memberships', count($active), 'org') ?>
  <?= ui_stat('Pending applications', count($pending), 'clock', count($pending) > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Membership history', count($history), 'doc-check') ?>
  <?= ui_stat('Total applications', count($memberships), 'clipboard') ?>
</div>

<?php if ($memberships === []): ?>
  <section class="card">
    <?= ui_empty('You have not joined any organization yet.', 'Open the directory and apply for membership.', 'org') ?>
    <div class="btn-row center">
      <a class="btn" href="<?= Helpers::e(Helpers::url('student/organizations.php')) ?>">
        <?= icon('org', 16) ?><span>Browse organizations</span>
      </a>
    </div>
  </section>
<?php else: ?>

<section class="card">
  <div class="card-head">
    <h3>Active memberships</h3>
    <span class="sub"><?= count($active) ?> organization<?= count($active) === 1 ? '' : 's' ?></span>
  </div>
  <?php if ($active === []): ?>
    <?= ui_empty('No active membership right now.', 'Pending applications are reviewed by the organization officers.', 'user-check') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Organization</th>
            <th>Position</th>
            <th>Joined</th>
            <th>Academic year</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($active as $membership): ?>
            <tr>
              <td>
                <div class="org-cell">
                  <?= ui_org_badge($membership, 34) ?>
                  <div>
                    <strong><?= Helpers::e((string) $membership['organization_name']) ?></strong>
                    <span class="muted"><?= Helpers::e((string) $membership['acronym']) ?></span>
                  </div>
                </div>
              </td>
              <td>
                <?php $position = (string) $membership['position_title'] !== ''
                    ? (string) $membership['position_title']
                    : (string) ($membership['position_name'] ?? ''); ?>
                <?php if ($position !== ''): ?>
                  <?= Helpers::e($position) ?>
                <?php else: ?>
                  <span class="muted">Member</span>
                <?php endif; ?>
              </td>
              <td class="nowrap"><?= Helpers::e(Helpers::fmtDate((string) $membership['joined_at'])) ?></td>
              <td class="nowrap"><?= Helpers::e((string) ($membership['academic_year_name'] ?? '—')) ?></td>
              <td><?= ui_status_badge((string) $membership['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<div class="grid cols-2">
  <section class="card">
    <div class="card-head">
      <h3>Pending applications</h3>
      <span class="sub"><?= count($pending) ?> waiting</span>
    </div>
    <?php if ($pending === []): ?>
      <?= ui_empty('No application is waiting for a decision.', '', 'check-circle') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl compact">
          <thead>
            <tr>
              <th>Organization</th>
              <th>Applied</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pending as $membership): ?>
              <tr>
                <td>
                  <a class="strong" href="<?= Helpers::e(Helpers::url('organization.php?id=' . (int) $membership['organization_id'])) ?>">
                    <?= Helpers::e((string) $membership['organization_name']) ?>
                  </a>
                  <span class="muted"><?= Helpers::e(Helpers::excerpt((string) $membership['remarks'], 80)) ?></span>
                </td>
                <td class="nowrap"><?= Helpers::e(Helpers::fmtDateTime((string) $membership['applied_at'])) ?></td>
                <td><?= ui_status_badge((string) $membership['status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head">
      <h3>Rejected, archived and closed</h3>
      <span class="sub"><?= count($history) ?> record<?= count($history) === 1 ? '' : 's' ?></span>
    </div>
    <?php if ($history === []): ?>
      <?= ui_empty('No closed membership record.', '', 'doc-check') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl compact">
          <thead>
            <tr>
              <th>Organization</th>
              <th>Decided</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($history as $membership): ?>
              <tr>
                <td>
                  <a class="strong" href="<?= Helpers::e(Helpers::url('organization.php?id=' . (int) $membership['organization_id'])) ?>">
                    <?= Helpers::e((string) $membership['organization_name']) ?>
                  </a>
                  <span class="muted"><?= Helpers::e(Helpers::excerpt((string) $membership['remarks'], 80)) ?></span>
                </td>
                <td class="nowrap">
                  <?= Helpers::e(Helpers::fmtDate((string) (($membership['decided_at'] ?? '') !== '' ? $membership['decided_at'] : $membership['applied_at']))) ?>
                </td>
                <td><?= ui_status_badge((string) $membership['status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<p class="hint">Membership records are read-only here. Ask the organization officers if a status needs to change.</p>

<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
