<?php
/**
 * organization/announcements.php — post and manage notices for organization members.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('officer');
Permissions::requireCapability('post_announcements');

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
        if ($action === 'create') {
            $title   = trim((string) (Helpers::post('title') ?? ''));
            $content = trim((string) (Helpers::post('content') ?? ''));
            if ($title === '' || $content === '') {
                throw new RuntimeException('Headline and message content are required.');
            }

            $audience = (string) (Helpers::post('audience') ?? 'organization');
            if (!in_array($audience, ['organization', 'officers', 'members'], true)) {
                $audience = 'organization';
            }

            AnnouncementRepo::create([
                'title'           => $title,
                'content'         => $content,
                'audience'        => $audience,
                'organization_id' => $orgId,
                'publish_date'    => Helpers::now(),
                'status'          => (string) (Helpers::post('status') ?? 'published'),
                'is_pinned'       => Helpers::inputBool('is_pinned'),
                'author_id'       => Auth::id(),
            ]);

            Helpers::flash('success', 'Announcement published.');
        } elseif ($action === 'status') {
            $id  = Helpers::postInt('announcement_id');
            $ann = $id > 0 ? AnnouncementRepo::find($id) : null;
            if ($ann === null || (int) $ann['organization_id'] !== $orgId) {
                throw new RuntimeException('Announcement not found or unauthorized.');
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

    Helpers::redirect('organization/announcements.php');
}

$filters = [
    'q'               => (string) (Helpers::get('q') ?? ''),
    'status'          => (string) (Helpers::get('status') ?? ''),
    'organization_id' => $orgId,
];

$result = AnnouncementRepo::list($filters, Helpers::page(), 15);
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];

$PAGE_TITLE       = 'Announcements';
$PAGE_ACTIVE      = 'announcements';
$PAGE_SUB         = Helpers::e((string) $org['name']) . ' · Broadcast news, meeting reminders and updates to members';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'organization/dashboard.php'], ['label' => 'Announcements']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="grid side-main">
  <section class="card">
    <div class="card-head">
      <h3>Broadcast Announcement</h3>
      <span class="muted small">Inform members</span>
    </div>

    <form method="post" action="<?= Helpers::e(Helpers::url('organization/announcements.php')) ?>" class="stack">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="create">

      <div class="field">
        <label class="field-label" for="ann-aud">Target Audience</label>
        <select id="ann-aud" name="audience">
          <option value="organization">All Members & Officers</option>
          <option value="members">General Members Only</option>
          <option value="officers">Executive Officers Only</option>
        </select>
      </div>

      <?= ui_input('title', 'Headline', '', ['placeholder' => 'e.g. Mandatory General Assembly on Friday'], true) ?>

      <div class="field">
        <label class="field-label" for="ann-body">Announcement Message</label>
        <textarea id="ann-body" name="content" rows="4" placeholder="Write full details, venue, time, and instructions..." required></textarea>
      </div>

      <div class="field">
        <label class="field-label" for="ann-st">Publication Status</label>
        <select id="ann-st" name="status">
          <option value="published">Publish Immediately</option>
          <option value="draft">Save as Draft</option>
        </select>
      </div>

      <label class="check">
        <input type="checkbox" name="is_pinned" value="1">
        <span>Pin to top of student feed</span>
      </label>

      <button class="btn" type="submit"><?= icon('megaphone', 16) ?><span>Post Announcement</span></button>
    </form>
  </section>

  <section class="card">
    <div class="card-head">
      <h3>Active Notices</h3>
      <span class="muted small"><?= $result['total'] ?> post(s)</span>
    </div>

    <form method="get" action="<?= Helpers::e(Helpers::url('organization/announcements.php')) ?>" class="filter-bar">
      <div class="filter-field">
        <input type="search" name="q" value="<?= Helpers::e($filters['q']) ?>" placeholder="Search notices...">
      </div>
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
      <?= ui_empty('No announcements posted yet.', 'Use the form on the left to broadcast your first message to members.', 'megaphone') ?>
    <?php else: ?>
      <div class="stack mt">
        <?php foreach ($rows as $ann): ?>
          <article class="card p-4" style="background: var(--ink-050); border: 1px solid var(--line);">
            <div class="flex-between">
              <div>
                <?php if (!empty($ann['is_pinned'])): ?>
                  <?= ui_badge('Pinned', 'amber') ?>
                <?php endif; ?>
                <?= ui_badge((string) $ann['audience'], 'blue') ?>
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
              <span>Posted on <?= Helpers::e(Helpers::fmtDateTime((string) $ann['publish_date'])) ?></span>

              <form method="post" action="<?= Helpers::e(Helpers::url('organization/announcements.php')) ?>" class="inline">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="announcement_id" value="<?= (int) $ann['id'] ?>">
                <?php if ($ann['status'] === 'published'): ?>
                  <button class="btn xs ghost text-danger" type="submit" name="status" value="archived" onclick="return confirm('Archive this notice?')">Archive</button>
                <?php elseif ($ann['status'] === 'draft'): ?>
                  <button class="btn xs ghost" type="submit" name="status" value="published">Publish</button>
                <?php else: ?>
                  <button class="btn xs ghost" type="submit" name="status" value="published">Restore</button>
                <?php endif; ?>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <?= ui_pagination($page, (int) $result['pages'], http_build_query(array_filter($filters))) ?>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
