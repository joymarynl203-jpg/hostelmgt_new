<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';

/** Roles allowed to use forgot-password (same as main HMS login, excluding super_admin). */
function hms_password_reset_roles(): array
{
    return ['student', 'warden', 'university_admin'];
}

function hms_password_reset_normalize_email(string $email): string
{
    return mb_strtolower(trim($email), 'UTF-8');
}

/** Returns user row if eligible for password reset; otherwise null (no information leak to caller). */
function hms_password_reset_find_eligible_user(string $email): ?array
{
    $email = hms_password_reset_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $stmt = hms_db()->prepare('SELECT id, email, role, is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }

    if (!in_array($user['role'], hms_password_reset_roles(), true)) {
        return null;
    }

    if ($user['role'] === 'student' && (int) ($user['is_active'] ?? 1) !== 1) {
        return null;
    }

    return $user;
}

/** Creates a new token for the user; invalidates previous tokens. Returns raw token for email, or null on failure. */
function hms_password_reset_issue_token(int $userId): ?string
{
    $db = hms_db();
    $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$userId]);

    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw, false);

    try {
        $db->prepare('
            INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
            VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))
        ')->execute([$userId, $hash]);
    } catch (PDOException $e) {
        error_log('HMS password_reset token insert failed: ' . $e->getMessage());

        return null;
    }

    return $raw;
}

/** Returns user id if token is valid and not expired; otherwise null. */
function hms_password_reset_lookup_user_id(string $rawToken): ?int
{
    $rawToken = trim($rawToken);
    if ($rawToken === '' || strlen($rawToken) !== 64 || !ctype_xdigit($rawToken)) {
        return null;
    }

    $hash = hash('sha256', $rawToken, false);
    $stmt = hms_db()->prepare('
        SELECT user_id FROM password_reset_tokens
        WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP()
        LIMIT 1
    ');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    return $row ? (int) $row['user_id'] : null;
}

function hms_password_reset_clear_for_user(int $userId): void
{
    hms_db()->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$userId]);
}

function hms_password_reset_clear_token(string $rawToken): void
{
    $rawToken = trim($rawToken);
    if ($rawToken === '' || strlen($rawToken) !== 64 || !ctype_xdigit($rawToken)) {
        return;
    }
    $hash = hash('sha256', $rawToken, false);
    hms_db()->prepare('DELETE FROM password_reset_tokens WHERE token_hash = ?')->execute([$hash]);
}

/** Sets new password for user after token validation; clears tokens for user. Returns false if password too short. */
function hms_password_reset_apply(int $userId, string $newPassword): bool
{
    if (mb_strlen($newPassword) < 8) {
        return false;
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    hms_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
    hms_password_reset_clear_for_user($userId);
    hms_audit_log(null, 'password_reset', 'user', $userId, 'Password reset completed via email link.');

    return true;
}
