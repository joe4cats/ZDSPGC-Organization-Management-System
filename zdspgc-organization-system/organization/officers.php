<?php
/**
 * organization/officers.php — officer terms of the open organization.
 *
 * Current officers of the active academic year, the appointment form for the
 * active members of the roster and the term history kept per academic year.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('manage_officers');

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
    $action = (string) (Helpers::post('action') ?? '');

    try {
        if ($action === 'assign') {
            $student    = StudentRepo::findByStudentId((string) (Helpers::post('student_id') ?? ''));
            $positionId = Helpers::postInt('position_id');
            if ($student === null) {
                throw new RuntimeException('Choose a student from the membership roster.');
            }
            if ($positionId < 1) {
                throw new RuntimeException('Choose the officer position to assign.');
            }
            $membership = MemberRepo::list([
                'organization_id' => $orgId,
                'student_id'      => (int) $student['id'],
                'status'          => 'active',
            ], 1, 1)['rows'];
            if ($membership === []) {
                throw new RuntimeException('Only an active member of this organization can be appointed.');
            }
            OfficerRepo::assign($orgId, (int) $student['id'], $positionId, [
                'term'       => Security::clean((string) (Helpers::post('term') ?? ''), 40),
                'start_date' => (string) (Helpers::post('start_date') ?? ''),
                'end_date'   => (string) (Helpers::post('end_date') ?? ''),
            ]);
            Helpers::flash('success', 'The officer was appointed.');
        } elseif ($action === 'end') {
            $officerId = Helpers::postInt('officer_id');
            $officer   = $officerId > 0 ? OfficerRepo::find($officerId) : null;
            if ($officer === null || (int) $officer['organization_id'] !== $orgId) {
                throw new RuntimeException('That officer record does not belong to this organization.');
            }
            OfficerRepo::end(
                $officerId,
                (string) (Helpers::post('end_date') ?? ''),
                Security::clean((string) (Helpers::post('notes') ?? ''), 255)
            );
            Helpers::flash('success', 'The officer term was closed.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }
    Helpers::redirect('organization/officers.php');
}

/* ---- read ---- */
$view      = Helpers::get('view') === 'history' ? 'history' : 'current';
$year      = AcademicRepo::activeYear();
$counts    = OfficerRepo::countByOrganization($orgId);
$positions = AcademicRepo::positionOptions(true);
$members   = MemberRepo::list([
    'organization_id' => $orgId,
    'status'          => 'active',
    'officers_only'   => false,
], 1, 200)['rows'];

$studentOptions = [];
foreach ($members as $member) {
    $studentOptions[(string) $member['student_id']] = $member['last_name'] . ', ' . $member['first_name']
        . ' · ' . $member['student_id'] . ($member['course'] !== '' ? ' · ' . $member['course'] : '');
}

$rows = $view === 'history'
    ? OfficerRepo::history($orgId)
    : OfficerRepo::current($orgId);

$PAGE_TITLE  = 'Officers';
$PAGE_ACTIVE = 'officers';
$PAGE_SUB    = Helpers::e((string) $org['name']) . ' · ' . (int) $counts['active'] . ' active officer(s) · Academic year '
    . Helpers::e((string) ($year['name'] ?? '—'));
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('organization/members.php')) . '">'
    . icon('users', 16) . '<span>Members</span></a>'
    . '<a class="btn" href="' . Helpers::e(Helpers::url('organization/reports.php?report=officers')) . '">'
    . icon('download', 16) . '<span>Officer report</span></a>';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'organization/dashboard.php'], ['label' => 'Officers']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Active officers', (int) $counts['active'], 'award') ?>
  <?= ui_stat('Ended this year', (int) $counts['ended'], 'clock') ?>
  <?= ui_stat('Previous terms', (int) $counts['history'], 'log') ?>
  <?= ui_stat('Active members', count($members), 'users') ?>
</div>

<section class="card">
  <div class="card-head"><h3>Appoint an officer</h3></div>
  <?php if ($studentOptions === []): ?>
    <?= ui_empty('No active member can be appointed yet.', 'Approve a membership application first.', 'users') ?>
  <?php else: ?>
    <form method="post">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="assign">
      <div class="grid cols-3">
        <?= ui_select('student_id', 'Active member', $studentOptions, '', ['placeholder' => 'Choose a member'], true) ?>
        <?= ui_select('position_id', 'Officer position', $positions, '', ['placeholder' => 'Choose a position'], true) ?>
        <?= ui_input('term', 'Term', (string) ($year['name'] ?? ''), ['hint' => 'Shown on the officer record.']) ?>
      </div>
      <div class="grid cols-2">
        <?= ui_input('start_date', 'Term starts', Helpers::today(), ['type' => 'date']) ?>
        <?= ui_input('end_date', 'Term ends', '', ['type' => 'date', 'hint' => 'Leave empty when it has no fixed end.']) ?>
      </div>
      <div class="form-actions">
        <button class="btn" type="submit"><?= icon('plus', 16) ?><span>Assign officer</span></button>
      </div>
    </form>
  <?php endif; ?>
</section>

<section class="card">
  <div class="card-head">
    <div class="tabs">
      <a class="<?= $view === 'current' ? 'active' : '' ?>" href="<?= Helpers::e(Helpers::url('organization/officers.php')) ?>">
        <?= icon('award', 16) ?><span>Current term</span><span class="count"><?= (int) $counts['active'] ?></span>
      </a>
      <a class="<?= $view === 'history' ? 'active' : '' ?>" href="<?= Helpers::e(Helpers::url('organization/officers.php?view=history')) ?>">
        <?= icon('log', 16) ?><span>History</span><span class="count"><?= (int) ($counts['ended'] + $counts['history']) ?></span>
      </a>
    </div>
  </div>

  <?php if ($rows === []): ?>
    <?= ui_empty(
        $view === 'history' ? 'No previous officer term is recorded.' : 'No officer is appointed for the current term.',
        'Use the appointment form above to assign one.',
        'award'
    ) ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr><th>Position</th><th>Officer</th><th>Course</th><th>Term</th><th>Served</th><th>Status</th><th class="actions">Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><strong><?= Helpers::e((string) $row['position_name']) ?></strong></td>
              <td>
                <div class="person-cell">
                  <?= ui_avatar((string) $row['first_name'] . ' ' . (string) $row['last_name'], null, 32) ?>
                  <div class="person-meta">
                    <strong><?= Helpers::e((string) $row['last_name'] . ', ' . (string) $row['first_name']) ?></strong>
                    <small><?= Helpers::e((string) $row['student_id']) ?></small>
                  </div>
                </div>
              </td>
              <td class="small"><?= Helpers::e((string) $row['course']) ?><?= $row['year_level'] !== '' ? ' · ' . Helpers::e((string) $row['year_level']) : '' ?></td>
              <td class="small"><?= Helpers::e((string) ($row['term'] !== '' ? $row['term'] : $row['academic_year_name'])) ?></td>
              <td class="small nowrap">
                <?= Helpers::e(Helpers::fmtDate((string) ($row['start_date'] ?? ''))) ?>
                – <?= $row['end_date'] !== null && (string) $row['end_date'] !== ''
                    ? Helpers::e(Helpers::fmtDate((string) $row['end_date'])) : '<span class="muted">present</span>' ?>
              </td>
              <td><?= ui_status_badge((string) $row['status']) ?></td>
              <td class="actions">
                <?php if ((string) $row['status'] === 'active'): ?>
                  <form method="post" class="inline" data-confirm="End the term of <?= Helpers::e((string) $row['position_name']) ?> <?= Helpers::e((string) $row['first_name']) ?>?">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="end">
                    <input type="hidden" name="officer_id" value="<?= (int) $row['id'] ?>">
                    <label class="field mb-tight">
                      <span class="field-label">End date</span>
                      <input type="date" name="end_date" value="<?= Helpers::e(Helpers::today()) ?>">
                    </label>
                    <button class="btn sm danger" type="submit"><?= icon('close', 15) ?><span>End term</span></button>
                  </form>
                <?php else: ?>
                  <span class="muted small"><?= Helpers::e(Helpers::fmtDate((string) ($row['end_date'] ?? ''))) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
