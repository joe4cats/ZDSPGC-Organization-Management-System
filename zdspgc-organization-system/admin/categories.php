<?php
/**
 * admin/categories.php — manage organization categories.
 *
 * Admins can create, edit, activate/deactivate and safely delete categories.
 * Deletion is blocked when the category is still used by organizations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('manage_categories');

/* ── POST actions ── */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');
    $id     = (int)    (Helpers::post('id')     ?? 0);

    try {
        if ($action === 'create' || $action === 'edit') {
            $name        = trim((string) (Helpers::post('name')        ?? ''));
            $description = trim((string) (Helpers::post('description') ?? ''));
            $status      = in_array(Helpers::post('status'), ['active', 'inactive'], true)
                         ? (string) Helpers::post('status') : 'active';

            if ($name === '') {
                throw new RuntimeException('Category name is required.');
            }

            // Duplicate name check
            $dupSql = 'SELECT id FROM organization_categories WHERE LOWER(name) = LOWER(:n)';
            $dupPar = ['n' => $name];
            if ($action === 'edit') {
                $dupSql .= ' AND id <> :id';
                $dupPar['id'] = $id;
            }
            if (Database::scalar($dupSql, $dupPar) !== null) {
                throw new RuntimeException('A category with that name already exists.');
            }

            $row = ['name' => $name, 'description' => $description, 'status' => $status];

            if ($action === 'create') {
                $row['created_at'] = Helpers::now();
                Database::insert('organization_categories', $row);
                Helpers::flash('success', 'Category "' . $name . '" was created.');
            } else {
                if ($id < 1) {
                    throw new RuntimeException('No category selected for editing.');
                }
                Database::update('organization_categories', $row, 'id = :id', ['id' => $id]);
                Helpers::flash('success', 'Category "' . $name . '" was updated.');
            }

        } elseif ($action === 'toggle') {
            $cat = Database::one('SELECT * FROM organization_categories WHERE id = :id', ['id' => $id]);
            if ($cat === null) {
                throw new RuntimeException('Category not found.');
            }
            $newStatus = (string) $cat['status'] === 'active' ? 'inactive' : 'active';
            Database::update('organization_categories', ['status' => $newStatus], 'id = :id', ['id' => $id]);
            Helpers::flash('success', 'Category status changed to ' . $newStatus . '.');

        } elseif ($action === 'delete') {
            $inUse = (int) Database::scalar(
                'SELECT COUNT(*) FROM organizations WHERE category_id = :id', ['id' => $id]
            );
            if ($inUse > 0) {
                throw new RuntimeException(
                    'Cannot delete — ' . $inUse . ' organization(s) are using this category. '
                    . 'Deactivate it instead.'
                );
            }
            Database::delete('organization_categories', 'id = :id', ['id' => $id]);
            Helpers::flash('success', 'Category deleted.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect('admin/categories.php');
}

/* ── Read ── */
$q      = (string) (Helpers::get('q')    ?? '');
$status = (string) (Helpers::get('status') ?? '');
$editId = (int)    (Helpers::get('edit')   ?? 0);

$sql    = 'SELECT c.*, (SELECT COUNT(*) FROM organizations o WHERE o.category_id = c.id) AS org_count
           FROM organization_categories c WHERE 1=1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (c.name LIKE :q OR c.description LIKE :q2)';
    $params['q'] = '%' . $q . '%';
    $params['q2'] = '%' . $q . '%';
}
if ($status !== '') {
    $sql .= ' AND c.status = :s';
    $params['s'] = $status;
}
$sql .= ' ORDER BY c.name ASC';
$categories = Database::all($sql, $params);

$editing = null;
if ($editId > 0) {
    $editing = Database::one('SELECT * FROM organization_categories WHERE id = :id', ['id' => $editId]);
}

$PAGE_TITLE       = 'Organization Categories';
$PAGE_ACTIVE      = 'categories';
$PAGE_SUB         = 'Classify organizations by type. Used on registration forms and the public directory.';
$PAGE_ACTIONS     = '<a class="btn" href="#cat-form">' . icon('plus', 16) . '<span>Add category</span></a>';
$PAGE_BREADCRUMBS = [
    ['label' => 'Dashboard',   'href' => 'admin/dashboard.php'],
    ['label' => 'Configuration'],
    ['label' => 'Categories'],
];
require __DIR__ . '/../includes/layout/header.php';
?>

<?= ui_filter_form('admin/categories.php',
    ui_filter_input('q', 'Search', $q) .
    ui_filter_select('status', 'Status', ['active' => 'Active', 'inactive' => 'Inactive'], $status, 'All statuses')
) ?>

<div class="grid cols-2">
  <!-- ── Category list ── -->
  <section class="card">
    <div class="card-head">
      <div>
        <h3>Categories</h3>
        <p class="sub muted small"><?= count($categories) ?> result(s)</p>
      </div>
      <span class="badge blue"><?= count($categories) ?></span>
    </div>

    <?php if ($categories === []): ?>
      <?= ui_empty('No categories found.', 'Add the first category or clear the search filter.', 'layers') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>Name</th>
              <th>Description</th>
              <th class="num">Organizations</th>
              <th>Status</th>
              <th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($categories as $cat): ?>
              <tr>
                <td><strong><?= Helpers::e((string) $cat['name']) ?></strong></td>
                <td class="small muted"><?= Helpers::e(Helpers::excerpt((string) $cat['description'], 60)) ?></td>
                <td class="num small"><?= (int) $cat['org_count'] ?></td>
                <td><?= ui_status_badge((string) $cat['status']) ?></td>
                <td class="actions">
                  <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/categories.php?edit=' . (int) $cat['id'])) ?>">
                    <?= icon('edit', 14) ?><span>Edit</span>
                  </a>
                  <form method="post" class="inline-form">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                    <button class="btn sm grey" type="submit" title="Toggle active/inactive">
                      <?= icon((string)$cat['status'] === 'active' ? 'close' : 'check', 14) ?>
                      <span><?= (string)$cat['status'] === 'active' ? 'Deactivate' : 'Activate' ?></span>
                    </button>
                  </form>
                  <?php if ((int) $cat['org_count'] === 0): ?>
                    <form method="post" class="inline-form"
                          onsubmit="return confirm('Delete category «<?= Helpers::e((string) $cat['name']) ?>»? This cannot be undone.')">
                      <?= Security::csrfField() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                      <button class="btn sm danger" type="submit"><?= icon('trash', 14) ?><span>Delete</span></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <!-- ── Add / Edit form ── -->
  <section class="card" id="cat-form">
    <div class="card-head">
      <h3><?= $editing !== null ? 'Edit: ' . Helpers::e((string) $editing['name']) : 'Add a new category' ?></h3>
      <?php if ($editing !== null): ?><?= ui_status_badge((string) $editing['status']) ?><?php endif; ?>
    </div>

    <form method="post">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="<?= $editing !== null ? 'edit' : 'create' ?>">
      <input type="hidden" name="id"     value="<?= (int) ($editing['id'] ?? 0) ?>">

      <?= ui_input('name', 'Category name', (string) ($editing['name'] ?? ''),
          ['placeholder' => 'e.g. Academic, Sports, Cultural'], true) ?>
      <?= ui_textarea('description', 'Description', (string) ($editing['description'] ?? ''),
          ['rows' => '3', 'placeholder' => 'Brief description of this category type']) ?>
      <?= ui_select('status', 'Status', ['active' => 'Active', 'inactive' => 'Inactive'],
          (string) ($editing['status'] ?? 'active'), [], true) ?>

      <div class="form-actions">
        <button class="btn" type="submit">
          <?= icon('check', 16) ?>
          <span><?= $editing !== null ? 'Save changes' : 'Create category' ?></span>
        </button>
        <?php if ($editing !== null): ?>
          <a class="btn grey" href="<?= Helpers::e(Helpers::url('admin/categories.php')) ?>">
            <?= icon('close', 16) ?><span>Cancel</span>
          </a>
        <?php endif; ?>
      </div>
    </form>
  </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
