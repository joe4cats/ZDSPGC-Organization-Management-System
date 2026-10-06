<?php
/**
 * organization/proposals.php — activity proposal submission & tracking for officers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('officer');
Permissions::requireCapability('submit_proposals');

$org = Permissions::activeOrganization();
if ($org === null) {
    Helpers::flash('error', 'You must be assigned to an active organization.');
    Helpers::redirect('organization/dashboard.php');
}
$orgId = (int) $org['id'];

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action     = (string) (Helpers::post('action') ?? '');
    $proposalId = Helpers::postInt('proposal_id');

    try {
        if ($action === 'create') {
            $projectId = Helpers::postInt('project_id');
            $title     = trim((string) (Helpers::post('title') ?? ''));

            if ($projectId <= 0) {
                throw new RuntimeException('Select a project for this proposal.');
            }

            $id = ProposalRepo::create([
                'project_id'      => $projectId,
                'organization_id' => $orgId,
                'title'           => $title,
            ]);

            if (Helpers::inputBool('submit_now')) {
                ProposalRepo::submit($id);
                Helpers::flash('success', 'Proposal created and submitted to the adviser for endorsement.');
            } else {
                Helpers::flash('success', 'Proposal draft created.');
            }
        } elseif ($action === 'submit' && $proposalId > 0) {
            $prop = ProposalRepo::find($proposalId);
            if ($prop === null || (int) $prop['organization_id'] !== $orgId) {
                throw new RuntimeException('Proposal not found or unauthorized.');
            }
            ProposalRepo::submit($proposalId);
            Helpers::flash('success', 'Proposal submitted to the adviser for review.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect('organization/proposals.php');
}

$filters = [
    'q'               => (string) (Helpers::get('q') ?? ''),
    'status'          => (string) (Helpers::get('status') ?? ''),
    'organization_id' => $orgId,
];

$result = ProposalRepo::list($filters, Helpers::page(), 20);
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];

// Projects eligible for proposals
$projects = Database::all(
    'SELECT id, title FROM projects WHERE organization_id = :org ORDER BY created_at DESC',
    ['org' => $orgId]
);

$detailId = Helpers::getInt('id');
$detailProp = $detailId > 0 ? ProposalRepo::find($detailId) : null;
if ($detailProp !== null && (int) $detailProp['organization_id'] !== $orgId) {
    $detailProp = null;
}
$comments = $detailProp !== null ? ProposalRepo::comments((int) $detailProp['id']) : [];

$PAGE_TITLE       = 'Activity Proposals';
$PAGE_ACTIVE      = 'proposals';
$PAGE_SUB         = Helpers::e((string) $org['name']) . ' · Prepare and submit activity proposals for review';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'organization/dashboard.php'], ['label' => 'Proposals']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?php
  $pCounts = Database::all('SELECT status, COUNT(*) AS c FROM proposals WHERE organization_id = :o GROUP BY status', ['o' => $orgId]);
  $sc = [];
  foreach ($pCounts as $pc) { $sc[(string) $pc['status']] = (int) $pc['c']; }
  ?>
  <?= ui_stat('Drafts', $sc['draft'] ?? 0, 'edit', 'grey') ?>
  <?= ui_stat('Under Review', ($sc['submitted'] ?? 0) + ($sc['under_review'] ?? 0), 'clock', 'amber') ?>
  <?= ui_stat('Revisions Needed', $sc['revision_required'] ?? 0, 'alert', ($sc['revision_required'] ?? 0) > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Approved', $sc['approved'] ?? 0, 'check-circle', 'green') ?>
</div>

<?php if ($detailProp !== null): ?>
  <div class="grid cols-2 mb-4">
    <section class="card">
      <div class="card-head">
        <h3>Proposal Details</h3>
        <?= ui_status_badge((string) $detailProp['status']) ?>
      </div>
      <dl class="detail-list">
        <dt>Proposal Title</dt><dd><strong><?= Helpers::e((string) $detailProp['title']) ?></strong></dd>
        <dt>Project</dt><dd><?= Helpers::e((string) $detailProp['project_title']) ?></dd>
        <dt>Budget</dt><dd>₱<?= number_format((float) ($detailProp['project_budget'] ?? 0), 2) ?></dd>
        <dt>Version</dt><dd>v<?= (int) $detailProp['current_version'] ?></dd>
        <dt>Submitted by</dt><dd><?= Helpers::e((string) ($detailProp['submitted_by_name'] ?? 'Officer')) ?></dd>
        <dt>Submitted at</dt><dd><?= Helpers::e(Helpers::fmtDateTime((string) $detailProp['submitted_at'])) ?></dd>
        <?php if (!empty($detailProp['reviewer_comments'])): ?>
          <dt>Reviewer comments</dt><dd><?= nl2br(Helpers::e((string) $detailProp['reviewer_comments'])) ?></dd>
        <?php endif; ?>
      </dl>
      <?php if (in_array((string) $detailProp['status'], ['draft', 'revision_required'], true)): ?>
        <div class="btn-row mt">
          <form method="post" action="<?= Helpers::e(Helpers::url('organization/proposals.php')) ?>" class="inline">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="submit">
            <input type="hidden" name="proposal_id" value="<?= (int) $detailProp['id'] ?>">
            <button class="btn" type="submit"><?= icon('send', 15) ?><span>Submit to Adviser</span></button>
          </form>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head">
        <h3>Review Trail & History</h3>
      </div>
      <?php if ($comments === []): ?>
        <?= ui_empty('No comments recorded yet.', 'Review activity from advisers and administrators will appear here.', 'message-square') ?>
      <?php else: ?>
        <ul class="timeline">
          <?php foreach ($comments as $c): ?>
            <li>
              <div class="tl-time"><?= Helpers::e(Helpers::fmtDateTime((string) $c['created_at'])) ?></div>
              <div class="tl-title"><?= Helpers::e((string) $c['user_name']) ?> <span class="badge grey"><?= Helpers::e((string) $c['action']) ?></span></div>
              <div class="tl-desc"><?= nl2br(Helpers::e((string) $c['comment'])) ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
<?php endif; ?>

<section class="card">
  <div class="card-head">
    <h3>Submitted Activity Proposals</h3>
    <button class="btn sm" type="button" onclick="document.getElementById('modal-new-proposal').showModal()">
      <?= icon('plus', 15) ?><span>New Proposal</span>
    </button>
  </div>

  <form method="get" action="<?= Helpers::e(Helpers::url('organization/proposals.php')) ?>" class="filter-bar">
    <div class="filter-field">
      <input type="search" name="q" value="<?= Helpers::e($filters['q']) ?>" placeholder="Search proposals...">
    </div>
    <div class="filter-field">
      <select name="status">
        <option value="">All statuses</option>
        <option value="draft" <?= $filters['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
        <option value="submitted" <?= $filters['status'] === 'submitted' ? 'selected' : '' ?>>Submitted</option>
        <option value="under_review" <?= $filters['status'] === 'under_review' ? 'selected' : '' ?>>Under review</option>
        <option value="revision_required" <?= $filters['status'] === 'revision_required' ? 'selected' : '' ?>>Revision required</option>
        <option value="approved" <?= $filters['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
        <option value="rejected" <?= $filters['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
      </select>
    </div>
    <div class="filter-actions">
      <button class="btn sm" type="submit"><?= icon('search', 15) ?><span>Filter</span></button>
      <?php if (array_filter($filters)): ?>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization/proposals.php')) ?>"><?= icon('refresh', 15) ?><span>Reset</span></a>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($rows === []): ?>
    <?= ui_empty('No proposals found.', 'Create a new proposal to start the approval workflow for your projects.', 'clipboard') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Proposal Title</th>
            <th>Project</th>
            <th>Budget</th>
            <th>Version</th>
            <th>Submitted At</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td>
                <strong>
                  <a href="<?= Helpers::e(Helpers::url('organization/proposals.php?id=' . (int) $row['id'])) ?>">
                    <?= Helpers::e((string) $row['title']) ?>
                  </a>
                </strong>
              </td>
              <td><?= Helpers::e((string) $row['project_title']) ?></td>
              <td>₱<?= number_format((float) ($row['project_budget'] ?? 0), 2) ?></td>
              <td>v<?= (int) $row['current_version'] ?></td>
              <td><?= Helpers::e(Helpers::fmtDate((string) $row['submitted_at'])) ?></td>
              <td><?= ui_status_badge((string) $row['status']) ?></td>
              <td>
                <div class="btn-row">
                  <a class="btn xs ghost" href="<?= Helpers::e(Helpers::url('organization/proposals.php?id=' . (int) $row['id'])) ?>">
                    <?= icon('search', 13) ?><span>Details</span>
                  </a>
                  <?php if (in_array((string) $row['status'], ['draft', 'revision_required'], true)): ?>
                    <form method="post" action="<?= Helpers::e(Helpers::url('organization/proposals.php')) ?>" class="inline">
                      <?= Security::csrfField() ?>
                      <input type="hidden" name="action" value="submit">
                      <input type="hidden" name="proposal_id" value="<?= (int) $row['id'] ?>">
                      <button class="btn xs" type="submit"><?= icon('send', 12) ?><span>Submit</span></button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?= ui_pagination($page, (int) $result['pages'], http_build_query(array_filter($filters))) ?>
  <?php endif; ?>
</section>

<!-- Modal: New Proposal -->
<dialog id="modal-new-proposal" class="modal-box">
  <div class="card p-4">
    <div class="card-head">
      <h3>Submit Activity Proposal</h3>
      <button type="button" class="btn xs ghost" onclick="document.getElementById('modal-new-proposal').close()">✕</button>
    </div>
    <form method="post" action="<?= Helpers::e(Helpers::url('organization/proposals.php')) ?>" class="stack mt">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="create">

      <div class="field">
        <label class="field-label" for="prop-proj">Associated Project / Activity</label>
        <select id="prop-proj" name="project_id" required>
          <option value="">Select project</option>
          <?php foreach ($projects as $pr): ?>
            <option value="<?= $pr['id'] ?>"><?= Helpers::e((string) $pr['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="field-hint">If your activity is not listed, add it in the Projects tab first.</span>
      </div>

      <?= ui_input('title', 'Proposal Title', '', ['placeholder' => 'e.g. Official Proposal for TechFest 2026'], true) ?>

      <label class="check">
        <input type="checkbox" name="submit_now" value="1" checked>
        <span>Submit immediately to adviser for endorsement</span>
      </label>

      <div class="btn-row mt">
        <button class="btn" type="submit"><?= icon('send', 16) ?><span>Save Proposal</span></button>
        <button class="btn ghost" type="button" onclick="document.getElementById('modal-new-proposal').close()">Cancel</button>
      </div>
    </form>
  </div>
</dialog>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
