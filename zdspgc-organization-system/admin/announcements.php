<?php
/**
 * admin/announcements.php — publish, edit and archive announcements.
 *
 * The list offers filters (search, audience, status and organization), the
 * status buttons (publish / unpublish / archive) and a paginated table. The
 * form above it creates or edits an announcement; the organization selector
 * only appears for the organization-scoped audiences and the department
 * selector only for the department audience.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('manage_announcements');

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');

    $payload = static function (): array {
        return [
            'title'                  => (string) (Helpers::post('title') ?? ''),
            'content'                => (string) (Helpers::post('content') ?? ''),
            'audience'               => (string) (Helpers::post('audience') ?? 'all'),
            'organization_id'        => Helpers::postInt('organization_id'),
            'audience_department_id' => Helpers::postInt('audience_department_id'),
            'publish_date'           => (string) (Helpers::post('publish_date') ?? ''),
            'expiration_date'        => (string) (Helpers::post('expiration_date') ?? ''),
            'status'                 => (string) (Helpers::post('status') ?? 'published'),
            'is_pinned'              => Helpers::inputBool('is_pinned'),
        ];
    };

    try {
        if ($action === 'create') {
            AnnouncementRepo::create($payload());
            Helpers::flash('success', 'The announcement was created.');
        } elseif ($action === 'edit') {
            $id      = Helpers::postInt('announcement_id');
            $current = $id > 0 ? AnnouncementRepo::find($id) : null;
            if ($current === null) {
                throw new RuntimeException('That announcement no longer exists.');
            }
            $data = $payload();
            if ($data['publish_date'] === '') {
                $data['publish_date'] = (string) $current['publish_date'];
            }
            AnnouncementRepo::update($id, $data);
            $status = in_array($data['status'], ['draft', 'published', 'archived'], true)
                ? $data['status'] : (string) $current['status'];
            if ($status !== (string) $current['status']) {
                AnnouncementRepo::setStatus($id, $status);
            }
            Helpers::flash('success', 'The announcement was updated.');
        } elseif ($action === 'status') {
            $key = (string) (Helpers::post('status') ?? '');
            $map = ['publish' => 'published', 'unpublish' => 'draft', 'archive' => 'archived'];
            $id  = Helpers::postInt('announcement_id');
            if ($id <= 0 || !isset($map[$key])) {
                throw new RuntimeException('Unknown status change.');
            }
            AnnouncementRepo::setStatus($id, $map[$key]);
            Helpers::flash('success', 'The announcement is now ' . ui_status($map[$key]) . '.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect('admin/announcements.php');
}

$filters = [
    'q'              => (string) (Helpers::get('q') ?? ''),
    'audience'       => (string) (Helpers::get('audience') ?? ''),
    'status'         => (string) (Helpers::get('status') ?? ''),
    'organization_id' => Helpers::getInt('organization_id'),
];

$result = AnnouncementRepo::list($filters, Helpers::page(), 20);
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];
$query  = http_build_query(array_filter($filters));

$editId   = Helpers::getInt('edit');
$editing  = $editId > 0 ? AnnouncementRepo::find($editId) : null;
if ($editId > 0 && $editing === null) {
    Helpers::flash('error', 'That announcement no longer exists.');
    Helpers::redirect('admin/announcements.php');
}

$audienceLabels = [
    'all'          => 'Everyone',
    'organization' => 'One organization',
    'department'   => 'One department',
    'officers'     => 'Officers of an organization',
    'members'      => 'Members of an organization',
];
$statusLabels = ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'];

$orgOptions = [];
foreach (OrgRepo::list([], 1, 200)['rows'] as $org) {
    $code = (string) ($org['acronym'] ?? '');
    $orgOptions[(int) $org['id']] = ($code !== '' ? $code . ' — ' : '') . (string) $org['name'];
}
$deptOptions = AcademicRepo::departmentOptions();

$totalCount     = AnnouncementRepo::list([], 1, 1)['total'];
$publishedCount = AnnouncementRepo::list(['status' => 'published'], 1, 1)['total'];
$draftCount     = AnnouncementRepo::list(['status' => 'draft'], 1, 1)['total'];
$archivedCount  = AnnouncementRepo::list(['status' => 'archived'], 1, 1)['total'];

$isEdit   = $editing !== null;
$audience = $isEdit ? (string) $editing['audience'] : 'all';
$orgId    = $isEdit ? (int) ($editing['organization_id'] ?? 0) : 0;
$deptId   = $isEdit ? (int) ($editing['audience_department_id'] ?? 0) : 0;
$title    = $isEdit ? (string) $editing['title'] : '';
$content  = $isEdit ? (string) ($editing['content'] ?? '') : '';
$status   = $isEdit ? (string) $editing['status'] : 'published';
$pinned   = $isEdit && (int) $editing['is_pinned'] === 1;
$toLocal  = static fn (?string $value): string => $value === null || $value === ''
    ? '' : substr(str_replace(' ', 'T', $value), 0, 16);
$publishDate = $isEdit ? $toLocal((string) $editing['publish_date']) : '';
$expiresDate = $isEdit ? $toLocal((string) ($editing['expiration_date'] ?? '')) : '';
$showOrg     = in_array($audience, ['organization', 'officers', 'members'], true);
$showDept    = $audience === 'department';

$filterFields = ui_filter_input('q', 'Search', $filters['q'])
    . ui_filter_select('audience', 'Audience', $audienceLabels, $filters['audience'])
    . ui_filter_select('status', 'Status', $statusLabels, $filters['status'])
    . ui_filter_select('organization_id', 'Organization', $orgOptions,
        $filters['organization_id'] > 0 ? (string) $filters['organization_id'] : '');

$statusForm = static function (array $row, string $key, string $label, string $classes, bool $confirm = false): string {
    return '<form method="post" class="inline"'
        . ($confirm ? ' data-confirm="Archive this announcement? It stays available in the archive and can be published again."' : '')
        . '>' . Security::csrfField()
        . '<input type="hidden" name="action" value="status">'
        . '<input type="hidden" name="announcement_id" value="' . (int) $row['id'] . '">'
        . '<button class="' . Helpers::e($classes) . '" type="submit" name="status" value="' . Helpers::e($key) . '">'
        . Helpers::e($label) . '</button></form>';
};

$PAGE_TITLE  = $isEdit ? 'Edit announcement' : 'Announcements';
$PAGE_ACTIVE = 'announcements';
$PAGE_SUB    = $isEdit
    ? 'Editing “' . Helpers::e(Helpers::excerpt($title, 70)) . '” · '
        . ui_badge(ui_status($status), ui_tone($status))
    : $totalCount . ' announcement(s) · ' . $publishedCount . ' published · '
        . $result['total'] . ' matching the current filters';
$PAGE_BREADCRUMBS = [
    ['label' => 'Dashboard', 'href' => 'admin/dashboard.php'],
    ['label' => 'Announcements'],
];
$PAGE_ACTIONS = $isEdit
    ? '<a class="btn ghost" href="' . Helpers::e(Helpers::url('admin/announcements.php')) . '">'
        . icon('close', 16) . '<span>Cancel editing</span></a>'
    : '<a class="btn ghost" href="#ann-form">' . icon('plus', 16) . '<span>Write announcement</span></a>';

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Total announcements', $totalCount, 'megaphone') ?>
  <?= ui_stat('Published', $publishedCount, 'check-circle') ?>
  <?= ui_stat('Drafts', $draftCount, 'edit', $draftCount > 0 ? 'blue' : 'grey') ?>
  <?= ui_stat('Archived', $archivedCount, 'folder', 'grey') ?>
</div>

<section class="card" id="ann-form">
  <div class="card-head">
    <h3><?= $isEdit ? 'Edit announcement' : 'New announcement' ?></h3>
    <?php if ($isEdit): ?>
      <span class="badge blue">Draft status is changed from the table below</span>
    <?php endif; ?>
  </div>

  <form method="post">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="<?= $isEdit ? 'edit' : 'create' ?>">
    <?php if ($isEdit): ?>
      <input type="hidden" name="announcement_id" value="<?= (int) $editing['id'] ?>">
    <?php endif; ?>

    <?= ui_input('title', 'Title', $title, ['placeholder' => 'What should everyone know?'], true) ?>
    <?= ui_textarea('content', 'Message', $content, [
        'rows' => 6,
        'hint' => 'Line breaks are kept. This text is what the audience reads in their notifications and feeds.',
    ], true) ?>

    <?= ui_select('audience', 'Audience', $audienceLabels, $audience, [
        'hint' => 'Choose who receives this announcement. The selectors below follow the audience you pick.',
    ], true) ?>

    <div id="ann_org"<?php echo $showOrg ? '' : ' hidden'; ?>>
      <?= ui_select('organization_id', 'Organization', $orgOptions, $orgId > 0 ? (string) $orgId : '', [
        'placeholder' => 'Choose an organization…',
        'hint'        => 'Required for the organization, officers and members audiences.',
      ]) ?>
    </div>

    <div id="ann_dept"<?php echo $showDept ? '' : ' hidden'; ?>>
      <?= ui_select('audience_department_id', 'Department', $deptOptions, $deptId > 0 ? (string) $deptId : '', [
        'placeholder' => 'Choose a department…',
        'hint'        => 'Required for the department audience.',
      ]) ?>
    </div>

    <?= ui_input('publish_date', 'Publish date and time', $publishDate, [
        'type'  => 'datetime-local',
        'hint'  => 'Leave empty to publish it as soon as the status is published.',
    ]) ?>
    <?= ui_input('expiration_date', 'Expires', $expiresDate, [
        'type'  => 'datetime-local',
        'hint'  => 'Leave empty to keep the announcement up until it is archived.',
    ]) ?>
    <?= ui_select('status', 'Status', $statusLabels, $status, [
        'hint' => 'Published announcements are pushed to the audience of the selected publish date.',
    ], true) ?>

    <label class="field field-check">
      <input type="checkbox" name="is_pinned" value="1"<?= $pinned ? ' checked' : '' ?>>
      <span>Pin this announcement above the others</span>
    </label>

    <div class="form-actions">
      <button class="btn" type="submit">
        <?= icon($isEdit ? 'check' : 'plus', 16) ?><span><?= $isEdit ? 'Save changes' : 'Create announcement' ?></span>
      </button>
      <?php if ($isEdit): ?>
        <a class="btn grey" href="<?= Helpers::e(Helpers::url('admin/announcements.php')) ?>"><?= icon('close', 16) ?><span>Cancel</span></a>
      <?php endif; ?>
    </div>
  </form>
</section>

<?= ui_filter_form('admin/announcements.php', $filterFields, 'Apply filters') ?>

<section class="card">
  <div class="card-head">
    <h3>All announcements</h3>
    <span class="badge"><?= (int) $result['total'] ?> found</span>
  </div>

  <?php if ($rows === []): ?>
    <?= ui_empty('No announcement matches these filters.', 'Clear the filters, or write the first announcement for your audience.', 'megaphone') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Announcement</th><th>Audience</th><th>Target</th><th>Author</th>
            <th>Publish date</th><th>Status</th><th>Expires</th><th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr<?= (string) $row['status'] === 'archived' ? ' class="is-archived"' : '' ?>>
              <td>
                <?php if ((int) $row['is_pinned'] === 1): ?>
                  <?= icon('star', 15) ?>
                <?php endif; ?>
                <strong><?= Helpers::e(Helpers::excerpt((string) $row['title'], 60)) ?></strong>
                <span class="muted"><?= Helpers::e(Helpers::excerpt((string) ($row['content'] ?? ''), 90)) ?></span>
              </td>
              <td><?= ui_badge(ui_status((string) $row['audience']), match ((string) $row['audience']) {
                    'all'          => 'blue',
                    'organization' => 'green',
                    'department'   => 'amber',
                    'officers'     => 'blue',
                    default        => 'grey',
                }) ?></td>
              <td class="small">
                <?php if (!empty($row['organization_name'])): ?>
                  <?= Helpers::e(Helpers::excerpt((string) $row['organization_name'], 34)) ?>
                  <?php if ((string) ($row['acronym'] ?? '') !== ''): ?><span class="muted"><?= Helpers::e((string) $row['acronym']) ?></span><?php endif; ?>
                <?php elseif (!empty($row['department_name'])): ?>
                  <?= Helpers::e(Helpers::excerpt((string) $row['department_name'], 34)) ?>
                <?php elseif ((string) $row['audience'] === 'all'): ?>
                  <span class="muted">Every student and officer</span>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td class="small"><?= !empty($row['author_name']) ? Helpers::e(Helpers::excerpt((string) $row['author_name'], 26)) : '<span class="muted">—</span>' ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDateTime((string) $row['publish_date'])) ?></td>
              <td><?= ui_status_badge((string) $row['status']) ?></td>
              <td class="small nowrap">
                <?php if (!empty($row['expiration_date'])): ?>
                  <?= Helpers::e(Helpers::fmtDateTime((string) $row['expiration_date'])) ?>
                <?php else: ?>
                  <span class="muted">No expiry</span>
                <?php endif; ?>
              </td>
              <td class="actions">
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/announcements.php?edit=' . (int) $row['id'])) ?>">
                  <?= icon('edit', 15) ?><span>Edit</span>
                </a>
                <?php
                $rowStatus = (string) $row['status'];
                if ($rowStatus === 'draft') {
                    echo $statusForm($row, 'publish', 'Publish', 'btn sm');
                } elseif ($rowStatus === 'published') {
                    echo $statusForm($row, 'unpublish', 'Unpublish', 'btn sm ghost');
                    echo $statusForm($row, 'archive', 'Archive', 'btn sm danger', true);
                } else {
                    echo $statusForm($row, 'publish', 'Publish', 'btn sm');
                }
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= ui_pagination($page, $result['pages'], $query) ?>
  <?php endif; ?>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var audience = document.getElementById('audience');
  if (!audience) { return; }
  var toggle = function () {
    var value = audience.value;
    var org   = document.getElementById('ann_org');
    var dept  = document.getElementById('ann_dept');
    if (org)  { org.hidden  = !(value === 'organization' || value === 'officers' || value === 'members'); }
    if (dept) { dept.hidden = value !== 'department'; }
  };
  audience.addEventListener('change', toggle);
  toggle();
});
</script>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
