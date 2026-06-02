<?php

declare(strict_types=1);

/**
 * Resolve HMS project root (sibling folder named `HMS` next to this app’s parent directory).
 * Layout: htdocs/HMS/ and htdocs/hms_superadmin/
 */
if (!defined('HMS_ROOT')) {
    $hmsRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'HMS';
    if (!is_dir($hmsRoot) || !is_file($hmsRoot . DIRECTORY_SEPARATOR . 'config.php')) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Super Admin portal: HMS project not found. Expected a folder named `HMS` next to this app (e.g. htdocs/HMS and htdocs/hms_superadmin).';
        exit;
    }
    define('HMS_ROOT', $hmsRoot);
}

require_once HMS_ROOT . '/config.php';

if (is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.sa.php')) {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.sa.php';
}

if (!defined('SA_BASE_URL')) {
    $saBase = getenv('SA_BASE_URL');
    if ($saBase !== false && $saBase !== '') {
        define('SA_BASE_URL', $saBase);
    } else {
        define('SA_BASE_URL', '/hms_superadmin/public/');
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hms_sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0775, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }

    session_name('HMS_SUPERADMIN');
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
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Super Admin portal: session initialization failed.';
        exit;
    }
}
