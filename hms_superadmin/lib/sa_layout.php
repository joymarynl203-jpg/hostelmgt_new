<?php

declare(strict_types=1);

require_once __DIR__ . '/sa_helpers.php';
require_once __DIR__ . '/auth_sa.php';

function sa_layout_header(string $title): void
{
    $user = sa_current_user();
    $stylesVersion = (string)(@filemtime(HMS_ROOT . '/public/styles.css') ?: time());
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo e($title); ?> · HMS Super Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo e(hms_url('styles.css?v=' . $stylesVersion)); ?>" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark shadow-sm">
    <div class="container-fluid">
        <span class="navbar-brand mb-0 h1">HMS Super Admin</span>
        <?php if ($user): ?>
            <div class="d-flex align-items-center gap-3 text-white-50 small">
                <span><?php echo e($user['name']); ?></span>
                <a class="btn btn-outline-light btn-sm" href="<?php echo e(sa_url('logout.php')); ?>">Log out</a>
            </div>
        <?php endif; ?>
    </div>
</nav>
<main class="container py-4">
    <?php flash_render(); ?>
    <?php
}

function sa_layout_footer(): void
{
    $appJsVersion = (string)(@filemtime(HMS_ROOT . '/public/app.js') ?: time());
    ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo e(hms_url('app.js?v=' . $appJsVersion)); ?>"></script>
</body>
</html>
    <?php
}
