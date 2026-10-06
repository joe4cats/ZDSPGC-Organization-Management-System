<?php
/**
 * organization.php — public profile of one student organization.
 *
 * Deliberately shows no private data: no member list, no officer names, no
 * internal notes. Only documents the organization marked public + approved.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$org = null;
if (($code = Helpers::get('code')) !== null && $code !== '') {
    $org = OrgRepo::findByCode($code);
}
if ($org === null && Helpers::getInt('id') > 0) {
    $org = OrgRepo::findPublic(Helpers::getInt('id'));
}

$documents = $org === null ? [] : Database::all(
    'SELECT title, document_type, file_name, uploaded_at FROM documents
      WHERE organization_id = :o AND is_public = 1 AND status = "approved"
      ORDER BY uploaded_at DESC LIMIT 10',
    ['o' => (int) $org['id']]
);

$upcoming = $org === null ? [] : EventRepo::upcoming(4, (int) $org['id']);

$PAGE_TITLE  = $org === null ? 'Organization not found' : (string) $org['name'];
$PAGE_ACTIVE = '';
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('organizations.php')) . '">'
    . icon('chevron-left', 16) . '<span>All organizations</span></a>';

require __DIR__ . '/includes/layout/header.php';
?>

<div class="public-page">
  <div class="public-nav">
    <a class="brand" href="<?= Helpers::e(Helpers::url('index.php')) ?>">
      <img class="brand-logo" src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>" alt="ZDSPGC seal" width="38" height="38">
      <span class="brand-text"><span class="name">ZDSPGC OrgSys</span><span class="sub">Organization profile</span></span>
    </a>
    <div class="btn-row">
      <a class="btn ghost sm" href="<?= Helpers::e(Helpers::url('organizations.php')) ?>">Directory</a>
      <?php if ($org !== null): ?>
        <a class="btn sm" href="<?= Helpers::e(Helpers::url(
            Auth::check() && Auth::role() === 'student' ? 'student/organizations.php' : 'login.php'
        )) ?>"><?= icon(Auth::check() ? 'user-check' : 'logout', 15) ?><span>Apply for membership</span></a>
      <?php endif; ?>
    </div>
  </div>

  <div class="public-body">
    <?php if ($org === null): ?>
      <section class="card">
        <?= ui_empty('This organization is not available.', 'It may be archived, suspended or the link is incorrect.', 'org') ?>
        <div class="btn-row center">
          <a class="btn" href="<?= Helpers::e(Helpers::url('organizations.php')) ?>">Back to the directory</a>
        </div>
      </section>
    <?php else: ?>
      <section class="card">
        <div class="org-cell gap-wide">
          <?= ui_org_badge($org, 72) ?>
          <div>
            <h1 class="mb-tight"><?= Helpers::e((string) $org['name']) ?></h1>
            <p class="muted no-gap">
              <?= Helpers::e((string) $org['acronym']) ?>
              <?php if ((string) $org['organization_type'] !== ''): ?>
                · <?= Helpers::e((string) $org['organization_type']) ?> organization
              <?php endif; ?>
            </p>
            <div class="card-head spaced-head">
              <?= ui_status_badge((string) $org['accreditation_status']) ?>
              <?php if ((string) $org['category_name'] !== ''): ?>
                <?= ui_badge((string) $org['category_name'], 'blue') ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php if ((string) $org['description'] !== ''): ?>
          <p class="mt"><?= nl2br(Helpers::e((string) $org['description'])) ?></p>
        <?php endif; ?>
      </section>

      <div class="grid cols-2">
        <section class="card">
          <div class="card-head"><h3>Organization details</h3></div>
          <dl class="detail-list">
            <dt>Organization code</dt><dd><code><?= Helpers::e((string) $org['organization_code']) ?></code></dd>
            <dt>Type</dt><dd><?= Helpers::e((string) $org['organization_type']) ?></dd>
            <?php if ((string) $org['category_name'] !== ''): ?>
              <dt>Category</dt><dd><?= Helpers::e((string) $org['category_name']) ?></dd>
            <?php endif; ?>
            <?php if ((string) $org['department_name'] !== ''): ?>
              <dt>Department</dt><dd><?= Helpers::e((string) $org['department_name']) ?></dd>
            <?php endif; ?>
            <?php if ((string) $org['adviser_name'] !== ''): ?>
              <dt>Faculty adviser</dt><dd><?= Helpers::e((string) $org['adviser_name']) ?></dd>
            <?php endif; ?>
            <dt>Date established</dt><dd><?= Helpers::e(Helpers::fmtDate((string) $org['date_established'])) ?></dd>
            <dt>Accreditation</dt>
            <dd>
              <?= ui_status_badge((string) $org['accreditation_status']) ?>
              <?php if (($org['accreditation_expires_at'] ?? null) !== null): ?>
                <span class="muted small">until <?= Helpers::e(Helpers::fmtDate((string) $org['accreditation_expires_at'])) ?></span>
              <?php endif; ?>
            </dd>
            <?php if ((string) $org['contact_email'] !== ''): ?>
              <dt>Contact e-mail</dt><dd><?= Helpers::e((string) $org['contact_email']) ?></dd>
            <?php endif; ?>
            <?php if ((string) $org['social_link'] !== ''): ?>
              <dt>Social media</dt>
              <dd><a href="<?= Helpers::e((string) $org['social_link']) ?>" rel="noopener noreferrer" target="_blank">Visit page</a></dd>
            <?php endif; ?>
          </dl>
          <p class="hint mt">Member names and internal records are not published. Sign in to apply for membership.</p>
        </section>

        <div>
          <section class="card">
            <div class="card-head"><h3>Upcoming activities</h3></div>
            <?php if ($upcoming === []): ?>
              <?= ui_empty('No approved activity is scheduled.', '', 'calendar') ?>
            <?php else: ?>
              <ul class="timeline">
                <?php foreach ($upcoming as $event): ?>
                  <li>
                    <div class="tl-time"><?= Helpers::e(Helpers::fmtDate((string) $event['event_date'], 'l, M j, Y')) ?>
                      · <?= Helpers::e(Helpers::fmtTime((string) $event['start_time'])) ?></div>
                    <div class="tl-title"><?= Helpers::e((string) $event['title']) ?></div>
                    <div class="tl-desc"><?= Helpers::e((string) $event['event_type']) ?> · <?= Helpers::e((string) $event['venue']) ?></div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>

          <section class="card">
            <div class="card-head"><h3>Public documents</h3></div>
            <?php if ($documents === []): ?>
              <?= ui_empty('No public document is available for this organization.', '', 'folder') ?>
            <?php else: ?>
              <table class="tbl compact">
                <tbody>
                  <?php foreach ($documents as $doc): ?>
                    <tr>
                      <td><strong><?= Helpers::e((string) $doc['title']) ?></strong><br>
                          <span class="muted small"><?= Helpers::e((string) $doc['document_type']) ?></span></td>
                      <td class="small nowrap right"><?= Helpers::e(Helpers::fmtDate((string) $doc['uploaded_at'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </section>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
