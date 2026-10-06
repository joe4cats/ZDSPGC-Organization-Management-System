<?php
/**
 * organization/members.php — membership roster of the open organization.
 *
 * Applications waiting for a decision are queued on top (approve with an
 * optional position, reject with remarks), the roster below is filtered by
 * keyword, status and academic year and paginated.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('manage_members');

$org = Permissions::activeOrganization();
if ($org === null) {
    Helpers::flash('error', 'No organization workspace is open.');
    Helpers::redirect('organization/dashboard.php');
}
$orgId = (int) $org['id'];
Permissions::requireOrganization($orgId);

/* ---- POST actions (run BEFORE any output) ---- */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action   = (string) (Helpers::post('action') ?? '');
    $memberId = Helpers::postInt('member_id');

    try {
        $member = $memberId > 0 ? MemberRepo::find($memberId) : null;
        if ($member === null || (int) $member['organization_id'] !== $orgId) {
            throw new RuntimeException('That membership record does not belong to this organization.');
        }
        $remarks = Security::clean((string) (Helpers::post('remarks') ?? ''), 400);

        if ($action === 'approve') {
            $positionId = Helpers::postInt('position_id');
            MemberRepo::decide($memberId, 'active', $remarks, $positionId > 0 ? $positionId : null);
            Helpers::flash('success', 'The application was approved.');
        } elseif ($action === 'reject') {
            if ($remarks === '') {
                $remarks = 'The application did not meet the membership requirements.';
            }
            MemberRepo::decide($memberId, 'rejected', $remarks);
            Helpers::flash('success', 'The application was rejected.');
        } elseif ($action === 'remove') {
            MemberRepo::remove($memberId, $remarks);
            Helpers::flash('success', 'The member was removed from the active roster.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }
    Helpers::redirect('organization/members.php');
}

/* ---- read ---- */
$filters = [
    'organization_id'   => $orgId,
    'q'                 => (string) (Helpers::get('q') ?? ''),
    'status'            => (string) (Helpers::get('status') ?? ''),
    'academic_year_id'  => (string) (Helpers::get('year') ?? ''),
];
$result = MemberRepo::list($filters, Helpers::page(), 20);
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];
$query  = http_build_query(array_filter([
    'q'      => $filters['q'],
    'status' => $filters['status'],
    'year'   => $filters['academic_year_id'],
]));

$pending   = MemberRepo::list(['organization_id' => $orgId, 'status' => 'pending'], 1, 20)['rows'];
$counts    = MemberRepo::countByOrganization($orgId);
$positions = AcademicRepo::positionOptions();
$statuses  = array_combine(AcademicRepo::MEMBER_STATUSES, AcademicRepo::MEMBER_STATUSES);

$PAGE_TITLE  = 'Members';
$PAGE_ACTIVE = 'members';
$PAGE_SUB    = Helpers::e((string) $org['name']) . ' · ' . (int) $counts['active'] . ' active member(s) · '
    . (int) $counts['pending'] . ' application(s) pending';
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('organization/officers.php')) . '">'
    . icon('award', 16) . '<span>Officers</span></a>'
    . '<a class="btn" href="' . Helpers::e(Helpers::url('organization/reports.php?report=members')) . '">'
    . icon('download', 16) . '<span>Member report</span></a>';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'organization/dashboard.php'], ['label' => 'Members']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Active members', (int) $counts['active'], 'user-check') ?>
  <?= ui_stat('Pending applications', (int) $counts['pending'], 'clock', $counts['pending'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Officers in roster', (int) $counts['officers'], 'award') ?>
  <?= ui_stat('Records this year', (int) $counts['total'], 'users') ?>
</div>

<section class="card">
  <div class="card-head">
    <h3>Applications awaiting a decision</h3>
    <span class="badge <?= $counts['pending'] > 0 ? 'amber' : 'grey' ?>"><?= (int) $counts['pending'] ?></span>
  </div>
  <?php if ($pending === []): ?>
    <?= ui_empty('No pending membership application.', 'New applications appear here automatically.', 'check-circle') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Student</th><th>Course</th><th>Applied</th><th>Remarks / position</th><th class="actions">Decision</th></tr></thead>
        <tbody>
          <?php foreach ($pending as $row): ?>
            <tr>
              <td>
                <div class="person-cell">
                  <?= ui_avatar((string) $row['first_name'] . ' ' . (string) $row['last_name'], null, 32) ?>
                  <div class="person-meta">
                    <strong><?= Helpers::e((string) $row['last_name'] . ', ' . (string) $row['first_name']) ?></strong>
                    <small><?= Helpers::e((string) $row['student_id']) ?> · <?= Helpers::e((string) $row['year_level']) ?></small>
                  </div>
                </div>
              </td>
              <td class="small"><?= Helpers::e((string) $row['course']) ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) ($row['applied_at'] ?? ''))) ?></td>
              <td>
                <form method="post" class="inline">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="member_id" value="<?= (int) $row['id'] ?>">
                  <?= ui_select('position_id', 'Position', $positions, '', ['placeholder' => 'Member only'], false) ?>
                  <label class="field mb-tight">
                    <span class="field-label">Remarks</span>
                    <input type="text" name="remarks" value="<?= Helpers::e((string) ($row['remarks'] ?? '')) ?>" maxlength="400">
                  </label>
                  <div class="actions">
                    <button class="btn sm" type="submit"><?= icon('check', 15) ?><span>Approve</span></button>
                    <button class="btn sm danger" type="submit" name="action" value="reject"
                            onclick="return confirm('Reject this membership application?')"><?= icon('close', 15) ?><span>Reject</span></button>
                  </div>
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
  <div class="card-head"><h3>Member roster</h3><span class="muted small"><?= (int) $result['total'] ?> record(s)</span></div>

  <?= ui_filter_form('organization/members.php',
      ui_filter_input('q', 'Search', $filters['q'])
      . ui_filter_select('status', 'Status', $statuses, $filters['status'])
      . ui_filter_select('year', 'Academic year', AcademicRepo::yearOptions(), $filters['academic_year_id'])) ?>

  <?php if ($rows === []): ?>
    <?= ui_empty('No member matches these filters.', 'Clear the filters to see the whole roster.', 'users') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Student</th><th>Course</th><th>Year</th><th>Position</th><th>Status</th><th>Joined</th><th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td>
                <div class="person-cell">
                  <?= ui_avatar((string) $row['first_name'] . ' ' . (string) $row['last_name'], null, 32) ?>
                  <div class="person-meta">
                    <strong><?= Helpers::e((string) $row['last_name'] . ', ' . (string) $row['first_name']) ?></strong>
                    <small><?= Helpers::e((string) $row['student_id']) ?></small>
                  </div>
                </div>
              </td>
              <td class="small"><?= Helpers::e((string) $row['course']) ?></td>
              <td class="small nowrap"><?= Helpers::e((string) $row['year_level']) ?></td>
              <td class="small"><?= !empty($row['position_title']) ? Helpers::e((string) $row['position_title']) : '<span class="muted">Member</span>' ?></td>
              <td><?= ui_status_badge((string) $row['status']) ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) ($row['joined_at'] ?? $row['applied_at'] ?? ''))) ?></td>
              <td class="actions">
                <?php if ((string) $row['status'] !== 'inactive'): ?>
                  <form method="post" class="inline" data-confirm="Remove <?= Helpers::e((string) $row['first_name'] . ' ' . (string) $row['last_name']) ?> from the active roster?">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="member_id" value="<?= (int) $row['id'] ?>">
                    <button class="btn sm ghost danger" type="submit"><?= icon('trash', 15) ?><span>Remove</span></button>
                  </form>
                <?php else: ?>
                  <span class="muted small">Closed</span>
                <?php endif; ?>
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
