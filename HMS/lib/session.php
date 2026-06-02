<?php

// Centralized session configuration for the entire app.
if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/db_dialect.php';

    if (hms_use_database_sessions()) {
        require_once __DIR__ . '/../db.php';
        require_once __DIR__ . '/session_handler_db.php';
        session_set_save_handler(new HmsDatabaseSessionHandler(hms_db()), true);
    } else {
        $sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hms_sessions';
        if (!is_dir($sessionDir)) {
            @mkdir($sessionDir, 0775, true);
        }
        if (is_dir($sessionDir) && is_writable($sessionDir)) {
            session_save_path($sessionDir);
        }
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

    $cookiePath = '/';
    if (defined('HMS_BASE_URL')) {
        $base = trim((string) HMS_BASE_URL);
        if ($base !== '' && $base !== '/') {
            $cookiePath = '/' . trim($base, '/') . '/';
        }
    }

    $started = session_start([
        'cookie_httponly'  => true,
        'cookie_samesite'  => 'Lax',
        'cookie_secure'    => $https,
        'cookie_path'      => $cookiePath,
        'use_strict_mode'  => 1,
        'use_only_cookies' => 1,
    ]);
    if ($started !== true) {
        http_response_code(500);
        echo 'Session initialization failed.';
        exit;
    }
}
