<?php
/**
 * organization/documents.php — organization document submissions & accreditation compliance.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('officer');
Permissions::requireCapability('manage_documents');

$org = Permissions::activeOrganization();
if ($org === null) {
    Helpers::flash('error', 'You must be assigned to an active organization.');
    Helpers::redirect('organization/dashboard.php');
}
$orgId = (int) $org['id'];

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');

    try {
        if ($action === 'upload') {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Please select a file to upload.');
            }

            $title   = trim((string) (Helpers::post('title') ?? ''));
            $docType = (string) (Helpers::post('document_type') ?? 'general');

            $upload = Uploads::document($_FILES['file'], $orgId);
            if (!$upload['ok']) {
                throw new RuntimeException((string) $upload['message']);
            }

            DocumentRepo::create([
                'organization_id' => $orgId,
                'document_type'   => $docType,
                'title'           => $title !== '' ? $title : (string) $upload['original'],
                'file'            => $upload,
                'is_public'       => Helpers::inputBool('is_public') ? 1 : 0,
            ]);

            Helpers::flash('success', 'Document uploaded successfully and queued for review.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect('organization/documents.php');
}

$filters = [
    'organization_id' => $orgId,
    'document_type'   => (string) (Helpers::get('document_type') ?? ''),
    'status'          => (string) (Helpers::get('status') ?? ''),
    'q'               => (string) (Helpers::get('q') ?? ''),
];

$result = DocumentRepo::list(array_filter($filters), Helpers::page(), 20);
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];

// Document types from required_document_types
$docTypes = Database::all('SELECT * FROM required_document_types WHERE status = "active" ORDER BY sort_order');

$PAGE_TITLE       = 'Organization Documents';
$PAGE_ACTIVE      = 'documents';
$PAGE_SUB         = Helpers::e((string) $org['name']) . ' · Upload and manage official documents and reports';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'organization/dashboard.php'], ['label' => 'Documents']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="grid side-main">
  <section class="card">
    <div class="card-head">
      <h3>Upload Document</h3>
      <span class="muted small">PDF, Word, or Excel</span>
    </div>

    <form method="post" action="<?= Helpers::e(Helpers::url('organization/documents.php')) ?>" enctype="multipart/form-data" class="stack">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="upload">

      <div class="field">
        <label class="field-label" for="doc-type">Document Category</label>
        <select id="doc-type" name="document_type" required>
          <?php foreach ($docTypes as $dt): ?>
            <option value="<?= Helpers::e((string) $dt['name']) ?>"><?= Helpers::e((string) $dt['name']) ?></option>
          <?php endforeach; ?>
          <option value="Other">Other / General File</option>
        </select>
      </div>

      <?= ui_input('title', 'Document Title', '', ['placeholder' => 'e.g. AY 2026-2027 Constitution and By-Laws'], true) ?>

      <div class="field">
        <label class="field-label" for="doc-file">Select File</label>
        <input id="doc-file" type="file" name="file" accept=".pdf,.doc,.docx,.xls,.xlsx" required>
        <span class="field-hint">Maximum <?= UPLOAD_MAX_MB ?> MB. Supported formats: PDF, DOC, DOCX, XLS, XLSX.</span>
      </div>

      <label class="check">
        <input type="checkbox" name="is_public" value="1">
        <span>Visible on public organization profile</span>
      </label>

      <button class="btn" type="submit"><?= icon('upload', 16) ?><span>Upload Document</span></button>
    </form>
  </section>

  <section class="card">
    <div class="card-head">
      <h3>Document Repository</h3>
      <span class="muted small"><?= $result['total'] ?> document(s)</span>
    </div>

    <form method="get" action="<?= Helpers::e(Helpers::url('organization/documents.php')) ?>" class="filter-bar">
      <div class="filter-field">
        <input type="search" name="q" value="<?= Helpers::e($filters['q']) ?>" placeholder="Search documents...">
      </div>
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
      </div>
    </form>

    <?php if ($rows === []): ?>
      <?= ui_empty('No documents uploaded yet.', 'Submit official constitution, officer rosters, plans, and reports here.', 'folder') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>Document Title</th>
              <th>Category</th>
              <th>Version</th>
              <th>Upload Date</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td>
                  <strong><?= Helpers::e((string) $row['title']) ?></strong>
                  <span class="muted small d-block">
                    <?= Helpers::e((string) $row['file_name']) ?> · <?= Helpers::e(Helpers::fileSize((int) $row['file_size'])) ?>
                  </span>
                  <?php if (!empty($row['remarks'])): ?>
                    <span class="muted small d-block" style="color: var(--amber-700); margin-top: 4px;">
                      <em>Note: <?= Helpers::e((string) $row['remarks']) ?></em>
                    </span>
                  <?php endif; ?>
                </td>
                <td><?= ui_badge((string) $row['document_type'], 'blue') ?></td>
                <td>v<?= (int) $row['version'] ?></td>
                <td><?= Helpers::e(Helpers::fmtDate((string) $row['uploaded_at'])) ?></td>
                <td><?= ui_status_badge((string) $row['status']) ?></td>
                <td>
                  <a class="btn xs ghost" href="<?= Helpers::e(Helpers::url('download.php?id=' . (int) $row['id'])) ?>">
                    <?= icon('download', 14) ?><span>Download</span>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?= ui_pagination($page, (int) $result['pages'], http_build_query(array_filter($filters))) ?>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
