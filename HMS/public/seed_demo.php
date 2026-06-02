<?php
/**
 * One-click demo account reset (browser).
 * Visit: /HMS/public/seed_demo.php?key=YOUR_HMS_DEMO_SETUP_KEY
 * Delete this file or set HMS_DEMO_SETUP_KEY to '' in production.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/demo_seed.php';

header('Content-Type: text/html; charset=utf-8');

if (!defined('HMS_DEMO_SETUP_KEY') || HMS_DEMO_SETUP_KEY === '') {
    http_response_code(403);
    echo '<p>Demo seed is disabled. Set HMS_DEMO_SETUP_KEY in config.php.</p>';
    exit;
}

$key = (string)($_GET['key'] ?? '');
if (!hash_equals(HMS_DEMO_SETUP_KEY, $key)) {
    http_response_code(403);
    echo '<p>Forbidden. Invalid key.</p>';
    exit;
}

try {
    $db = hms_db();
    $accounts = demo_seed_demo_accounts($db);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Demo accounts ready</title></head><body style="font-family:system-ui;padding:2rem;">';
    echo '<h1>Demo accounts updated</h1>';
    echo '<p>You can log in with:</p>';
    echo '<ul>';
    echo '<li><strong>University Admin</strong> — university.admin@hms.local / Admin12345</li>';
    echo '<li><strong>Warden</strong> — warden@hms.local / Warden12345</li>';
    echo '</ul>';
    echo '<p><a href="' . htmlspecialchars(hms_url('login.php'), ENT_QUOTES, 'UTF-8') . '">Go to login</a></p>';
    echo '<p style="color:#666;font-size:0.9rem;">Remove <code>public/seed_demo.php</code> or clear HMS_DEMO_SETUP_KEY after setup.</p>';
    echo '</body></html>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<p>Database error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p>Check <code>config.php</code> (MySQL name/user/password) and import <code>database/schema.sql</code>.</p>';
}
