<?php
/**
 * layout/header.php — HTML shell: sidebar, top bar, page head, flash messages.
 *
 * Pages may set these before including this file:
 *   $PAGE_TITLE       (string)  <title> text and page heading
 *   $PAGE_ACTIVE      (string)  sidebar nav key to highlight
 *   $PAGE_SUB         (string)  small line under the heading
 *   $PAGE_ACTIONS     (string)  raw HTML for the buttons on the right
 *   $PAGE_BREADCRUMBS (array)   [['label' => '…', 'href' => '…'], …]
 *   $PAGE_PRINT       (bool)    true -> print-only sheet (no navigation)
 *   $PAGE_WIDE        (bool)    true -> full-width content area
 *   $PAGE_CHARTS      (bool)    true -> load Chart.js
 *   $EXTRA_CSS        (array)   extra stylesheet file names inside assets/css/
 *   $EXTRA_JS         (array)   extra script file names inside assets/js/
 */

declare(strict_types=1);

$PAGE_TITLE       = $PAGE_TITLE       ?? 'Dashboard';
$PAGE_ACTIVE      = $PAGE_ACTIVE      ?? '';
$PAGE_SUB         = $PAGE_SUB         ?? '';
$PAGE_ACTIONS     = $PAGE_ACTIONS     ?? '';
$PAGE_BREADCRUMBS = $PAGE_BREADCRUMBS ?? [];
$PAGE_PRINT       = $PAGE_PRINT       ?? false;
$PAGE_WIDE        = $PAGE_WIDE        ?? false;
$PAGE_CHARTS      = $PAGE_CHARTS      ?? false;
$EXTRA_CSS        = $EXTRA_CSS        ?? [];
$EXTRA_JS         = $EXTRA_JS         ?? [];

$__flashes    = Helpers::takeFlashes();
$__unread     = Notifications::unreadCount();
$__user       = Auth::user();
$__flashIcons = ['success' => 'check-circle', 'error' => 'alert', 'warning' => 'alert', 'info' => 'info'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= Helpers::e($PAGE_TITLE) ?> · <?= Helpers::e(APP_SHORT) ?></title>
  <meta name="csrf-token" content="<?= Helpers::e(Security::csrfToken()) ?>">
  <meta name="app-url" content="<?= Helpers::e(Helpers::url('')) ?>">
  <meta name="description" content="<?= Helpers::e(SCHOOL_NAME . ' — ' . APP_NAME) ?>">
  <link rel="icon" type="image/png" href="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>">
  <link rel="stylesheet" href="<?= Helpers::e(Helpers::url('assets/css/style.css')) ?>">
<?php foreach ($EXTRA_CSS as $css): ?>
  <link rel="stylesheet" href="<?= Helpers::e(Helpers::url('assets/css/' . $css)) ?>">
<?php endforeach; ?>
<?php if ($PAGE_CHARTS): ?>
  <?= Chart::scriptTag() ?>
<?php endif; ?>
</head>
<body class="<?= $PAGE_PRINT ? 'print-mode' : '' ?>">

<?php if ($PAGE_PRINT): ?>
  <!-- ================= print-only document header ================= -->
  <div class="print-doc-head">
    <div class="print-doc-brand">
      <img src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>" alt="ZDSPGC seal" width="62" height="62">
      <div>
        <strong><?= Helpers::e(SCHOOL_NAME) ?></strong>
        <span><?= Helpers::e(SCHOOL_CAMPUS) ?></span>
        <span class="print-doc-unit">Office of Student Affairs · <?= Helpers::e(APP_NAME) ?></span>
      </div>
    </div>
    <div class="print-doc-meta">
      <div><b>Document:</b> <?= Helpers::e($PAGE_TITLE) ?></div>
      <div><b>Date generated:</b> <?= Helpers::e(date('F j, Y · g:i A')) ?></div>
      <div><b>Prepared by:</b> <?= Helpers::e((string) Auth::userName()) ?> (<?= Helpers::e(Auth::roleLabel()) ?>)</div>
    </div>
  </div>
  <div class="print-actions no-print">
    <button class="btn sm" type="button" onclick="window.print()"><?= icon('print', 16) ?><span>Print this page</span></button>
    <a class="btn sm ghost" href="javascript:history.back()">Back</a>
  </div>
  <main class="print-sheet">
<?php else: ?>
<!-- ================= application shell ================= -->
<div class="app-shell">
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="side-head">
      <a class="brand" href="<?= Helpers::e(Helpers::url(Permissions::homeForRole())) ?>">
        <img class="brand-logo" src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>" alt="ZDSPGC seal" width="40" height="40">
        <span class="brand-text">
          <span class="name">OrgSys</span>
          <span class="sub">Student Organizations</span>
        </span>
      </a>
      <button class="icon-btn side-close" type="button" id="sidebar-close" aria-label="Close navigation"><?= icon('close', 20) ?></button>
    </div>

    <?php
    require __DIR__ . '/sidebar.php';
    $__groups = layout_nav((string) Auth::role());
    foreach ($__groups as $__group):
        $__rendered = 0;
        ?>
        <nav class="side-nav">
          <div class="side-heading"><?= Helpers::e((string) $__group['group']) ?></div>
          <?php foreach ($__group['items'] as $__item): layout_nav_link($__item, (string) $PAGE_ACTIVE); $__rendered++; endforeach; ?>
        </nav>
        <?php
    endforeach;
    ?>

    <?= layout_sidebar_user() ?>
  </aside>
  <div class="sidebar-backdrop" id="sidebar-backdrop"></div>


  <div class="main-col">
    <div class="mobile-bar">
      <button class="icon-btn" id="menu-btn" type="button" aria-label="Open navigation menu" aria-controls="sidebar" aria-expanded="false"><?= icon('menu', 22) ?></button>
      <span class="mobile-title"><?= Helpers::e($PAGE_TITLE) ?></span>
      <a class="icon-btn" href="<?= Helpers::e(Helpers::url('notifications.php')) ?>" aria-label="Notifications">
        <?= icon('bell', 20) ?><?php if ($__unread > 0): ?><span class="dot-badge"><?= $__unread > 9 ? '9+' : (int) $__unread ?></span><?php endif; ?>
      </a>
    </div>

    <div class="govbar">
      <span class="govbar-left"><?= flag_ph() ?><?= Helpers::e(SCHOOL_NAME) ?> · <?= Helpers::e(SCHOOL_CAMPUS) ?></span>
      <span class="govbar-right"><?= icon('shield', 15) ?> Official student organization record · <?= Helpers::e(date('F j, Y')) ?></span>
    </div>

    <header class="topbar">
      <form class="global-search" method="get" action="<?= Helpers::e(Helpers::url('search.php')) ?>" role="search">
        <input type="search" name="q" placeholder="Search students, organizations, events, documents…" aria-label="Global search" value="<?= Helpers::e((string) (Helpers::get('q') ?? '')) ?>">
      </form>

      <div class="topbar-right">
        <a class="icon-btn notif-btn" href="<?= Helpers::e(Helpers::url('notifications.php')) ?>" aria-label="Notifications (<?= (int) $__unread ?> unread)">
          <?= icon('bell', 19) ?>
          <?php if ($__unread > 0): ?><span class="dot-badge"><?= $__unread > 9 ? '9+' : (int) $__unread ?></span><?php endif; ?>
        </a>
        <a class="topbar-user" href="<?= Helpers::e(Helpers::url('account.php')) ?>">
          <?= ui_avatar((string) Auth::userName(), Uploads::url((string) ($__user['avatar'] ?? '')), 34) ?>
          <span class="topbar-user-meta">
            <strong><?= Helpers::e(Helpers::excerpt((string) Auth::userName(), 22)) ?></strong>
            <small><?= Helpers::e(Auth::roleLabel()) ?></small>
          </span>
        </a>
      </div>
    </header>

    <main class="<?= $PAGE_WIDE ? 'wrap wide' : 'wrap' ?>">
      <div class="page-head">
        <div class="page-head-main">
          <?= ui_breadcrumbs($PAGE_BREADCRUMBS) ?>
          <h1><?= Helpers::e($PAGE_TITLE) ?></h1>
          <?php if ($PAGE_SUB !== ''): ?><p class="lead"><?= $PAGE_SUB ?></p><?php endif; ?>
        </div>
        <?php if ($PAGE_ACTIONS !== ''): ?>
          <div class="page-actions"><?= $PAGE_ACTIONS ?></div>
        <?php endif; ?>
      </div>

      <?php foreach ($__flashes as $flash): ?>
        <div class="flash flash-<?= Helpers::e((string) $flash['type']) ?>" role="status">
          <?= icon($__flashIcons[(string) $flash['type']] ?? 'info', 18) ?>
          <span><?= Helpers::e((string) $flash['message']) ?></span>
          <button class="flash-close" type="button" aria-label="Dismiss"><?= icon('close', 15) ?></button>
        </div>
      <?php endforeach; ?>
<?php endif; ?>
