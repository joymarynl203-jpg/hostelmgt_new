<?php
/**
 * HMS base configuration.
 *
 * Production: copy `config.local.php.example` to `config.local.php`, fill in DB, HMS_APP_URL,
 * live Pesapal keys, and IPN id. Do not commit `config.local.php`.
 */
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

require_once __DIR__ . '/lib/env_config.php';
hms_apply_env_config();

if (!defined('HMS_DB_HOST')) {
    define('HMS_DB_HOST', 'localhost');
}
if (!defined('HMS_DB_NAME')) {
    define('HMS_DB_NAME', 'hms_db');
}
if (!defined('HMS_DB_USER')) {
    define('HMS_DB_USER', 'root');
}
if (!defined('HMS_DB_PASS')) {
    define('HMS_DB_PASS', '');
}

/** Web path to `public/` (leading slash, trailing slash). On production often `/` if the vhost root is `public/`. */
if (!defined('HMS_BASE_URL')) {
    define('HMS_BASE_URL', '/HMS/public/');
}

/** Public site origin, no trailing slash. Must match the URL users use (HTTPS). Used for Pesapal callback/IPN. */
if (!defined('HMS_APP_URL')) {
    define('HMS_APP_URL', 'http://localhost');
}

// Pesapal API 3.0 — https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/api-reference
if (!defined('HMS_PESAPAL_ENV')) {
    define('HMS_PESAPAL_ENV', 'sandbox'); // sandbox | live
}
if (!defined('HMS_PESAPAL_CONSUMER_KEY')) {
    define('HMS_PESAPAL_CONSUMER_KEY', 'YOUR_PESAPAL_CONSUMER_KEY');
}
if (!defined('HMS_PESAPAL_CONSUMER_SECRET')) {
    define('HMS_PESAPAL_CONSUMER_SECRET', 'YOUR_PESAPAL_CONSUMER_SECRET');
}
if (!defined('HMS_PESAPAL_IPN_ID')) {
    define('HMS_PESAPAL_IPN_ID', '');
}

/** Google Maps browser key (optional). Enable Maps JavaScript API + Maps Embed API; restrict by HTTP referrer. */
if (!defined('HMS_GOOGLE_MAPS_API_KEY')) {
    define('HMS_GOOGLE_MAPS_API_KEY', '');
}

/** Non-empty enables browser demo seed at public/seed_demo.php — use empty string in production. */
if (!defined('HMS_DEMO_SETUP_KEY')) {
    define('HMS_DEMO_SETUP_KEY', 'hms-demo-setup-2026');
}

/**
 * Outbound email for forgot-password and similar.
 * Transport: smtp (recommended) requires HMS_SMTP_HOST; php_mail uses PHP mail().
 */
if (!defined('HMS_MAIL_TRANSPORT')) {
    define('HMS_MAIL_TRANSPORT', 'smtp'); // smtp | php_mail
}
if (!defined('HMS_SMTP_HOST')) {
    define('HMS_SMTP_HOST', '');
}
if (!defined('HMS_SMTP_PORT')) {
    define('HMS_SMTP_PORT', 587);
}
if (!defined('HMS_SMTP_USER')) {
    define('HMS_SMTP_USER', '');
}
if (!defined('HMS_SMTP_PASS')) {
    define('HMS_SMTP_PASS', '');
}
/** tls (STARTTLS on 587), ssl (SMTPS on 465), none (plain, e.g. local relay). */
if (!defined('HMS_SMTP_ENCRYPTION')) {
    define('HMS_SMTP_ENCRYPTION', 'tls');
}
if (!defined('HMS_SMTP_VERIFY_PEER')) {
    define('HMS_SMTP_VERIFY_PEER', true);
}
if (!defined('HMS_MAIL_FROM')) {
    define('HMS_MAIL_FROM', 'noreply@localhost');
}
if (!defined('HMS_MAIL_FROM_NAME')) {
    define('HMS_MAIL_FROM_NAME', 'Hostel Management System');
}

/** Footer social: WhatsApp chat (wa.me, digits only, no +). */
if (!defined('HMS_SOCIAL_WHATSAPP')) {
    define('HMS_SOCIAL_WHATSAPP', '256787221531');
}
/** Footer social: Gmail compose recipient. */
if (!defined('HMS_SOCIAL_GMAIL')) {
    define('HMS_SOCIAL_GMAIL', 'shamirah0mar915@gmail.com');
}
/** Full profile URLs — override in config.local.php when available. */
if (!defined('HMS_SOCIAL_TIKTOK_URL')) {
    define('HMS_SOCIAL_TIKTOK_URL', '');
}
if (!defined('HMS_SOCIAL_X_URL')) {
    define('HMS_SOCIAL_X_URL', '');
}

function hms_url(string $path = ''): string
{
    $base = rtrim(HMS_BASE_URL, '/');
    $path = ltrim($path, '/');
    return $base . ($path ? '/' . $path : '');
}

function hms_abs_url(string $path = ''): string
{
    return rtrim(HMS_APP_URL, '/') . hms_url($path);
}
