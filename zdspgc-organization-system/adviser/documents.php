<?php
/**
 * adviser/documents.php — document monitoring and verification for adviser's organizations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('adviser');
Permissions::requireCapability('review_documents');

$orgIds = Permissions::managedOrganizationIds();

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');
    $docId  = Helpers::postInt('document_id');

    try {
        if ($action === 'verify' && $docId > 0) {
            $doc = DocumentRepo::find($docId);
            if ($doc === null) {
                throw new RuntimeException('Document not found.');
            }
            if ($orgIds !== [] && !in_array((int) $doc['organization_id'], $orgIds, true)) {
                throw new RuntimeException('You are not authorized to verify documents for this organization.');
            }

            $decision = (string) (Helpers::post('decision') ?? '');
            if (!in_array($decision, ['approved', 'rejected', 'revision_required'], true)) {
                throw new RuntimeException('Choose a valid decision (approve, reject, or request revision).');
            }
            $remarks = trim((string) (Helpers::post('remarks') ?? ''));

            DocumentRepo::verify($docId, $decision, $remarks);

            Audit::log('ADVISER_DOCUMENT_DECISION', 'documents',
                'Adviser ' . Auth::user()['full_name'] . ' set document #' . $docId . ' to ' . $decision . ($remarks !== '' ? ': ' . $remarks : ''),
                $docId);

            Helpers::flash('success', 'Document verification recorded as ' . ui_status($decision) . '.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect($docId > 0 ? 'adviser/documents.php?id=' . $docId : 'adviser/documents.php');
}

$detailId = Helpers::getInt('id');

if ($detailId > 0) {
    $doc = DocumentRepo::find($detailId);
    if ($doc === null || ($orgIds !== [] && !in_array((int) $doc['organization_id'], $orgIds, true))) {
        Helpers::flash('error', 'That document no longer exists or you are not authorized to view it.');
        Helpers::redirect('adviser/documents.php');
    }

    $parent   = !empty($doc['parent_id']) ? DocumentRepo::find((int) $doc['parent_id']) : null;
    $orgLabel = (string) ($doc['organization_name'] ?? '');
    if ((string) ($doc['acronym'] ?? '') !== '') {
        $orgLabel .= ' (' . (string) $doc['acronym'] . ')';
    }

    $PAGE_TITLE       = (string) $doc['title'];
    $PAGE_ACTIVE      = 'documents';
    $PAGE_SUB         = 'Document review · ' . ui_status_badge((string) $doc['status']) . ' · Version ' . (int) $doc['version'];
    $PAGE_BREADCRUMBS = [
        ['label' => 'Dashboard', 'href' => 'adviser/dashboard.php'],
        ['label' => 'Documents', 'href' => 'adviser/documents.php'],
        ['label' => Helpers::excerpt((string) $doc['title'], 44)],
    ];
    $PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('adviser/documents.php')) . '">'
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
      <dd><?= Helpers::e($orgLabel) ?></dd>
      <dt>File name</dt><dd><code><?= Helpers::e((string) $doc['file_name']) ?></code></dd>
      <dt>File type</dt><dd><?= Helpers::e((string) ($doc['mime_type'] !== '' ? $doc['mime_type'] : 'unknown')) ?></dd>
      <dt>File size</dt><dd><?= Helpers::e(Helpers::fileSize((int) $doc['file_size'])) ?></dd>
      <dt>Version</dt><dd><?= (int) $doc['version'] ?></dd>
      <dt>Visibility</dt>
      <dd><?= (int) $doc['is_public'] === 1 ? ui_badge('Public', 'green') : ui_badge('Organization only', 'grey') ?></dd>
      <dt>Uploaded by</dt>
      <dd><?= !empty($doc['uploaded_by_name']) ? Helpers::e((string) $doc['uploaded_by_name']) : '<span class="muted">Unknown</span>' ?></dd>
      <dt>Uploaded at</dt><dd><?= Helpers::e(Helpers::fmtDateTime((string) $doc['uploaded_at'])) ?></dd>
      <?php if (!empty($doc['verified_by_name'])): ?>
        <dt>Last verified by</dt><dd><?= Helpers::e((string) $doc['verified_by_name']) ?> at <?= Helpers::e(Helpers::fmtDateTime((string) $doc['verified_at'])) ?></dd>
      <?php endif; ?>
      <?php if (!empty($doc['remarks'])): ?>
        <dt>Verification remarks</dt><dd><?= nl2br(Helpers::e((string) $doc['remarks'])) ?></dd>
      <?php endif; ?>
      <?php if ($parent !== null): ?>
        <dt>Replaces</dt>
        <dd><a href="<?= Helpers::e(Helpers::url('adviser/documents.php?id=' . (int) $parent['id'])) ?>"><?= Helpers::e(Helpers::excerpt((string) $parent['title'], 60)) ?></a></dd>
      <?php endif; ?>
    </dl>
    <div class="btn-row mt">
      <a class="btn" href="<?= Helpers::e(Helpers::url('download.php?id=' . (int) $doc['id'])) ?>">
        <?= icon('download', 16) ?><span>Download (<?= Helpers::e(Helpers::fileSize((int) $doc['file_size'])) ?>)</span>
      </a>
    </div>
  </section>

  <section class="card">
    <div class="card-head">
      <h3>Adviser Verification</h3>
      <?= ui_status_badge((string) $doc['status']) ?>
    </div>
    <form method="post" action="<?= Helpers::e(Helpers::url('adviser/documents.php?id=' . (int) $doc['id'])) ?>" class="stack">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="verify">
      <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">

      <div class="field">
        <label class="field-label">Verification decision</label>
        <div class="cluster">
          <label class="check">
            <input type="radio" name="decision" value="approved" <?= $doc['status'] === 'approved' ? 'checked' : '' ?>>
            <span>Approve document</span>
          </label>
          <label class="check">
            <input type="radio" name="decision" value="revision_required" <?= $doc['status'] === 'revision_required' ? 'checked' : '' ?>>
            <span>Request revision</span>
          </label>
          <label class="check">
            <input type="radio" name="decision" value="rejected" <?= $doc['status'] === 'rejected' ? 'checked' : '' ?>>
            <span>Reject document</span>
          </label>
        </div>
      </div>

      <div class="field">
        <label class="field-label" for="doc-remarks">Adviser comments / notes</label>
        <textarea id="doc-remarks" name="remarks" rows="4" placeholder="Add specific guidance or reasons for approval, revision, or rejection..."><?= Helpers::e((string) $doc['remarks']) ?></textarea>
      </div>

      <button class="btn" type="submit">
        <?= icon('check-circle', 16) ?><span>Save decision</span>
      </button>
    </form>
  </section>
</div>

    <?php
    require __DIR__ . '/../includes/layout/footer.php';
    exit;
}

$filters = [
    'q'              => (string) (Helpers::get('q') ?? ''),
    'organization_id' => Helpers::getInt('organization_id'),
    'document_type'  => (string) (Helpers::get('document_type') ?? ''),
    'status'         => (string) (Helpers::get('status') ?? ''),
];

if ($filters['organization_id'] <= 0 && count($orgIds) === 1) {
    $filters['organization_id'] = $orgIds[0];
}

$where = ['d.status <> "archived"'];
$params = [];

if ($orgIds !== []) {
    if ($filters['organization_id'] > 0 && in_array($filters['organization_id'], $orgIds, true)) {
        $where[] = 'd.organization_id = :org';
        $params['org'] = $filters['organization_id'];
    } else {
        $in = [];
        foreach ($orgIds as $i => $id) {
            $key = ':org' . $i;
            $in[] = $key;
            $params[$key] = $id;
        }
        $where[] = 'd.organization_id IN (' . implode(',', $in) . ')';
    }
} else {
    $where[] = '1=0';
}

if ($filters['q'] !== '') {
    $where[] = '(d.title LIKE :q OR d.file_name LIKE :q2)';
    $params['q'] = '%' . $filters['q'] . '%';
    $params['q2'] = '%' . $filters['q'] . '%';
}
if ($filters['document_type'] !== '') {
    $where[] = 'd.document_type = :dt';
    $params['dt'] = $filters['document_type'];
}
if ($filters['status'] !== '') {
    $where[] = 'd.status = :st';
    $params['st'] = $filters['status'];
}

$whereSql = implode(' AND ', $where);
$total = (int) Database::scalar(
    'SELECT COUNT(*) FROM documents d '
    . 'LEFT JOIN organizations o ON o.id = d.organization_id WHERE ' . $whereSql,
    $params
);

$page    = Helpers::page();
$perPage = 20;
$pages   = (int) max(1, ceil($total / $perPage));
$page    = min($page, $pages);

$rows = Database::all(
    'SELECT d.*, o.name AS organization_name, o.acronym, uu.full_name AS uploaded_by_name '
    . 'FROM documents d '
    . 'LEFT JOIN organizations o ON o.id = d.organization_id '
    . 'LEFT JOIN users uu ON uu.id = d.uploaded_by '
    . 'WHERE ' . $whereSql . ' '
    . 'ORDER BY FIELD(d.status, "pending","revision_required","approved","rejected"), d.uploaded_at DESC '
    . 'LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
    $params
);

$counts = ['pending' => 0, 'approved' => 0, 'revision_required' => 0, 'rejected' => 0];
if ($orgIds !== []) {
    $in = implode(',', array_map('intval', $orgIds));
    $statusRows = Database::all(
        "SELECT status, COUNT(*) AS c FROM documents WHERE organization_id IN ($in) GROUP BY status"
    );
    foreach ($statusRows as $sr) {
        $st = (string) $sr['status'];
        if (isset($counts[$st])) {
            $counts[$st] = (int) $sr['c'];
        }
    }
}

$orgOptions = [];
if ($orgIds !== []) {
    $in = implode(',', array_map('intval', $orgIds));
    $orgRows = Database::all("SELECT id, name, acronym FROM organizations WHERE id IN ($in) ORDER BY name");
    foreach ($orgRows as $o) {
        $label = (string) $o['name'];
        if (!empty($o['acronym'])) {
            $label .= ' (' . $o['acronym'] . ')';
        }
        $orgOptions[(int) $o['id']] = $label;
    }
}

$PAGE_TITLE       = 'Document Monitoring';
$PAGE_ACTIVE      = 'documents';
$PAGE_SUB         = 'Review and endorse organization documents submitted by student leaders';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'adviser/dashboard.php'], ['label' => 'Documents']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Pending Review', $counts['pending'], 'clock', $counts['pending'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Revision Needed', $counts['revision_required'], 'edit', $counts['revision_required'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Approved', $counts['approved'], 'check-circle', $counts['approved'] > 0 ? 'green' : 'grey') ?>
  <?= ui_stat('Rejected', $counts['rejected'], 'x-circle', $counts['rejected'] > 0 ? 'red' : 'grey') ?>
</div>

<section class="card">
  <form method="get" action="<?= Helpers::e(Helpers::url('adviser/documents.php')) ?>" class="filter-bar">
    <div class="filter-field">
      <input type="search" name="q" value="<?= Helpers::e($filters['q']) ?>" placeholder="Search document title or filename...">
    </div>
    <?php if (count($orgOptions) > 1): ?>
      <div class="filter-field">
        <select name="organization_id">
          <option value="">All assigned organizations</option>
          <?php foreach ($orgOptions as $id => $label): ?>
            <option value="<?= $id ?>" <?= $filters['organization_id'] === $id ? 'selected' : '' ?>><?= Helpers::e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="filter-field">
      <select name="status">
        <option value="">All statuses</option>
        <option value="pending" <?= $filters['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
        <option value="revision_required" <?= $filters['status'] === 'revision_required' ? 'selected' : '' ?>>Revision required</option>
        <option value="approved" <?= $filters['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
        <option value="rejected" <?= $filters['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
      </select>
    </div>
    <div class="filter-actions">
      <button class="btn sm" type="submit"><?= icon('search', 15) ?><span>Filter</span></button>
      <?php if (array_filter($filters)): ?>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/documents.php')) ?>"><?= icon('refresh', 15) ?><span>Reset</span></a>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($orgIds === []): ?>
    <?= ui_empty('No organizations assigned yet.', 'Documents submitted by your organizations will appear here once you are assigned as an adviser.', 'folder') ?>
  <?php elseif ($rows === []): ?>
    <?= ui_empty('No documents found.', 'No documents match your current filter settings.', 'folder') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Document</th>
            <th>Organization</th>
            <th>Type</th>
            <th>Version</th>
            <th>Submitted By</th>
            <th>Upload Date</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td>
                <strong>
                  <a href="<?= Helpers::e(Helpers::url('adviser/documents.php?id=' . (int) $row['id'])) ?>">
                    <?= Helpers::e((string) $row['title']) ?>
                  </a>
                </strong>
                <span class="muted small d-block"><?= Helpers::e((string) $row['file_name']) ?> · <?= Helpers::e(Helpers::fileSize((int) $row['file_size'])) ?></span>
              </td>
              <td><?= Helpers::e((string) ($row['acronym'] ?: $row['organization_name'])) ?></td>
              <td><?= ui_badge((string) $row['document_type'], 'blue') ?></td>
              <td>v<?= (int) $row['version'] ?></td>
              <td><?= Helpers::e((string) ($row['uploaded_by_name'] ?? '—')) ?></td>
              <td><?= Helpers::e(Helpers::fmtDate((string) $row['uploaded_at'])) ?></td>
              <td><?= ui_status_badge((string) $row['status']) ?></td>
              <td>
                <div class="btn-row">
                  <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/documents.php?id=' . (int) $row['id'])) ?>">
                    <?= icon('search', 14) ?><span>Review</span>
                  </a>
                  <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('download.php?id=' . (int) $row['id'])) ?>" title="Download">
                    <?= icon('download', 14) ?>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?= ui_pagination($page, $pages, http_build_query(array_filter($filters))) ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
