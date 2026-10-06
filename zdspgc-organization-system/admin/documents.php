<?php
/**
 * admin/documents.php — review the files organizations upload.
 *
 * The list gives per-status counters, filters (search, organization, document
 * type and status) and a paginated table. The detail view opened with ?id=N
 * shows the stored file metadata, the remarks, the verification history and
 * the approve / reject / revision decision form, plus the archive action.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('review_documents');

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');
    $docId  = Helpers::postInt('document_id');

    try {
        if ($action === 'verify' && $docId > 0) {
            $decision = (string) (Helpers::post('decision') ?? '');
            if (!in_array($decision, ['approved', 'rejected', 'revision_required'], true)) {
                throw new RuntimeException('Choose the decision to record for this document.');
            }
            DocumentRepo::verify($docId, $decision, (string) (Helpers::post('remarks') ?? ''));
            Helpers::flash('success', 'Decision saved as ' . ui_status($decision) . '.');
        } elseif ($action === 'archive' && $docId > 0) {
            DocumentRepo::archive($docId);
            Helpers::flash('success', 'The document was archived.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect($docId > 0 ? 'admin/documents.php?id=' . $docId : 'admin/documents.php');
}

$detailId = Helpers::getInt('id');

if ($detailId > 0) {
    $doc = DocumentRepo::find($detailId);
    if ($doc === null) {
        Helpers::flash('error', 'That document no longer exists.');
        Helpers::redirect('admin/documents.php');
    }

    $parent   = !empty($doc['parent_id']) ? DocumentRepo::find((int) $doc['parent_id']) : null;
    $orgLabel = '';
    if (!empty($doc['organization_name'])) {
        $orgLabel = (string) $doc['organization_name'];
        if ((string) ($doc['acronym'] ?? '') !== '') {
            $orgLabel .= ' (' . (string) $doc['acronym'] . ')';
        }
    }

    $PAGE_TITLE       = (string) $doc['title'];
    $PAGE_ACTIVE      = 'documents';
    $PAGE_SUB         = 'Document review · ' . ui_status_badge((string) $doc['status'])
        . ' · Version ' . (int) $doc['version'];
    $PAGE_BREADCRUMBS = [
        ['label' => 'Dashboard', 'href' => 'admin/dashboard.php'],
        ['label' => 'Documents', 'href' => 'admin/documents.php'],
        ['label' => Helpers::excerpt((string) $doc['title'], 44)],
    ];
    $PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('admin/documents.php')) . '">'
        . icon('chevron-left', 16) . '<span>All documents</span></a>'
        . '<a class="btn" href="' . Helpers::e(Helpers::url('download.php?id=' . (int) $doc['id'])) . '">'
        . icon('download', 16) . '<span>Download file</span></a>';

    require __DIR__ . '/../includes/layout/header.php';
    ?>

<div class="grid cols-2">
  <section class="card">
    <div class="card-head">
      <h3>File details</h3>
      <?= ui_badge((string) $doc['document_type'], 'blue') ?>
    </div>
    <dl class="detail-list">
      <dt>Organization</dt>
      <dd><?php if ($orgLabel !== ''): ?><?= Helpers::e($orgLabel) ?><?php else: ?><span class="muted">Not linked to an organization</span><?php endif; ?></dd>
      <dt>File name</dt><dd><code><?= Helpers::e((string) $doc['file_name']) ?></code></dd>
      <dt>File type</dt><dd><?= Helpers::e((string) ($doc['mime_type'] !== '' ? $doc['mime_type'] : 'unknown')) ?></dd>
      <dt>File size</dt><dd><?= Helpers::e(Helpers::fileSize((int) $doc['file_size'])) ?></dd>
      <dt>Version</dt><dd><?= (int) $doc['version'] ?></dd>
      <dt>Visibility</dt>
      <dd><?= (int) $doc['is_public'] === 1 ? ui_badge('Public', 'green') : ui_badge('Organization only', 'grey') ?></dd>
      <dt>Uploaded by</dt>
      <dd><?= !empty($doc['uploaded_by_name']) ? Helpers::e((string) $doc['uploaded_by_name']) : '<span class="muted">Unknown</span>' ?></dd>
      <dt>Uploaded at</dt><dd><?= Helpers::e(Helpers::fmtDateTime((string) $doc['uploaded_at'])) ?></dd>
      <?php if ($parent !== null): ?>
        <dt>Replaces</dt>
        <dd><a href="<?= Helpers::e(Helpers::url('admin/documents.php?id=' . (int) $parent['id'])) ?>"><?= Helpers::e(Helpers::excerpt((string) $parent['title'], 60)) ?></a></dd>
      <?php endif; ?>
    </dl>
    <div class="btn-row mt">
      <a class="btn" href="<?= Helpers::e(Helpers::url('download.php?id=' . (int) $doc['id'])) ?>">
        <?= icon('download', 16) ?><span>Download</span>
      </a>
    </div>
  </section>

  <section class="card">
    <div class="card-head">
      <h3>Verification history</h3>
      <?= ui_status_badge((string) $doc['status']) ?>
    </div>
    <dl class="detail-list">
      <dt>Current remarks</dt>
      <dd><?php if ((string) $doc['remarks'] !== ''): ?><?= Helpers::e((string) $doc['remarks']) ?><?php else: ?><span class="muted">No remarks recorded yet.</span><?php endif; ?></dd>
      <dt>Verified by</dt>
      <dd><?php if (!empty($doc['verified_by_name'])): ?><?= Helpers::e((string) $doc['verified_by_name']) ?><?php else: ?><span class="muted">Not verified yet</span><?php endif; ?></dd>
      <dt>Verified at</dt>
      <dd><?php if (!empty($doc['verified_at'])): ?><?= Helpers::e(Helpers::fmtDateTime((string) $doc['verified_at'])) ?><?php else: ?><span class="muted">—</span><?php endif; ?></dd>
      <dt>Archived at</dt>
      <dd><?php if (!empty($doc['archived_at'])): ?><?= Helpers::e(Helpers::fmtDateTime((string) $doc['archived_at'])) ?><?php else: ?><span class="muted">Not archived</span><?php endif; ?></dd>
      <dt>Last decision</dt>
      <dd><?= in_array((string) $doc['status'], AcademicRepo::DOCUMENT_STATUSES, true)
            ? ui_status_badge((string) $doc['status'])
            : ui_status_badge('pending') ?></dd>
    </dl>
  </section>
</div>

<section class="card">
  <div class="card-head">
    <h3>Verification decision</h3>
    <?php if ((string) $doc['status'] !== 'archived'): ?>
      <form method="post" class="inline" data-confirm="Archive <?= Helpers::e(Helpers::excerpt((string) $doc['title'], 40)) ?>? It will be kept as a record but hidden from the organization.">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="archive">
        <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">
        <button class="btn sm danger" type="submit"><?= icon('trash', 15) ?><span>Archive</span></button>
      </form>
    <?php endif; ?>
  </div>

  <form method="post">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="verify">
    <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">
    <?= ui_select(
        'decision',
        'Decision',
        [
            'approved'          => 'Approve this document',
            'revision_required' => 'Ask the organization for a revision',
            'rejected'          => 'Reject this document',
        ],
        in_array((string) $doc['status'], ['approved', 'rejected', 'revision_required'], true) ? (string) $doc['status'] : '',
        ['placeholder' => 'Choose a decision…'],
        true
    ) ?>
    <?= ui_textarea('remarks', 'Remarks', (string) $doc['remarks'], [
        'rows'  => 4,
        'hint'  => 'Stored on the document record and shared with the organization together with the decision.',
    ]) ?>
    <div class="form-actions">
      <button class="btn" type="submit" data-confirm="Save this verification decision for <?= Helpers::e(Helpers::excerpt((string) $doc['title'], 40)) ?>?">
        <?= icon('check', 16) ?><span>Save decision</span>
      </button>
    </div>
  </form>
</section>

    <?php
    require __DIR__ . '/../includes/layout/footer.php';
    return;
}

$filters = [
    'q'              => (string) (Helpers::get('q') ?? ''),
    'organization_id' => Helpers::getInt('organization_id'),
    'document_type'  => (string) (Helpers::get('document_type') ?? ''),
    'status'         => (string) (Helpers::get('status') ?? ''),
];

$result = DocumentRepo::list($filters, Helpers::page(), 20);
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];
$query  = http_build_query(array_filter($filters));

$counts      = DocumentRepo::statusCounts();
$orgOptions  = [];
foreach (OrgRepo::list([], 1, 200)['rows'] as $org) {
    $code = (string) ($org['acronym'] ?? '');
    $orgOptions[(int) $org['id']] = ($code !== '' ? $code . ' — ' : '') . (string) $org['name'];
}
$typeOptions = [];
foreach (AcademicRepo::allDocumentTypes() as $type) {
    $typeOptions[(string) $type['name']] = (string) $type['name'];
}
$statusOptions = [];
foreach (AcademicRepo::DOCUMENT_STATUSES as $status) {
    $statusOptions[$status] = ucwords(str_replace('_', ' ', $status));
}

$filterFields = ui_filter_input('q', 'Search', $filters['q'])
    . ui_filter_select('organization_id', 'Organization', $orgOptions,
        $filters['organization_id'] > 0 ? (string) $filters['organization_id'] : '')
    . ui_filter_select('document_type', 'Document type', $typeOptions, $filters['document_type'])
    . ui_filter_select('status', 'Status', $statusOptions, $filters['status']);

$PAGE_TITLE       = 'Documents';
$PAGE_ACTIVE      = 'documents';
$PAGE_SUB         = $counts['total'] . ' document(s) on record · '
    . $counts['pending'] . ' waiting for verification · ' . $result['total'] . ' matching the current filters';
$PAGE_BREADCRUMBS = [
    ['label' => 'Dashboard', 'href' => 'admin/dashboard.php'],
    ['label' => 'Documents'],
];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Total documents', $counts['total'], 'folder') ?>
  <?= ui_stat('Pending verification', $counts['pending'], 'clock', $counts['pending'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Approved', $counts['approved'], 'check-circle') ?>
  <?= ui_stat('Revision required', $counts['revision_required'], 'refresh', $counts['revision_required'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Rejected', $counts['rejected'], 'alert', $counts['rejected'] > 0 ? 'red' : 'grey') ?>
  <?= ui_stat('Archived', $counts['archived'], 'folder', 'grey') ?>
</div>

<?= ui_filter_form('admin/documents.php', $filterFields, 'Apply filters') ?>

<section class="card">
  <div class="card-head">
    <h3>Uploaded documents</h3>
    <span class="badge"><?= (int) $result['total'] ?> found</span>
  </div>

  <?php if ($rows === []): ?>
    <?= ui_empty('No document matches these filters.', 'Clear the filters to see every file the organizations have uploaded.', 'folder') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Document</th><th>Type</th><th>Organization</th><th>Uploaded by</th>
            <th>Uploaded</th><th class="num">Version</th><th>Status</th><th class="num">Size</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr<?= (string) $row['status'] === 'archived' ? ' class="is-archived"' : '' ?>>
              <td>
                <strong><?= Helpers::e(Helpers::excerpt((string) $row['title'], 60)) ?></strong>
                <span class="muted"><?= Helpers::e((string) $row['file_name']) ?></span>
              </td>
              <td><?= ui_badge((string) $row['document_type'], 'blue') ?></td>
              <td class="small">
                <?php if (!empty($row['organization_name'])): ?>
                  <?= Helpers::e(Helpers::excerpt((string) $row['organization_name'], 34)) ?>
                  <?php if ((string) ($row['acronym'] ?? '') !== ''): ?><span class="muted"><?= Helpers::e((string) $row['acronym']) ?></span><?php endif; ?>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td class="small"><?= !empty($row['uploaded_by_name']) ? Helpers::e(Helpers::excerpt((string) $row['uploaded_by_name'], 26)) : '<span class="muted">—</span>' ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDateTime((string) $row['uploaded_at'])) ?></td>
              <td class="num"><?= (int) $row['version'] ?></td>
              <td><?= ui_status_badge((string) $row['status']) ?></td>
              <td class="num nowrap small"><?= Helpers::e(Helpers::fileSize((int) $row['file_size'])) ?></td>
              <td class="actions">
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/documents.php?id=' . (int) $row['id'])) ?>">
                  <?= icon('eye', 15) ?><span>Review</span>
                </a>
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('download.php?id=' . (int) $row['id'])) ?>">
                  <?= icon('download', 15) ?><span>Download</span>
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

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
