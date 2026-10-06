<?php
/**
 * student/announcements.php — campus and organization announcements feed for students.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$student   = Permissions::requireStudentProfile();
$studentId = (int) $student['id'];
$deptId    = (int) ($student['department_id'] ?? 0);

$memberships = MemberRepo::forStudent($studentId);
$activeOrgIds = array_map(
    'intval',
    array_column(array_filter($memberships, static fn (array $m): bool => $m['status'] === 'active'), 'organization_id')
);

$q = trim((string) (Helpers::get('q') ?? ''));

$announcements = AnnouncementRepo::feed($activeOrgIds, $deptId, false, 50);

if ($q !== '') {
    $term = mb_strtolower($q);
    $announcements = array_filter($announcements, static function (array $a) use ($term): bool {
        return str_contains(mb_strtolower((string) $a['title']), $term)
            || str_contains(mb_strtolower((string) $a['content']), $term)
            || str_contains(mb_strtolower((string) ($a['organization_name'] ?? '')), $term);
    });
}

$PAGE_TITLE       = 'Announcements';
$PAGE_ACTIVE      = 'announcements';
$PAGE_SUB         = 'Official news and notices from your organizations, college department, and campus administration';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'student/dashboard.php'], ['label' => 'Announcements']];

require __DIR__ . '/../includes/layout/header.php';
?>

<section class="card mb-4">
  <form method="get" action="<?= Helpers::e(Helpers::url('student/announcements.php')) ?>" class="filter-bar">
    <div class="filter-field">
      <input type="search" name="q" value="<?= Helpers::e($q) ?>" placeholder="Search announcements...">
    </div>
    <div class="filter-actions">
      <button class="btn sm" type="submit"><?= icon('search', 15) ?><span>Search</span></button>
      <?php if ($q !== ''): ?>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('student/announcements.php')) ?>"><?= icon('refresh', 15) ?><span>Reset</span></a>
      <?php endif; ?>
    </div>
  </form>
</section>

<?php if ($announcements === []): ?>
  <section class="card">
    <?= ui_empty('No announcements available at this time.', 'Updates from your joined organizations and academic department will be posted here.', 'megaphone') ?>
  </section>
<?php else: ?>
  <div class="stack">
    <?php foreach ($announcements as $ann): ?>
      <article class="card p-5" style="border: 1px solid var(--line); <?= !empty($ann['is_pinned']) ? 'border-left: 4px solid var(--brand-700);' : '' ?>">
        <div class="flex-between mb-2">
          <div class="cluster">
            <?php if (!empty($ann['is_pinned'])): ?>
              <?= ui_badge('Pinned Notice', 'amber') ?>
            <?php endif; ?>
            <?php if (!empty($ann['organization_name'])): ?>
              <?= ui_badge((string) ($ann['acronym'] ?: $ann['organization_name']), 'blue') ?>
            <?php elseif (!empty($ann['department_name'])): ?>
              <?= ui_badge((string) $ann['department_name'], 'purple') ?>
            <?php else: ?>
              <?= ui_badge('Campus Wide', 'green') ?>
            <?php endif; ?>
          </div>
          <span class="muted small"><?= Helpers::e(Helpers::fmtDateTime((string) $ann['publish_date'])) ?></span>
        </div>

        <h3 style="font-size: var(--fs-lg); margin-top: 4px; margin-bottom: 8px; color: var(--ink-900);">
          <?= Helpers::e((string) $ann['title']) ?>
        </h3>

        <div class="ann-content" style="line-height: 1.6; color: var(--ink-700); white-space: pre-wrap; font-size: var(--fs-md);">
          <?= nl2br(Helpers::e((string) $ann['content'])) ?>
        </div>

        <div class="flex-between mt-4 pt-3 text-xs muted" style="border-top: 1px solid var(--line-soft);">
          <span>Published by <?= Helpers::e((string) ($ann['author_name'] ?? 'College Staff')) ?></span>
          <?php if (!empty($ann['expiration_date'])): ?>
            <span>Valid until <?= Helpers::e(Helpers::fmtDate((string) $ann['expiration_date'])) ?></span>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
