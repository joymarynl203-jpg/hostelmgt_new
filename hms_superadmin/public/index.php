<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth_sa.php';

if (sa_current_user()) {
    sa_redirect('admins.php');
}

sa_redirect('login.php');
