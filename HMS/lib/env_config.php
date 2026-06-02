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

function hms_apply_env_config(): void
{
    $map = [
        'HMS_DB_HOST'               => 'HMS_DB_HOST',
        'HMS_DB_NAME'               => 'HMS_DB_NAME',
        'HMS_DB_USER'               => 'HMS_DB_USER',
        'HMS_DB_PASS'               => 'HMS_DB_PASS',
        'HMS_BASE_URL'              => 'HMS_BASE_URL',
        'HMS_APP_URL'               => 'HMS_APP_URL',
        'HMS_PESAPAL_ENV'           => 'HMS_PESAPAL_ENV',
        'HMS_PESAPAL_CONSUMER_KEY'  => 'HMS_PESAPAL_CONSUMER_KEY',
        'HMS_PESAPAL_CONSUMER_SECRET' => 'HMS_PESAPAL_CONSUMER_SECRET',
        'HMS_PESAPAL_IPN_ID'        => 'HMS_PESAPAL_IPN_ID',
        'HMS_GOOGLE_MAPS_API_KEY'   => 'HMS_GOOGLE_MAPS_API_KEY',
        'HMS_DEMO_SETUP_KEY'        => 'HMS_DEMO_SETUP_KEY',
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
