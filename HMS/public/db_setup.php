<?php
/**
 * One-time PostgreSQL schema setup in the browser (no psql needed).
 *
 * 1. Set HMS_DB_SETUP_KEY in Render Environment (e.g. hms-db-setup-2026)
 * 2. Visit: https://YOUR_APP.onrender.com/db_setup.php?key=YOUR_KEY
 * 3. Clear HMS_DB_SETUP_KEY when done.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/db_setup.php';

header('Content-Type: text/html; charset=utf-8');

$setupKey = defined('HMS_DB_SETUP_KEY') ? (string) HMS_DB_SETUP_KEY : '';
if ($setupKey === '') {
    http_response_code(403);
    echo '<p>Database setup is disabled. Set <code>HMS_DB_SETUP_KEY</code> in Render Environment, redeploy, then open this page again.</p>';
    exit;
}

$key = (string) ($_GET['key'] ?? '');
if (!hash_equals($setupKey, $key)) {
    http_response_code(403);
    echo '<p>Forbidden. Add <code>?key=YOUR_HMS_DB_SETUP_KEY</code> to the URL.</p>';
    exit;
}

if (!hms_is_pgsql()) {
    http_response_code(400);
    echo '<p>This installer is for PostgreSQL only. Set <code>HMS_DB_DRIVER=pgsql</code> and <code>DATABASE_URL</code>.</p>';
    exit;
}

echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<title>Database setup</title>';
echo '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:2rem auto;padding:0 1rem;line-height:1.5}';
echo '.ok{color:#0a7}.err{color:#c00}code{background:#f4f4f4;padding:.15rem .35rem;border-radius:4px}</style></head><body>';

try {
    $db = hms_db();
    $hadUsers = hms_schema_users_table_exists($db);

    echo '<h1>PostgreSQL schema setup</h1>';

    if ($hadUsers && empty($_GET['force'])) {
        echo '<p class="ok">Tables already exist (<code>users</code> found).</p>';
        echo '<p><a href="' . htmlspecialchars(hms_url('login.php'), ENT_QUOTES, 'UTF-8') . '">Go to login</a></p>';
        echo '<p>To run schema again anyway: <code>?key=...&amp;force=1</code></p>';
        echo '</body></html>';
        exit;
    }

    $result = hms_run_postgresql_schema($db);
    $userCount = 0;
    if (hms_schema_users_table_exists($db)) {
        $userCount = (int) $db->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
    }

    echo '<p class="ok"><strong>Done.</strong> Executed ' . (int) $result['executed'] . ' statement(s)';
    if ($result['skipped'] > 0) {
        echo ', skipped ' . (int) $result['skipped'] . ' (already existed)';
    }
    echo '.</p>';

    if ($result['errors'] !== []) {
        echo '<h2>Warnings / errors</h2><ul class="err">';
        foreach ($result['errors'] as $err) {
            echo '<li>' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul>';
    }

    echo '<p>Users in database: <strong>' . $userCount . '</strong></p>';
    echo '<ul>';
    echo '<li><a href="' . htmlspecialchars(hms_url('register.php'), ENT_QUOTES, 'UTF-8') . '">Student register</a></li>';
    echo '<li><a href="' . htmlspecialchars(hms_url('login.php'), ENT_QUOTES, 'UTF-8') . '">Login</a></li>';
    echo '<li><a href="' . htmlspecialchars(rtrim(HMS_APP_URL, '/') . '/superadmin/login.php', ENT_QUOTES, 'UTF-8') . '">Super admin login</a></li>';
    echo '</ul>';
    echo '<p><strong>Security:</strong> Remove <code>HMS_DB_SETUP_KEY</code> from Render Environment (set to empty) and redeploy.</p>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Setup failed</h1>';
    echo '<p class="err">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p>Check <code>DATABASE_URL</code> uses the <strong>External</strong> Postgres host (with <code>.oregon-postgres.render.com</code>).</p>';
}

echo '</body></html>';
