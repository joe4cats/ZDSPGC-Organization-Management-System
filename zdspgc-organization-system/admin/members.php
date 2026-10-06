<?php
/**
 * admin/members.php — membership applications across every organization.
 *
 * Filters (q, organization, status, academic year) over MemberRepo::list with
 * approve / reject / suspend / remove decisions through MemberRepo::decide()
 * and MemberRepo::remove().
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('manage_members');

/* ---- POST actions (before any output) ---- */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action    = (string) (Helpers::post('action') ?? '');
    $memberId  = Helpers::postInt('member_id');
    $reason    = (string) (Helpers::post('reason') ?? '');

    if (in_array($action, ['approve', 'reject', 'suspend', 'remove'], true) && $memberId > 0) {
        if (MemberRepo::find($memberId) === null) {
            Helpers::flash('error', 'That membership record no longer exists.');
        } else {
            try {
                if ($action === 'approve') {
                    MemberRepo::decide($memberId, 'active');
                    Helpers::flash('success', 'Membership approved.');
                } elseif ($action === 'reject') {
                    MemberRepo::decide($memberId, 'rejected', $reason !== '' ? $reason : 'The application was not approved.');
                    Helpers::flash('success', 'Membership rejected.');
                } elseif ($action === 'suspend') {
                    MemberRepo::decide($memberId, 'suspended', $reason);
                    Helpers::flash('success', 'Membership suspended.');
                } else {
                    MemberRepo::remove($memberId, $reason);
                    Helpers::flash('success', 'Member removed from the organization.');
                }
            } catch (Throwable $e) {
                Helpers::flash('error', $e->getMessage());
            }
        }
    }
    Helpers::redirect('admin/members.php');
}

/* ---- read ---- */
$filters = [
    'q'               => (string) (Helpers::get('q') ?? ''),
    'organization_id' => (string) (Helpers::get('organization_id') ?? ''),
    'status'          => (string) (Helpers::get('status') ?? ''),
    'academic_year_id'=> (string) (Helpers::get('academic_year_id') ?? ''),
];

$result = MemberRepo::list($filters, Helpers::page(), 20);
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];
$query  = http_build_query(array_filter($filters));

$orgOptions = [];
foreach (OrgRepo::list([], 1, 200)['rows'] as $orgRow) {
    $orgOptions[(int) $orgRow['id']] = (string) $orgRow['name'];
}

$statusOptions = [];
foreach (AcademicRepo::MEMBER_STATUSES as $statusOption) {
    $statusOptions[$statusOption] = ui_status($statusOption);
}

$totals = [
    'all'       => MemberRepo::list([], 1, 1)['total'],
    'pending'   => MemberRepo::list(['status' => 'pending'], 1, 1)['total'],
    'active'    => MemberRepo::list(['status' => 'active'], 1, 1)['total'],
    'suspended' => MemberRepo::list(['status' => 'suspended'], 1, 1)['total'],
];

/* ---- page meta (before header) ---- */
$PAGE_TITLE  = 'Members';
$PAGE_ACTIVE = 'members';
$PAGE_SUB    = 'Review membership applications and manage the roster of every organization.';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Members']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Memberships', $totals['all'], 'users') ?>
  <?= ui_stat('Pending applications', $totals['pending'], 'clock', $totals['pending'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Active members', $totals['active'], 'user-check') ?>
  <?= ui_stat('Suspended', $totals['suspended'], 'alert', $totals['suspended'] > 0 ? 'red' : 'grey') ?>
</div>

<?= ui_filter_form('admin/members.php',
    ui_filter_input('q', 'Search', $filters['q'])
    . ui_filter_select('organization_id', 'Organization', $orgOptions, $filters['organization_id'])
    . ui_filter_select('status', 'Status', $statusOptions, $filters['status'])
    . ui_filter_select('academic_year_id', 'Academic year', AcademicRepo::yearOptions(), $filters['academic_year_id'])) ?>

<section class="card">
  <div class="card-head">
    <h3>Membership records</h3>
    <?= ui_badge((string) $result['total'], 'blue') ?>
  </div>

  <?php if ($rows === []): ?>
    <?= ui_empty('No membership record matches this search.', 'Adjust the filters above, or ask a student to apply from the public directory.', 'user-check') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Student</th>
            <th>Organization</th>
            <th>Position</th>
            <th>Status</th>
            <th>Joined</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $member): ?>
            <?php $fullName = trim((string) $member['first_name'] . ' ' . (string) $member['last_name']); ?>
            <tr>
              <td>
                <div class="person-cell">
                  <?= ui_avatar($fullName, null, 34) ?>
                  <div class="person-meta">
                    <strong><?= Helpers::e($fullName) ?></strong>
                    <small><?= Helpers::e((string) $member['student_id']) ?><?= (string) $member['course'] !== '' ? ' · ' . Helpers::e((string) $member['course']) : '' ?></small>
                  </div>
                </div>
              </td>
              <td class="small"><?= Helpers::e((string) $member['organization_name']) ?><br>
                  <span class="muted small"><?= Helpers::e((string) $member['academic_year_name']) ?></span></td>
              <td class="small"><?= Helpers::e((string) ($member['position_name'] !== '' && $member['position_name'] !== null ? $member['position_name'] : 'Member')) ?></td>
              <td><?= ui_status_badge((string) $member['status']) ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $member['joined_at'])) ?></td>
              <td class="actions nowrap">
                <form method="post" class="inline">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
                  <input type="hidden" name="reason" value="">
                  <?php if ((string) $member['status'] === 'pending'): ?>
                    <button class="btn sm" type="submit" name="action" value="approve"
                            onclick="return confirm('Approve this membership application?')">Approve</button>
                    <button class="btn sm danger" type="submit" name="action" value="reject"
                            onclick="var r=prompt('Reason for rejecting this application:'); if(r===null){return false;} this.form.reason.value=r; return true;">Reject</button>
                  <?php elseif ((string) $member['status'] === 'active'): ?>
                    <button class="btn sm warn" type="submit" name="action" value="suspend"
                            onclick="return confirm('Suspend this membership? The student keeps the record but loses access.')">Suspend</button>
                    <button class="btn sm danger" type="submit" name="action" value="remove"
                            onclick="var r=prompt('Reason for removing this member:'); if(r===null){return false;} this.form.reason.value=r; return true;">Remove</button>
                  <?php else: ?>
                    <button class="btn sm" type="submit" name="action" value="approve"
                            onclick="return confirm('Reinstate this membership as active?')">Approve</button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= ui_pagination($page, $result['pages'], $query) ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
