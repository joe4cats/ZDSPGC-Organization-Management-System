<?php
/**
 * index.php — public landing page.
 *
 * No sign-in required: live counters, the latest announcements and a preview of
 * the organization directory. No member or officer data is exposed here.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$stats = [
    'organizations' => (int) Database::scalar("SELECT COUNT(*) FROM organizations WHERE status = 'active'"),
    'members'       => (int) Database::scalar("SELECT COUNT(*) FROM organization_members WHERE status = 'active'"),
    'events'        => (int) Database::scalar("SELECT COUNT(*) FROM events WHERE status = 'approved' AND event_date >= CURDATE()"),
    'departments'   => (int) Database::scalar("SELECT COUNT(*) FROM departments WHERE status = 'active'"),
];

$year          = AcademicRepo::activeYear();
$organizations = OrgRepo::publicList([], 6);
$events        = EventRepo::upcoming(4);

$announcements = Database::all(
    "SELECT a.*, o.name AS organization_name
       FROM announcements a
       LEFT JOIN organizations o ON o.id = a.organization_id
      WHERE a.status = 'published'
        AND (a.audience = 'all' OR a.is_pinned = 1)
        AND a.publish_date <= NOW()
        AND (a.expiration_date IS NULL OR a.expiration_date >= NOW())
      ORDER BY a.is_pinned DESC, a.publish_date DESC LIMIT 4"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= Helpers::e(SCHOOL_NAME) ?> · Student Organizations</title>
  <meta name="description" content="Official directory of student organizations at <?= Helpers::e(SCHOOL_NAME) ?>.">
  <link rel="icon" type="image/png" href="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>">
  <link rel="stylesheet" href="<?= Helpers::e(Helpers::url('assets/css/style.css')) ?>">
</head>
<body>
  <div class="public-nav-wrap">
  <div class="public-nav">
    <a class="brand" href="index.php">
      <img class="brand-logo" src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>" alt="ZDSPGC seal" width="38" height="38">
      <span class="brand-text">
        <span class="name">ZDSPGC OrgSys</span>
        <span class="sub">Student Organizations</span>
      </span>
    </a>
    <div class="btn-row">
      <a class="btn ghost sm" href="<?= Helpers::e(Helpers::url('organizations.php')) ?>"><?= icon('org', 15) ?><span>Directory</span></a>
      <a class="btn sm" href="<?= Helpers::e(Helpers::url(Auth::check() ? Permissions::homeForRole() : 'login.php')) ?>">
        <?= icon(Auth::check() ? 'dashboard' : 'logout', 15) ?>
        <span><?= Auth::check() ? 'My dashboard' : 'Sign in' ?></span>
      </a>
    </div>
  </div>
  </div>

  <header class="public-hero">
    <div class="public-hero-inner">
      <div class="hero-row">
        <img class="hero-brand" src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>" alt="ZDSPGC seal" width="96" height="96">
        <div class="hero-copy">
        <p class="hero-eyebrow"><?= Helpers::e(SCHOOL_NAME) ?></p>
        <h1>Student Organization Management System</h1>
        <p>The official record of student organizations at ZDSPGC: registration, accreditation, membership,
           officers, activities, events and QR attendance — managed in one place by the Office of Student Affairs.</p>
        <div class="public-hero-stats">
          <div><strong><?= (int) $stats['organizations'] ?></strong><span>Active organizations</span></div>
          <div><strong><?= (int) $stats['members'] ?></strong><span>Student members</span></div>
          <div><strong><?= (int) $stats['events'] ?></strong><span>Upcoming events</span></div>
          <div><strong><?= (int) $stats['departments'] ?></strong><span>Departments</span></div>
        </div>
        </div>
      </div>
    </div>
  </header>

  <main class="public-body">
    <div class="grid cols-2">
      <section class="card">
        <div class="card-head">
          <h3>Organizations</h3>
          <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organizations.php')) ?>">View all</a>
        </div>
        <?php if ($organizations === []): ?>
          <?= ui_empty('No active organization yet.', 'Organizations appear here after the administrator approves their registration.', 'org') ?>
        <?php else: ?>
          <div class="org-grid">
            <?php foreach ($organizations as $org): ?>
              <a class="org-card" href="<?= Helpers::e(Helpers::url('organization.php?id=' . (int) $org['id'])) ?>">
                <div class="org-cell">
                  <?= ui_org_badge($org, 44) ?>
                  <div>
                    <h3><?= Helpers::e((string) $org['name']) ?></h3>
                    <?= ui_org_acronym($org) ?>
                  </div>
                </div>
                <?= ui_org_desc($org, 96) ?>
                <div class="meta">
                  <?= ui_status_badge((string) $org['accreditation_status']) ?>
                  <span><?= icon('users', 13) ?> <?= (int) $org['member_count'] ?> members</span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <div>
        <section class="card">
          <div class="card-head"><h3>Announcements</h3></div>
          <?php if ($announcements === []): ?>
            <?= ui_empty('No announcements right now.', '', 'megaphone') ?>
          <?php else: ?>
            <ul class="timeline">
              <?php foreach ($announcements as $item): ?>
                <li>
                  <div class="tl-time"><?= Helpers::e(Helpers::fmtDate((string) $item['publish_date'])) ?></div>
                  <div class="tl-title"><?= Helpers::e((string) $item['title']) ?></div>
                  <div class="tl-desc"><?= Helpers::e(Helpers::excerpt((string) $item['content'], 150)) ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>

        <section class="card">
          <div class="card-head"><h3>Upcoming events</h3></div>
          <?php if ($events === []): ?>
            <?= ui_empty('No approved events scheduled.', '', 'calendar') ?>
          <?php else: ?>
            <table class="tbl compact">
              <tbody>
                <?php foreach ($events as $event): ?>
                  <tr>
                    <td class="nowrap">
                      <strong><?= Helpers::e(Helpers::fmtDate((string) $event['event_date'], 'M j')) ?></strong><br>
                      <span class="muted small"><?= Helpers::e(Helpers::fmtTime((string) $event['start_time'])) ?></span>
                    </td>
                    <td>
                      <strong><?= Helpers::e((string) $event['title']) ?></strong><br>
                      <span class="muted small"><?= Helpers::e((string) $event['organization_name']) ?> · <?= Helpers::e((string) $event['venue']) ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </section>

        <section class="card">
          <div class="card-head"><h3>About this system</h3></div>
          <dl class="detail-list">
            <dt>Institution</dt><dd><?= Helpers::e(SCHOOL_NAME) ?></dd>
            <dt>Campus</dt><dd><?= Helpers::e(SCHOOL_CAMPUS) ?></dd>
            <dt>Academic year</dt><dd><?= Helpers::e((string) ($year['name'] ?? '—')) ?></dd>
            <dt>Modules</dt><dd>Organizations · Membership · Officers · Events · QR attendance · Projects · Proposals · Documents · Announcements · Reports</dd>
          </dl>
        </section>
      </div>
    </div>
  </main>

  <footer class="site">
    <div class="foot-wrap">
      <div>
        <strong><?= Helpers::e(APP_NAME) ?></strong> · v<?= Helpers::e(APP_VERSION) ?><br>
        <span class="muted"><?= Helpers::e(SCHOOL_NAME) ?> · <?= Helpers::e(SCHOOL_CAMPUS) ?></span>
      </div>
      <div class="muted small">
        <?= Auth::check()
            ? 'Signed in as ' . Helpers::e(Auth::roleLabel()) . '.'
            : 'Sign in to apply for membership, register for events and check in with your QR code.' ?>
      </div>
    </div>
  </footer>
  <script src="<?= Helpers::e(Helpers::url('assets/js/app.js')) ?>" defer></script>
</body>
</html>

