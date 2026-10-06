<?php
/**
 * admin/proposals.php — activity proposal queue and decisions.
 *
 * Filters (q, status, organization) with stat cards, and a detail view
 * (?id=N) holding the proposal, its project, the full reviewer comment
 * history and the approve / reject / revision decision form.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('approve_proposals');

$decisions = ['approved', 'rejected', 'revision_required'];

/* ---- POST actions (run BEFORE any output) ---- */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action     = (string) (Helpers::post('action') ?? '');
    $proposalId = Helpers::postInt('proposal_id');
    $return     = $proposalId > 0 ? 'admin/proposals.php?id=' . $proposalId : 'admin/proposals.php';

    if ($action === 'decide' && $proposalId > 0) {
        $decision = (string) (Helpers::post('decision') ?? '');
        $comments = Security::clean((string) (Helpers::post('comments') ?? ''), 600);
        if (!in_array($decision, $decisions, true)) {
            Helpers::flash('error', 'Choose one of the available decisions.');
        } else {
            try {
                ProposalRepo::decide($proposalId, $decision, $comments);
                Helpers::flash('success', 'Decision recorded: ' . ui_status($decision) . '.');
            } catch (Throwable $e) {
                Helpers::flash('error', $e->getMessage());
            }
        }
    }
    Helpers::redirect($return);
}

/* ---- read ---- */
$viewId    = Helpers::getInt('id');
$proposal  = $viewId > 0 ? ProposalRepo::find($viewId) : null;
$project   = null;
$comments  = [];

if ($viewId > 0 && $proposal === null) {
    Helpers::flash('error', 'That proposal no longer exists.');
    Helpers::redirect('admin/proposals.php');
}

if ($proposal !== null) {
    $project  = ProjectRepo::find((int) $proposal['project_id']);
    $comments = ProposalRepo::comments($viewId);
}

$filters = [
    'q'               => (string) (Helpers::get('q') ?? ''),
    'status'          => (string) (Helpers::get('status') ?? ''),
    'organization_id' => (string) (Helpers::get('organization_id') ?? ''),
];

$result = $proposal === null ? ProposalRepo::list($filters, Helpers::page(), 20) : ['rows' => [], 'total' => 0, 'pages' => 1];
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];
$query  = http_build_query(array_filter($filters));

$counts = ProposalRepo::statusCounts();

$orgList    = OrgRepo::list([], 1, 300)['rows'];
$orgOptions = [];
foreach ($orgList as $org) {
    $orgOptions[(int) $org['id']] = (string) $org['name'];
}
$statusOptions = array_combine(AcademicRepo::PROPOSAL_STATUSES, AcademicRepo::PROPOSAL_STATUSES);

$openForDecision = $proposal !== null && in_array((string) $proposal['status'], ['submitted', 'under_review'], true);

/* ---- page meta (before header) ---- */
$PAGE_TITLE  = $proposal !== null ? 'Proposal detail' : 'Proposals';
$PAGE_ACTIVE = 'proposals';
$PAGE_SUB    = $proposal !== null
    ? Helpers::e((string) $proposal['organization_name']) . ' · ' . Helpers::e(ui_status((string) $proposal['status']))
    : (int) $counts['total'] . ' proposal(s) · submitted, endorsed, approved, rejected or returned for revision';
$PAGE_BREADCRUMBS = $proposal !== null
    ? [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Proposals', 'href' => 'admin/proposals.php'], ['label' => (string) $proposal['title']]]
    : [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Proposals']];

$PAGE_ACTIONS = $proposal !== null
    ? '<a class="btn ghost" href="' . Helpers::e(Helpers::url('admin/proposals.php')) . '">' . icon('chevron-left', 16) . '<span>All proposals</span></a>'
    : '<a class="btn ghost" href="' . Helpers::e(Helpers::url('admin/projects.php')) . '">' . icon('layers', 16) . '<span>Projects</span></a>';

require __DIR__ . '/../includes/layout/header.php';
?>

<?php if ($proposal !== null): ?>
  <div class="stat-grid">
    <?= ui_stat('Status', ui_status((string) $proposal['status']), 'clipboard', ui_tone((string) $proposal['status'])) ?>
    <?= ui_stat('Version', (int) $proposal['current_version'], 'doc') ?>
    <?= ui_stat('Project budget', Helpers::money((string) ($project['budget'] ?? 0)), 'chart') ?>
    <?= ui_stat('Comments', count($comments), 'log') ?>
  </div>

  <div class="grid cols-2">
    <section class="card">
      <div class="card-head">
        <h3><?= Helpers::e((string) $proposal['title']) ?></h3>
        <?= ui_status_badge((string) $proposal['status']) ?>
      </div>
      <dl class="detail-list">
        <dt>Organization</dt><dd><?= Helpers::e((string) $proposal['organization_name']) ?> (<?= Helpers::e((string) $proposal['acronym']) ?>)</dd>
        <dt>Academic year</dt><dd><?= Helpers::e((string) ($proposal['academic_year_name'] ?? '—')) ?></dd>
        <dt>Submitted by</dt><dd><?= Helpers::e((string) ($proposal['submitted_by_name'] ?? '—')) ?></dd>
        <dt>Submitted at</dt><dd><?= Helpers::e(Helpers::fmtDateTime((string) ($proposal['submitted_at'] ?? ''))) ?></dd>
        <dt>Reviewed by</dt><dd><?= Helpers::e((string) ($proposal['reviewer_name'] ?? '—')) ?></dd>
        <dt>Reviewed at</dt><dd><?= Helpers::e(Helpers::fmtDateTime((string) ($proposal['reviewed_at'] ?? ''))) ?></dd>
        <dt>Reviewer comments</dt><dd><?= $proposal['reviewer_comments'] !== '' ? Helpers::e((string) $proposal['reviewer_comments']) : '—' ?></dd>
        <dt>Created</dt><dd><?= Helpers::e(Helpers::fmtDateTime((string) $proposal['created_at'])) ?></dd>
      </dl>
    </section>

    <section class="card">
      <div class="card-head">
        <h3>Project</h3>
        <?php if ($project !== null): ?>
          <?= ui_status_badge((string) $project['status']) ?>
        <?php endif; ?>
      </div>
      <?php if ($project === null): ?>
        <?= ui_empty('The linked project was deleted.', '', 'layers') ?>
      <?php else: ?>
        <dl class="detail-list">
          <dt>Title</dt><dd><strong><?= Helpers::e((string) $project['title']) ?></strong></dd>
          <dt>Dates</dt><dd><?= Helpers::e(Helpers::fmtDate((string) ($project['start_date'] ?? ''))) ?> – <?= Helpers::e(Helpers::fmtDate((string) ($project['end_date'] ?? ''))) ?></dd>
          <dt>Budget</dt><dd><?= Helpers::e(Helpers::money((string) $project['budget'])) ?></dd>
          <dt>Funding</dt><dd><?= $project['funding_source'] !== '' ? Helpers::e((string) $project['funding_source']) : '—' ?></dd>
          <dt>Target</dt><dd><?= $project['target_participants'] !== '' ? Helpers::e((string) $project['target_participants']) : '—' ?></dd>
          <dt>Description</dt><dd><?= Helpers::e(Helpers::excerpt((string) ($project['description'] ?? ''), 320)) ?></dd>
          <dt>Objectives</dt><dd><?= Helpers::e(Helpers::excerpt((string) ($project['objectives'] ?? ''), 320)) ?></dd>
          <dt>Adviser</dt><dd><?= Helpers::e((string) ($project['adviser_name'] ?? '—')) ?></dd>
        </dl>
      <?php endif; ?>
    </section>
  </div>

  <section class="card">
    <div class="card-head"><h3>Decision</h3></div>
    <?php if (!$openForDecision): ?>
      <p class="hint">This proposal is <?= Helpers::e(ui_status((string) $proposal['status'])) ?> — decisions can only be recorded
        while it is submitted or under review.</p>
    <?php else: ?>
      <form method="post">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="decide">
        <input type="hidden" name="proposal_id" value="<?= (int) $proposal['id'] ?>">
        <div class="grid cols-3">
          <?php foreach ($decisions as $value): ?>
            <label class="field-check">
              <input type="radio" name="decision" value="<?= Helpers::e($value) ?>"<?= $value === 'approved' ? ' checked' : '' ?>>
              <span><strong><?= Helpers::e(ui_status($value)) ?></strong>
                <span class="field-hint"><?= Helpers::e(match ($value) {
                    'approved' => 'Approves the proposal and notifies the organization.',
                    'rejected' => 'Closes the proposal with the comments below.',
                    default    => 'Sends it back to the organization for revision.',
                }) ?></span></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?= ui_textarea('comments', 'Comments to the organization', '', ['rows' => 4, 'placeholder' => 'Explain the decision — the organization and its adviser receive it.']) ?>
        <div class="form-actions">
          <button class="btn" type="submit" data-confirm="Record this decision?"><?= icon('check-circle', 16) ?><span>Record decision</span></button>
          <a class="btn grey" href="<?= Helpers::e(Helpers::url('admin/proposals.php')) ?>">Back to the list</a>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head">
      <h3>Comment history</h3>
      <span class="badge blue"><?= count($comments) ?></span>
    </div>
    <?php if ($comments === []): ?>
      <?= ui_empty('No reviewer comment yet.', 'Every submission, endorsement and decision is recorded here.', 'log') ?>
    <?php else: ?>
      <ul class="timeline">
        <?php foreach ($comments as $comment): ?>
          <li>
            <div class="tl-time"><?= Helpers::e(Helpers::fmtDateTime((string) $comment['created_at'])) ?>
              · <?= Helpers::e((string) ($comment['author_name'] ?? 'System')) ?>
              <?= $comment['author_role'] !== '' ? ' · ' . Helpers::e(ucwords(str_replace('_', ' ', (string) $comment['author_role']))) : '' ?></div>
            <div class="tl-title"><?= ui_badge(ui_status((string) $comment['decision']), ui_tone((string) $comment['decision'])) ?></div>
            <div class="tl-desc"><?= Helpers::e((string) ($comment['comments'] ?? '')) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

<?php else: ?>
  <div class="stat-grid">
    <?= ui_stat('Total proposals', $counts['total'], 'clipboard') ?>
    <?= ui_stat('Submitted', $counts['submitted'], 'clock', $counts['submitted'] > 0 ? 'amber' : 'grey') ?>
    <?= ui_stat('Under review', $counts['under_review'], 'eye', $counts['under_review'] > 0 ? 'amber' : 'grey') ?>
    <?= ui_stat('Approved', $counts['approved'], 'check-circle', $counts['approved'] > 0 ? '' : 'grey') ?>
    <?= ui_stat('Rejected', $counts['rejected'], 'close', $counts['rejected'] > 0 ? 'red' : 'grey') ?>
    <?= ui_stat('Revision required', $counts['revision_required'], 'refresh', $counts['revision_required'] > 0 ? 'blue' : 'grey') ?>
    <?= ui_stat('Implemented', $counts['implemented'], 'award', $counts['implemented'] > 0 ? '' : 'grey') ?>
    <?= ui_stat('Draft', $counts['draft'], 'doc', $counts['draft'] > 0 ? 'grey' : 'grey') ?>
  </div>

  <?= ui_filter_form('admin/proposals.php',
      ui_filter_input('q', 'Search', $filters['q'])
      . ui_filter_select('status', 'Status', $statusOptions, $filters['status'])
      . ui_filter_select('organization_id', 'Organization', $orgOptions, $filters['organization_id'])) ?>

  <section class="card">
    <div class="card-head">
      <h3>Proposals</h3>
      <span class="badge blue"><?= (int) $result['total'] ?> found</span>
    </div>
    <?php if ($rows === []): ?>
      <?= ui_empty('No proposal matches these filters.', 'Proposals are submitted by the organizations from their project pages.', 'clipboard') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>Proposal</th><th>Project</th><th>Organization</th>
              <th>Submitted by</th><th>Submitted at</th><th>Status</th><th class="actions">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td>
                  <strong><?= Helpers::e(Helpers::excerpt((string) $row['title'], 52)) ?></strong>
                  <span class="muted">v<?= (int) $row['current_version'] ?></span>
                </td>
                <td class="small"><?= Helpers::e(Helpers::excerpt((string) $row['project_title'], 44)) ?><span class="muted"><?= Helpers::e(Helpers::money((string) $row['project_budget'])) ?></span></td>
                <td class="small"><?= Helpers::e((string) $row['acronym']) ?><span class="muted"><?= Helpers::e(Helpers::excerpt((string) $row['organization_name'], 40)) ?></span></td>
                <td class="small"><?= Helpers::e((string) ($row['submitted_by_name'] ?? '—')) ?></td>
                <td class="small nowrap"><?= Helpers::e(Helpers::fmtDateTime((string) ($row['submitted_at'] ?? ''))) ?></td>
                <td><?= ui_status_badge((string) $row['status']) ?></td>
                <td class="actions">
                  <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/proposals.php?id=' . (int) $row['id'])) ?>">
                    <?= icon(in_array((string) $row['status'], ['submitted', 'under_review'], true) ? 'clipboard' : 'eye', 15) ?>
                    <span><?= in_array((string) $row['status'], ['submitted', 'under_review'], true) ? 'Review' : 'View' ?></span>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= ui_pagination($page, $result['pages'], $query) ?>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
