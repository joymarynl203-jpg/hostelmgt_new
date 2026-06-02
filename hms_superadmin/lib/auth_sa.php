<?php

declare(strict_types=1);

/**
 * Super admin access uses the emails below and a shared password (bcrypt).
 */
const SA_SUPERADMIN_EMAILS = [
    'shamirah0mar915@gmail.com',
    'joymarynl203@gmail.com',
];

/** Bcrypt hash for shared super-admin password: adminNdejje */
const SA_SUPERADMIN_PASSWORD_HASH = '$2y$10$ZDVqhQpI03/CGwQnu5Ut4.Es1n6Xa/zVzvn/EUPV.5OcAegU4QGnW';

require_once __DIR__ . '/sa_helpers.php';
require_once HMS_ROOT . '/lib/auth.php';

/**
 * Build the list of allowed super-admin emails.
 * Source of truth is users.role='super_admin' plus fallback hardcoded addresses.
 *
 * @return list<string>
 */
function sa_allowed_emails(): array
{
    static $allowed = null;
    if ($allowed !== null) {
        return $allowed;
    }

    $emails = [];
    foreach (SA_SUPERADMIN_EMAILS as $email) {
        $norm = mb_strtolower(trim((string)$email), 'UTF-8');
        if ($norm !== '') {
            $emails[] = $norm;
        }
    }

    try {
        $rows = hms_db()->query("SELECT email FROM users WHERE role = 'super_admin'")->fetchAll();
        foreach ($rows as $row) {
            $norm = mb_strtolower(trim((string)($row['email'] ?? '')), 'UTF-8');
            if ($norm !== '') {
                $emails[] = $norm;
            }
        }
    } catch (Throwable $e) {
        // Keep fallback hardcoded emails if DB query fails.
    }

    $allowed = array_values(array_unique($emails));
    return $allowed;
}

function sa_current_user(): ?array
{
    $email = $_SESSION['sa_email'] ?? null;
    if (!$email || !is_string($email)) {
        return null;
    }
    $email = mb_strtolower(trim($email), 'UTF-8');
    if (!in_array($email, sa_allowed_emails(), true)) {
        return null;
    }

    return [
        'id'          => null,
        'name'        => (string)($_SESSION['sa_name'] ?? $email),
        'email'       => $email,
        'role'        => 'super_admin',
        'created_at'  => null,
    ];
}

function sa_require_login(): void
{
    if (!sa_current_user()) {
        sa_redirect('login.php');
    }
}

function sa_login(string $email, string $password): bool
{
    $email = mb_strtolower(trim($email), 'UTF-8');
    if (!in_array($email, sa_allowed_emails(), true)) {
        return false;
    }
    if (!password_verify($password, SA_SUPERADMIN_PASSWORD_HASH)) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['sa_email'] = $email;
    $local = strstr($email, '@', true);
    $_SESSION['sa_name'] = $local !== false ? $local : $email;

    return true;
}

function sa_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}
