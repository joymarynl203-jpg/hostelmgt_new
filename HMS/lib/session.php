<?php

// Centralized session configuration for the entire app.
// Using a single place ensures cookie options are consistent.
if (session_status() !== PHP_SESSION_ACTIVE) {
    // In container platforms (Render, Docker), default session paths can be read-only
    // or unavailable for www-data. Pin sessions to a writable location.
    $sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hms_sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0775, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    $started = session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure'   => $https,
        'use_strict_mode' => 1,
    ]);
    if ($started !== true) {
        http_response_code(500);
        echo 'Session initialization failed.';
        exit;
    }
}

