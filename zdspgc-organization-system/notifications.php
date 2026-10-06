<?php
/**
 * notifications.php — in-app notification inbox (all roles).
 *
 * Notifications are produced by every workflow decision: approvals, rejections,
 * new applications, event decisions, published announcements.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireLogin();

// "Open": mark the notification as read, then continue to its target page.
$openId = (int) (Helpers::get('open') ?? 0);
if ($openId > 0) {
    $item = Database::one('SELECT url FROM notifications WHERE id = :id AND user_id = :u', ['id' => $openId, 'u' => Auth::id()]);
    if ($item !== null) {
        Notifications::markRead($openId);
        $target = trim((string) $item['url']);
        if ($target !== '' && preg_match('#^(https?:)?//#', $target) !== 1) {
            Helpers::redirect($target);
        }
    }
    Helpers::redirect('notifications.php');
}

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');
    if ($action === 'read_all') {
        $count = Notifications::markAllRead();
        Helpers::flash('success', $count > 0 ? $count . ' notification(s) marked as read.' : 'No unread notification.');
    } elseif ($action === 'read') {
        Notifications::markRead(Helpers::postInt('id'));
        Helpers::redirect('notifications.php');
    }
    Helpers::redirect('notifications.php');
}

$onlyUnread = Helpers::get('unread') === '1';
$rows       = Notifications::forUser(null, 50, $onlyUnread);
$unread     = Notifications::unreadCount();

$PAGE_TITLE   = 'Notifications';
$PAGE_ACTIVE  = 'notifications';
$PAGE_SUB     = $unread > 0 ? $unread . ' unread notification(s)' : 'You are all caught up.';
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('notifications.php?unread=' . ($onlyUnread ? '0' : '1'))) . '">'
    . icon('filter', 16) . '<span>' . ($onlyUnread ? 'Show all' : 'Unread only') . '</span></a>';

require __DIR__ . '/includes/layout/header.php';
?>

<?php if ($unread > 0): ?>
  <form method="post" class="mb">
    <?= Security::csrfField() ?>
    <button class="btn ghost sm" type="submit" name="action" value="read_all">
      <?= icon('check', 16) ?><span>Mark all as read</span>
    </button>
  </form>
<?php endif; ?>

<section class="card">
  <div class="card-head">
    <h3><?= $onlyUnread ? 'Unread notifications' : 'All notifications' ?></h3>
    <span class="badge <?= $unread > 0 ? 'amber' : 'green' ?>"><?= (int) $unread ?> unread</span>
  </div>

  <?php if ($rows === []): ?>
    <?= ui_empty(
        $onlyUnread ? 'No unread notification.' : 'No notification yet.',
        'Notifications appear here when an approval, event or announcement needs your attention.',
        'bell'
    ) ?>
  <?php else: ?>
    <ul class="timeline">
      <?php foreach ($rows as $item): ?>
        <li>
          <div class="tl-time">
            <?= Helpers::e(Helpers::humanAgo((string) $item['created_at'])) ?>
            · <?= Helpers::e(Helpers::fmtDateTime((string) $item['created_at'])) ?>
            <?php if ((int) $item['is_read'] === 0): ?><?= ui_badge('New', 'amber') ?><?php endif; ?>
          </div>
          <div class="tl-title">
            <?= ui_badge(ui_status((string) $item['type']), ui_tone((string) $item['type'])) ?>
            <?= Helpers::e((string) $item['title']) ?>
          </div>
          <div class="tl-desc"><?= Helpers::e((string) $item['message']) ?></div>
          <?php if ((string) $item['url'] !== '' || (int) $item['is_read'] === 0): ?>
            <div class="btn-row mt-tight">
              <?php if ((string) $item['url'] !== ''): ?>
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('notifications.php?open=' . (int) $item['id'])) ?>">
                  <?= icon('external', 15) ?><span>Open</span>
                </a>
              <?php endif; ?>
              <?php if ((int) $item['is_read'] === 0): ?>
                <form method="post" class="inline">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                  <button class="btn sm grey" type="submit" name="action" value="read">
                    <?= icon('check', 15) ?><span>Mark as read</span>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
