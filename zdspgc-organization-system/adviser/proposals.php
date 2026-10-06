<?php
/**
 * adviser/proposals.php — proposal monitoring and endorsement for adviser's organizations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('adviser');
Permissions::requireCapability('review_proposals');

$adviserId = Permissions::adviserId();
$orgIds    = Permissions::managedOrganizationIds();

/* ── Endorsement / Return POST ── */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action     = (string) (Helpers::post('action')      ?? '');
    $proposalId = (int)    (Helpers::post('proposal_id') ?? 0);
    $comments   = (string) (Helpers::post('comments')    ?? '');

    try {
        $proposal = ProposalRepo::find($proposalId);
        if ($proposal === null || !in_array((int)$proposal['organization_id'], $orgIds, true)) {
            throw new RuntimeException('Proposal not found or not in your scope.');
        }
        if ($action === 'endorse') {
            ProposalRepo::adviserEndorse($proposalId, (int) Auth::id(), $comments);
            Helpers::flash('success', 'Proposal endorsed and forwarded to the administrator.');
        } elseif ($action === 'return') {
            if ($comments === '') {
                throw new RuntimeException('Please provide comments explaining what needs revision.');
            }
            ProposalRepo::adviserReturn($proposalId, (int) Auth::id(), $comments);
            Helpers::flash('success', 'Proposal returned to the organization for revision.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }
    Helpers::redirect('adviser/proposals.php');
}

/* ── Read ── */
$q      = (string) (Helpers::get('q')       ?? '');
$orgId  = (int)    (Helpers::get('org_id')  ?? 0);
$status = (string) (Helpers::get('status')  ?? '');
$page   = max(1, (int) (Helpers::get('page') ?? 1));
$viewId = (int)    (Helpers::get('id')      ?? 0);

if ($orgId > 0 && !in_array($orgId, $orgIds, true)) {
    $orgId = 0;
}

$filters = [];
if ($q     !== '') $filters['q']               = $q;
if ($orgId  >  0)  $filters['organization_id'] = $orgId;
if ($status !== '') $filters['status']          = $status;
// Scope to adviser's orgs via a custom filter: we add org_ids
$result   = ProposalRepo::list($filters, $page, 20);
$proposals = array_values(array_filter($result['rows'], fn($r) => in_array((int)$r['organization_id'], $orgIds, true)));
$total    = count($proposals);
$pages    = $result['pages'];

$viewProposal = null;
if ($viewId > 0) {
    $viewProposal = ProposalRepo::find($viewId);
    if ($viewProposal !== null && !in_array((int)$viewProposal['organization_id'], $orgIds, true)) {
        $viewProposal = null;
    }
}

$orgOptions    = [];
foreach (OrgRepo::list(['adviser_id' => $adviserId], 1, 100)['rows'] as $org) {
    $orgOptions[(int) $org['id']] = (string) $org['name'];
}
$statusOptions = [
    'submitted'          => 'Submitted',
    'under_review'       => 'Under Review',
    'revision_required'  => 'Revision Required',
    'approved'           => 'Approved',
    'rejected'           => 'Rejected',
];
$queryStr = http_build_query(array_filter(['q' => $q, 'org_id' => $orgId ?: '', 'status' => $status]));

$PAGE_TITLE       = 'Proposal Monitoring';
$PAGE_ACTIVE      = 'proposals';
$PAGE_SUB         = 'Review project and activity proposals from your assigned organizations.';
$PAGE_BREADCRUMBS = [
    ['label' => 'Dashboard', 'href' => 'adviser/dashboard.php'],
    ['label' => 'Proposals'],
];
require __DIR__ . '/../includes/layout/header.php';
?>

<?php if ($orgIds === []): ?>
  <section class="card"><?= ui_empty('No organizations assigned yet.', '', 'clipboard') ?></section>
<?php else: ?>

<?php if ($viewProposal !== null): ?>
  <section class="card">
    <div class="card-head">
      <div>
        <h3><?= Helpers::e((string)$viewProposal['project_title']) ?></h3>
        <p class="sub muted small"><?= Helpers::e((string)$viewProposal['organization_name']) ?>
          · Submitted <?= Helpers::e(Helpers::fmtDate((string)$viewProposal['submitted_at'])) ?></p>
      </div>
      <div class="cluster">
        <?= ui_status_badge((string)$viewProposal['status']) ?>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/proposals.php')) ?>">
          <?= icon('close', 14) ?><span>Close</span>
        </a>
      </div>
    </div>

    <p><strong>Title:</strong> <?= Helpers::e((string)$viewProposal['project_title']) ?></p>
    <p><strong>Budget:</strong> ₱<?= number_format((float)($viewProposal['project_budget'] ?? 0), 2) ?></p>
    <?php if ((string)($viewProposal['project_start'] ?? '') !== ''): ?>
      <p><strong>Planned dates:</strong>
        <?= Helpers::e(Helpers::fmtDate((string)$viewProposal['project_start'])) ?>
        <?= $viewProposal['project_end'] ? ' — ' . Helpers::e(Helpers::fmtDate((string)$viewProposal['project_end'])) : '' ?></p>
    <?php endif; ?>
    <p><strong>Submitted by:</strong> <?= Helpers::e((string)($viewProposal['submitted_by_name'] ?? '—')) ?></p>
    <?php if ((string)($viewProposal['reviewer_name'] ?? '') !== ''): ?>
      <p><strong>Last reviewed by:</strong> <?= Helpers::e((string)$viewProposal['reviewer_name']) ?></p>
    <?php endif; ?>
    <?php if ((string)($viewProposal['review_comments'] ?? '') !== ''): ?>
      <div class="flash flash-info"><?= icon('info', 17) ?>
        <span><strong>Review comments:</strong> <?= Helpers::e((string)$viewProposal['review_comments']) ?></span>
      </div>
    <?php endif; ?>

    <?php if ((string)$viewProposal['status'] === 'submitted'): ?>
      <hr>
      <h4>Your Review</h4>
      <div class="grid cols-2">
        <form method="post">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="endorse">
          <input type="hidden" name="proposal_id" value="<?= (int)$viewProposal['id'] ?>">
          <?= ui_textarea('comments', 'Endorsement comments (optional)', '', ['rows' => '3']) ?>
          <button class="btn" type="submit"><?= icon('check-circle', 16) ?><span>Endorse proposal</span></button>
        </form>
        <form method="post">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="return">
          <input type="hidden" name="proposal_id" value="<?= (int)$viewProposal['id'] ?>">
          <?= ui_textarea('comments', 'Return comments (required)', '', ['rows' => '3', 'placeholder' => 'Explain what needs to be revised…']) ?>
          <button class="btn grey" type="submit"><?= icon('alert', 16) ?><span>Return for revision</span></button>
        </form>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?= ui_filter_form('adviser/proposals.php',
    ui_filter_input('q', 'Search', $q) .
    (count($orgOptions) > 1 ? ui_filter_select('org_id', 'Organization', $orgOptions, (string)$orgId, 'All organizations') : '') .
    ui_filter_select('status', 'Status', $statusOptions, $status, 'All statuses')
) ?>

<section class="card">
  <div class="card-head">
    <h3>Proposals</h3>
    <span class="badge grey"><?= $total ?></span>
  </div>

  <?php if ($proposals === []): ?>
    <?= ui_empty('No proposals found.', 'Try adjusting the filters or wait for organizations to submit.', 'clipboard') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Project / Activity</th>
            <th>Organization</th>
            <th>Submitted by</th>
            <th>Submitted</th>
            <th>Status</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($proposals as $p): ?>
            <tr>
              <td><strong><?= Helpers::e(Helpers::excerpt((string)$p['project_title'], 55)) ?></strong></td>
              <td class="small"><?= Helpers::e((string)$p['acronym']) ?></td>
              <td class="small"><?= Helpers::e((string)($p['submitted_by_name'] ?? '—')) ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string)$p['submitted_at'])) ?></td>
              <td><?= ui_status_badge((string)$p['status']) ?></td>
              <td class="actions">
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/proposals.php?id=' . (int)$p['id'])) ?>">
                  <?= icon((string)$p['status'] === 'submitted' ? 'external' : 'doc-check', 14) ?>
                  <span><?= (string)$p['status'] === 'submitted' ? 'Review' : 'View' ?></span>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= ui_pagination($page, $pages, $queryStr) ?>
  <?php endif; ?>
</section>

<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
