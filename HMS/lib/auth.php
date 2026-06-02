<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';

function hms_current_user(): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return null;
    }

    // No per-process static cache: fields like is_active must reflect the database immediately
    // (e.g. after an admin deactivates a student while a PHP worker stays warm).
    $stmt = hms_db()->prepare('SELECT id, name, email, role, created_at, is_active, institution, nin FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function require_login(): void
{
    $user = hms_current_user();
    if (!$user) {
        redirect_to(hms_url('login.php'));
    }
    if ($user['role'] === 'student' && (int)($user['is_active'] ?? 1) !== 1) {
        auth_logout();
        flash_set('error', 'Your account has been deactivated. Contact your university administrator.');
        redirect_to(hms_url('login.php'));
    }
}

function require_role(array $roles): void
{
    $user = hms_current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function auth_login(string $email, string $password): bool
{
    $GLOBALS['hms_auth_login_student_inactive'] = false;
    $GLOBALS['hms_auth_login_super_admin_portal'] = false;
    $email = mb_strtolower(trim($email), 'UTF-8');
    $stmt = hms_db()->prepare('SELECT id, name, email, password_hash, role, is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    if (($user['role'] ?? '') === 'super_admin') {
        $GLOBALS['hms_auth_login_super_admin_portal'] = true;
        return false;
    }

    if ($user['role'] === 'student' && (int)($user['is_active'] ?? 1) !== 1) {
        $GLOBALS['hms_auth_login_student_inactive'] = true;
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['name'] = $user['name'];

    return true;
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function auth_register_student(string $name, string $email, string $password, string $institution, string $phone, ?string $regNo = null): bool
{
    $email = mb_strtolower(trim($email), 'UTF-8');
    $institution = trim($institution);
    $phone = trim($phone);
    if (mb_strlen($name) < 2) {
        return false;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (mb_strlen($password) < 8) {
        return false;
    }
    if (mb_strlen($institution) < 2 || mb_strlen($institution) > 200) {
        return false;
    }
    if (mb_strlen($phone) < 7 || mb_strlen($phone) > 30) {
        return false;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = hms_db()->prepare('INSERT INTO users (name, email, password_hash, role, reg_no, nin, phone, institution) VALUES (?, ?, ?, "student", ?, NULL, ?, ?)');
    try {
        return $stmt->execute([$name, $email, $hash, $regNo !== '' ? $regNo : null, $phone, $institution]);
    } catch (PDOException $e) {
        return false;
    }
}

function auth_create_user(string $name, string $email, string $password, string $role, ?string $regNo = null, ?string $phone = null, ?string $nin = null): bool
{
    $email = mb_strtolower(trim($email), 'UTF-8');
    if (!in_array($role, ['student', 'warden', 'university_admin'], true)) {
        return false;
    }
    if (mb_strlen($name) < 2) {
        return false;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (mb_strlen($password) < 8) {
        return false;
    }

    $nin = $nin !== null ? trim($nin) : '';
    if ($role === 'warden' || $role === 'university_admin') {
        if ($nin === '' || mb_strlen($nin) < 8 || mb_strlen($nin) > 28 || !preg_match('/^[A-Za-z0-9\-]+$/', $nin)) {
            return false;
        }
    } else {
        $nin = $nin !== '' ? $nin : null;
        if ($nin !== null && (mb_strlen($nin) > 28 || !preg_match('/^[A-Za-z0-9\-]+$/', $nin))) {
            return false;
        }
    }

    if ($role === 'university_admin') {
        $phone = $phone !== null ? trim((string)$phone) : '';
        if ($phone === '' || mb_strlen($phone) < 7 || mb_strlen($phone) > 30) {
            return false;
        }
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = hms_db()->prepare('INSERT INTO users (name, email, password_hash, role, reg_no, nin, phone, institution) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)');
    try {
        $ninParam = ($role === 'warden' || $role === 'university_admin') ? $nin : (($nin !== null && $nin !== '') ? $nin : null);
        return $stmt->execute([$name, $email, $hash, $role, $regNo, $ninParam, $phone]);
    } catch (PDOException $e) {
        return false;
    }
}

function hms_audit_log(?int $actorUserId, string $action, string $entityType, ?int $entityId, ?string $details = null): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $stmt = hms_db()->prepare('
        INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, details, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$actorUserId, $action, $entityType, $entityId, $details, $ip, $ua]);
}

function hms_notify(int $userId, string $message, string $type = 'system'): void
{
    $stmt = hms_db()->prepare('
        INSERT INTO notifications (user_id, message, type, is_read)
        VALUES (?, ?, ?, 0)
    ');
    $stmt->execute([$userId, $message, $type]);
}

/** Notify staff when a student submits a maintenance ticket (includes full description). */
function hms_notify_maintenance_new_request(int $userId, string $title, string $description): void
{
    $body = 'New maintenance request: ' . $title . ".\n\nDescription:\n" . $description;
    hms_notify($userId, $body, 'maintenance');
}

/** Notify the student when staff change maintenance request status (includes title and description). */
function hms_notify_maintenance_status_changed(int $studentUserId, string $newStatus, string $title, string $description): void
{
    $parts = ['Maintenance request status updated to: ' . $newStatus . '.'];
    $title = trim($title);
    $description = trim($description);
    if ($title !== '') {
        $parts[] = 'Request: ' . $title;
    }
    if ($description !== '') {
        $parts[] = 'Description:';
        $parts[] = $description;
    }
    hms_notify($studentUserId, implode("\n\n", $parts), 'maintenance');
}

