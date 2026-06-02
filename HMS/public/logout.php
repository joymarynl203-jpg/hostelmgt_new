<?php
require_once __DIR__ . '/../lib/auth.php';

auth_logout();
redirect_to(hms_url());

