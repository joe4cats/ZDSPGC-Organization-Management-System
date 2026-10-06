<?php
/**
 * switch-organization.php — change active organization workspace.
 *
 * Used by advisers and organization officers who manage or advise more than
 * one registered student organization.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireAuth();

if (Helpers::isPost()) {
    Security::requireCsrf();

    $orgId = Helpers::postInt('organization_id');
    if ($orgId > 0 && Permissions::setActiveOrganization($orgId)) {
        $org = Database::one('SELECT name FROM organizations WHERE id = :id', ['id' => $orgId]);
        Helpers::flash('success', 'Switched workspace to ' . (string) ($org['name'] ?? 'organization') . '.');
    } else {
        Helpers::flash('error', 'You are not authorized to switch to that organization workspace.');
    }
}

$back = (string) ($_SERVER['HTTP_REFERER'] ?? '');
if ($back !== '' && !str_contains($back, 'switch-organization.php')) {
    header('Location: ' . $back);
    exit;
}

Helpers::redirect(Permissions::homeForRole());
