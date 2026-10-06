<?php
/**
 * adviser/announcements.php — announcement monitoring and posting for adviser's organizations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('adviser');
Permissions::requireCapability('post_announcements');

$orgIds = Permissions::managedOrganizationIds();

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');

    try {
        if ($action === 'create') {
            $orgId = Helpers::postInt('organization_id');
            if ($orgId <= 0 || !in_array($orgId, $orgIds, true)) {
                throw new RuntimeException('Please select one of your assigned organizations.');
            }

            $title   = trim((string) (Helpers::post('title') ?? ''));
            $content = trim((string) (Helpers::post('content') ?? ''));
            if ($title === '' || $content === '') {
                throw new RuntimeException('Title and content are required.');
            }

            $audience = (string) (Helpers::post('audience') ?? 'organization');
            if (!in_array($audience, ['organization', 'officers', 'members'], true)) {
                $audience = 'organization';
            }

            $pubDate = (string) (Helpers::post('publish_date') ?? '');
            $expDate = (string) (Helpers::post('expiration_date') ?? '');

            AnnouncementRepo::create([
                'title'           => $title,
                'content'         => $content,
                'audience'        => $audience,
                'organization_id' => $orgId,
                'publish_date'    => $pubDate !== '' ? $pubDate : Helpers::now(),
                'expiration_date' => $expDate !== '' ? $expDate : null,
                'status'          => (string) (Helpers::post('status') ?? 'published'),
                'is_pinned'       => Helpers::inputBool('is_pinned'),
                'author_id'       => Auth::id(),
            ]);

            Helpers::flash('success', 'Announcement published.');
        } elseif ($action === 'status') {
            $id  = Helpers::postInt('announcement_id');
            $ann = $id > 0 ? AnnouncementRepo::find($id) : null;
            if ($ann === null) {
                throw new RuntimeException('Announcement not found.');
            }
            if (!in_array((int) $ann['organization_id'], $orgIds, true)) {
                throw new RuntimeException('Not authorized.');
            }

            $newStatus = (string) (Helpers::post('status') ?? 'draft');
            if (in_array($newStatus, ['draft', 'published', 'archived'], true)) {
                AnnouncementRepo::setStatus($id, $newStatus);
                Helpers::flash('success', 'Announcement status changed to ' . ui_status($newStatus) . '.');
            }
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect('adviser/announcements.php');
}

$filters = [
    'q'              => (string) (Helpers::get('q') ?? ''),
    'organization_id' => Helpers::getInt('organization_id'),
    'status'         => (string) (Helpers::get('status') ?? ''),
];

$where = ['1=1'];
$params = [];

if ($orgIds !== []) {
    if ($filters['organization_id'] > 0 && in_array($filters['organization_id'], $orgIds, true)) {
        $where[] = 'a.organization_id = :org';
        $params['org'] = $filters['organization_id'];
    } else {
        $in = [];
        foreach ($orgIds as $i => $id) {
            $key = ':org' . $i;
            $in[] = $key;
            $params[$key] = $id;
        }
        $where[] = '(a.organization_id IN (' . implode(',', $in) . ') OR a.audience = "all")';
    }
} else {
    $where[] = 'a.audience = "all"';
}

if ($filters['q'] !== '') {
    $where[] = '(a.title LIKE :q OR a.content LIKE :q2)';
    $params['q'] = '%' . $filters['q'] . '%';
    $params['q2'] = '%' . $filters['q'] . '%';
}
if ($filters['status'] !== '') {
    $where[] = 'a.status = :st';
    $params['st'] = $filters['status'];
}

$whereSql = implode(' AND ', $where);
$total = (int) Database::scalar(
    'SELECT COUNT(*) FROM announcements a '
    . 'LEFT JOIN organizations o ON o.id = a.organization_id WHERE ' . $whereSql,
    $params
);

$page    = Helpers::page();
$perPage = 15;
$pages   = (int) max(1, ceil($total / $perPage));
$page    = min($page, $pages);

$rows = Database::all(
    'SELECT a.*, o.name AS organization_name, o.acronym, u.full_name AS author_name '
    . 'FROM announcements a '
    . 'LEFT JOIN organizations o ON o.id = a.organization_id '
    . 'LEFT JOIN users u ON u.id = a.author_id '
    . 'WHERE ' . $whereSql . ' '
    . 'ORDER BY a.is_pinned DESC, a.publish_date DESC, a.id DESC '
    . 'LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
    $params
);

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

$PAGE_TITLE       = 'Announcement Monitoring';
$PAGE_ACTIVE      = 'announcements';
$PAGE_SUB         = 'View and broadcast notices for your assigned organizations';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'adviser/dashboard.php'], ['label' => 'Announcements']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="grid side-main">
  <?php if ($orgIds !== []): ?>
    <section class="card">
      <div class="card-head">
        <h3>Post Announcement</h3>
        <span class="muted small">Broadcast to members</span>
      </div>
      <form method="post" action="<?= Helpers::e(Helpers::url('adviser/announcements.php')) ?>" class="stack">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="create">

        <div class="field">
          <label class="field-label" for="ann-org">Target organization</label>
          <select id="ann-org" name="organization_id" required>
            <?php foreach ($orgOptions as $id => $label): ?>
              <option value="<?= $id ?>"><?= Helpers::e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="field-label" for="ann-audience">Audience</label>
          <select id="ann-audience" name="audience">
            <option value="organization">All members & officers</option>
            <option value="officers">Officers only</option>
            <option value="members">General members only</option>
          </select>
        </div>

        <?= ui_input('title', 'Headline / Title', '', ['placeholder' => 'e.g. Important Adviser Notice regarding Accreditation'], true) ?>

        <div class="field">
          <label class="field-label" for="ann-content">Message</label>
          <textarea id="ann-content" name="content" rows="4" placeholder="Write your announcement details here..." required></textarea>
        </div>

        <label class="check">
          <input type="checkbox" name="is_pinned" value="1">
          <span>Pin to top of organization feed</span>
        </label>

        <button class="btn" type="submit">
          <?= icon('megaphone', 16) ?><span>Publish Notice</span>
        </button>
      </form>
    </section>
  <?php endif; ?>

  <section class="card <?= $orgIds === [] ? 'cols-span-full' : '' ?>">
    <div class="card-head">
      <h3>Active Announcements</h3>
      <span class="muted small"><?= $total ?> notice(s)</span>
    </div>

    <form method="get" action="<?= Helpers::e(Helpers::url('adviser/announcements.php')) ?>" class="filter-bar">
      <div class="filter-field">
        <input type="search" name="q" value="<?= Helpers::e($filters['q']) ?>" placeholder="Search announcements...">
      </div>
      <?php if (count($orgOptions) > 1): ?>
        <div class="filter-field">
          <select name="organization_id">
            <option value="">All assigned orgs</option>
            <?php foreach ($orgOptions as $id => $label): ?>
              <option value="<?= $id ?>" <?= $filters['organization_id'] === $id ? 'selected' : '' ?>><?= Helpers::e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="filter-field">
        <select name="status">
          <option value="">All statuses</option>
          <option value="published" <?= $filters['status'] === 'published' ? 'selected' : '' ?>>Published</option>
          <option value="draft" <?= $filters['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
          <option value="archived" <?= $filters['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
        </select>
      </div>
      <div class="filter-actions">
        <button class="btn sm" type="submit"><?= icon('search', 15) ?><span>Filter</span></button>
      </div>
    </form>

    <?php if ($rows === []): ?>
      <?= ui_empty('No announcements found.', 'Announcements for your organizations will be listed here.', 'megaphone') ?>
    <?php else: ?>
      <div class="stack mt">
        <?php foreach ($rows as $ann): ?>
          <article class="card p-4 <?= !empty($ann['is_pinned']) ? 'border-primary' : '' ?>" style="background: var(--ink-050); border: 1px solid var(--line);">
            <div class="flex-between">
              <div>
                <?php if (!empty($ann['is_pinned'])): ?>
                  <?= ui_badge('Pinned', 'amber') ?>
                <?php endif; ?>
                <?= ui_badge((string) $ann['audience'], 'blue') ?>
                <?php if (!empty($ann['organization_name'])): ?>
                  <span class="muted small">· <?= Helpers::e((string) ($ann['acronym'] ?: $ann['organization_name'])) ?></span>
                <?php else: ?>
                  <span class="muted small">· Campus-Wide</span>
                <?php endif; ?>
              </div>
              <div>
                <?= ui_status_badge((string) $ann['status']) ?>
              </div>
            </div>

            <h4 class="mt-2 mb-1" style="font-size: var(--fs-md); font-weight: 600;">
              <?= Helpers::e((string) $ann['title']) ?>
            </h4>

            <p class="muted small mb-3" style="line-height: 1.5; white-space: pre-wrap;"><?= nl2br(Helpers::e((string) $ann['content'])) ?></p>

            <div class="flex-between text-xs muted pt-2" style="border-top: 1px solid var(--line-soft);">
              <span>Posted by <?= Helpers::e((string) ($ann['author_name'] ?? 'Admin')) ?> on <?= Helpers::e(Helpers::fmtDateTime((string) $ann['publish_date'])) ?></span>

              <?php if (in_array((int) $ann['organization_id'], $orgIds, true)): ?>
                <form method="post" action="<?= Helpers::e(Helpers::url('adviser/announcements.php')) ?>" class="inline">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="announcement_id" value="<?= (int) $ann['id'] ?>">
                  <?php if ($ann['status'] === 'published'): ?>
                    <button class="btn xs ghost text-danger" type="submit" name="status" value="archived" onclick="return confirm('Archive this announcement?')">Archive</button>
                  <?php elseif ($ann['status'] === 'archived'): ?>
                    <button class="btn xs ghost" type="submit" name="status" value="published">Restore</button>
                  <?php endif; ?>
                </form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <?= ui_pagination($page, $pages, http_build_query(array_filter($filters))) ?>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
