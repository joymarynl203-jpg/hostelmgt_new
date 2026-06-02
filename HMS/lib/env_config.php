<?php

/**
 * Define HMS constants from environment variables (Docker / Render).
 * Only applies when the constant is not already set (e.g. by config.local.php).
 */
function hms_env_define_string(string $constant, string $envKey): void
{
    if (defined($constant)) {
        return;
    }
    $value = getenv($envKey);
    if ($value !== false && $value !== '') {
        define($constant, $value);
    }
}

function hms_env_is_true(?string $value): bool
{
    if ($value === null || $value === '') {
        return false;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Parse Render/Heroku DATABASE_URL (postgres:// or postgresql://).
 */
function hms_apply_database_url(): void
{
    $url = getenv('DATABASE_URL');
    if ($url === false || $url === '') {
        return;
    }

    $normalized = preg_replace('#^postgres://#i', 'postgresql://', $url);
    if (!is_string($normalized)) {
        return;
    }

    $parts = parse_url($normalized);
    if ($parts === false || empty($parts['host'])) {
        return;
    }

    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    if (!defined('HMS_DB_DRIVER')) {
        define('HMS_DB_DRIVER', 'pgsql');
    }
    if (!defined('HMS_DB_HOST')) {
        define('HMS_DB_HOST', $parts['host']);
    }
    if (!defined('HMS_DB_PORT')) {
        define('HMS_DB_PORT', (int) ($parts['port'] ?? 5432));
    }
    if (!defined('HMS_DB_NAME')) {
        define('HMS_DB_NAME', ltrim((string) ($parts['path'] ?? ''), '/'));
    }
    if (!defined('HMS_DB_USER') && isset($parts['user'])) {
        define('HMS_DB_USER', rawurldecode((string) $parts['user']));
    }
    if (!defined('HMS_DB_PASS') && isset($parts['pass'])) {
        define('HMS_DB_PASS', rawurldecode((string) $parts['pass']));
    }
    if (!defined('HMS_DB_SSLMODE') && !empty($query['sslmode'])) {
        define('HMS_DB_SSLMODE', (string) $query['sslmode']);
    }
}

function hms_apply_env_config(): void
{
    hms_apply_database_url();

    $map = [
        'HMS_DB_DRIVER'             => 'HMS_DB_DRIVER',
        'HMS_DB_HOST'               => 'HMS_DB_HOST',
        'HMS_DB_SSLMODE'            => 'HMS_DB_SSLMODE',
        'HMS_DB_NAME'               => 'HMS_DB_NAME',
        'HMS_DB_USER'               => 'HMS_DB_USER',
        'HMS_DB_PASS'               => 'HMS_DB_PASS',
        'HMS_DB_SSL_CA'             => 'HMS_DB_SSL_CA',
        'HMS_BASE_URL'              => 'HMS_BASE_URL',
        'HMS_APP_URL'               => 'HMS_APP_URL',
        'HMS_PESAPAL_ENV'           => 'HMS_PESAPAL_ENV',
        'HMS_PESAPAL_CONSUMER_KEY'  => 'HMS_PESAPAL_CONSUMER_KEY',
        'HMS_PESAPAL_CONSUMER_SECRET' => 'HMS_PESAPAL_CONSUMER_SECRET',
        'HMS_PESAPAL_IPN_ID'        => 'HMS_PESAPAL_IPN_ID',
        'HMS_GOOGLE_MAPS_API_KEY'   => 'HMS_GOOGLE_MAPS_API_KEY',
        'HMS_DEMO_SETUP_KEY'        => 'HMS_DEMO_SETUP_KEY',
        'HMS_DB_SETUP_KEY'          => 'HMS_DB_SETUP_KEY',
        'HMS_MAIL_TRANSPORT'        => 'HMS_MAIL_TRANSPORT',
        'HMS_SMTP_HOST'             => 'HMS_SMTP_HOST',
        'HMS_SMTP_USER'             => 'HMS_SMTP_USER',
        'HMS_SMTP_PASS'             => 'HMS_SMTP_PASS',
        'HMS_SMTP_ENCRYPTION'       => 'HMS_SMTP_ENCRYPTION',
        'HMS_MAIL_FROM'             => 'HMS_MAIL_FROM',
        'HMS_MAIL_FROM_NAME'        => 'HMS_MAIL_FROM_NAME',
    ];

    foreach ($map as $constant => $envKey) {
        hms_env_define_string($constant, $envKey);
    }

    if (!defined('HMS_DB_PORT')) {
        $dbPort = getenv('HMS_DB_PORT');
        if ($dbPort !== false && $dbPort !== '') {
            define('HMS_DB_PORT', (int) $dbPort);
        }
    }

    if (!defined('HMS_DB_SSL')) {
        if (hms_env_is_true(getenv('HMS_DB_SSL') ?: null)) {
            define('HMS_DB_SSL', true);
        }
    }

    if (!defined('HMS_DB_SSL_VERIFY')) {
        $verify = getenv('HMS_DB_SSL_VERIFY');
        if ($verify === false || $verify === '') {
            define('HMS_DB_SSL_VERIFY', true);
        } else {
            define('HMS_DB_SSL_VERIFY', hms_env_is_true($verify));
        }
    }

    if (!defined('HMS_SMTP_PORT')) {
        $port = getenv('HMS_SMTP_PORT');
        if ($port !== false && $port !== '') {
            define('HMS_SMTP_PORT', (int) $port);
        }
    }

    if (!defined('HMS_APP_URL')) {
        $renderUrl = getenv('RENDER_EXTERNAL_URL');
        if ($renderUrl !== false && $renderUrl !== '') {
            define('HMS_APP_URL', rtrim($renderUrl, '/'));
        }
    }
}
