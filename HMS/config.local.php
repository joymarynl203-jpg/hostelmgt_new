<?php
/**
 * Local / server overrides (not committed — listed in .gitignore).
 *
 * For production: switch HMS_APP_URL to https://your-domain, HMS_BASE_URL if needed,
 * set HMS_PESAPAL_ENV to live, paste live keys + HMS_PESAPAL_IPN_ID, use a strong DB user/password,
 * and set HMS_DEMO_SETUP_KEY to '' before production.
 */

define('HMS_DB_HOST', 'localhost');
define('HMS_DB_NAME', 'hms_db');
define('HMS_DB_USER', 'root');
define('HMS_DB_PASS', '');

define('HMS_BASE_URL', '/HMS/public/');
define('HMS_APP_URL', 'http://localhost');

// Use 'sandbox' only with Pesapal-published demo keys. Merchant dashboard keys are usually 'live'.
define('HMS_PESAPAL_ENV', 'live');
define('HMS_PESAPAL_CONSUMER_KEY', 'sFwgynLw9LZFr6qOUWhvhHQqNVb0bVTo');
define('HMS_PESAPAL_CONSUMER_SECRET', 'fkQvNBXeweFLv5kW72CWnRqv8fM=');
define('HMS_PESAPAL_IPN_ID', '');

// Empty string disables public/seed_demo.php — use '' on production.
define('HMS_DEMO_SETUP_KEY', 'hms-demo-setup-2026');

define('HMS_GOOGLE_MAPS_API_KEY', 'AIzaSyDxxLC0N1nQ43rZIJhtpaYDNIoA8q2phpk');
