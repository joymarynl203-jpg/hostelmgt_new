<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth_sa.php';

$u = sa_current_user();
if ($u) {
    hms_audit_log(null, 'sa_logout', 'session', null, 'Super admin signed out: ' . ($u['email'] ?? ''));
}
sa_logout();
sa_redirect('login.php');
