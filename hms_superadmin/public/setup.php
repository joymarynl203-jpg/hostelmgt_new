<?php

declare(strict_types=1);

/**
 * First-time database setup was removed: super admin sign-in uses fixed accounts in lib/auth_sa.php only.
 */
require_once __DIR__ . '/../lib/sa_helpers.php';

flash_set('error', 'Super admin accounts are fixed in configuration. Use the login page with your authorised email.');
sa_redirect('login.php');
