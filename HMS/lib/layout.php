<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/footer.php';

function layout_header(string $title, array $options = []): void
{
    $user = hms_current_user();
    $isAuthenticated = (bool)$user;
    $GLOBALS['hms_layout_authenticated'] = $isAuthenticated;

    $navLinks = [];
    $ctaLink = null;
    if ($isAuthenticated) {
        if ($user['role'] === 'student') {
            $navLinks = [
                ['label' => 'Dashboard', 'href' => hms_url('dashboard.php')],
                ['label' => 'Notifications', 'href' => hms_url('notifications.php')],
                ['label' => 'Hostels', 'href' => hms_url('hostels.php')],
                ['label' => 'Bookings', 'href' => hms_url('bookings.php')],
                ['label' => 'Payments', 'href' => hms_url('my_payments.php')],
                ['label' => 'Maintenance', 'href' => hms_url('maintenance.php')],
            ];
            $ctaLink = ['label' => 'Browse hostels', 'href' => hms_url('hostels.php')];
        } elseif ($user['role'] === 'warden') {
            $navLinks = [
                ['label' => 'Dashboard', 'href' => hms_url('dashboard.php')],
                ['label' => 'Notifications', 'href' => hms_url('notifications.php')],
                ['label' => 'Hostels', 'href' => hms_url('admin/hostels.php')],
                ['label' => 'Approvals', 'href' => hms_url('admin/bookings.php')],
                ['label' => 'Students', 'href' => hms_url('admin/students_by_room.php')],
                ['label' => 'Maintenance', 'href' => hms_url('admin/maintenance.php')],
                ['label' => 'Payments', 'href' => hms_url('admin/payments.php')],
                ['label' => 'Reports', 'href' => hms_url('admin/reports.php')],
            ];
            $ctaLink = ['label' => 'Review bookings', 'href' => hms_url('admin/bookings.php')];
        } else {
            $navLinks = [
                ['label' => 'Dashboard', 'href' => hms_url('dashboard.php')],
                ['label' => 'Notifications', 'href' => hms_url('notifications.php')],
                ['label' => 'Hostels', 'href' => hms_url('admin/hostels.php')],
                ['label' => 'Rooms', 'href' => hms_url('admin/rooms.php')],
                ['label' => 'Students', 'href' => hms_url('admin/students_by_room.php')],
                ['label' => 'Reports', 'href' => hms_url('admin/reports.php')],
                ['label' => 'Payments', 'href' => hms_url('admin/payments.php')],
                ['label' => 'Wardens', 'href' => hms_url('admin/users.php')],
            ];
            $ctaLink = ['label' => 'Manage hostels', 'href' => hms_url('admin/hostels.php')];
        }
    }

    $activePath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $activeBase = basename($activePath);
    $bodyClass = isset($options['body_class']) ? trim((string)$options['body_class']) : '';
    if ($isAuthenticated && !empty($user['role'])) {
        $roleClass = 'hms-role-' . preg_replace('/[^a-z_]/', '', (string)$user['role']);
        $bodyClass = trim($bodyClass . ' hms-app-topnav ' . $roleClass);
    }
    $stylesVersion = (string)(@filemtime(__DIR__ . '/../public/styles.css') ?: time());
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?php echo e($title); ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="<?php echo hms_url('styles.css?v=' . $stylesVersion); ?>" rel="stylesheet">
    </head>
    <body<?php echo $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : ''; ?>>
    <?php if ($isAuthenticated): ?>
        <div class="app-shell app-shell--topnav">
            <header class="app-topnav sticky-top">
                <div class="container-fluid app-topnav-inner">
                    <a class="app-topnav-brand" href="<?php echo hms_url('dashboard.php'); ?>">
                        <span class="app-topnav-brand-mark" aria-hidden="true">H</span>
                        <span class="app-topnav-brand-text">Hostel MS</span>
                    </a>
                    <button class="navbar-toggler app-topnav-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#appTopNavMenu" aria-controls="appTopNavMenu" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse app-topnav-collapse" id="appTopNavMenu">
                        <nav class="app-topnav-menu" aria-label="Main">
                            <?php foreach ($navLinks as $link): ?>
                                <?php $isActive = basename(parse_url($link['href'], PHP_URL_PATH) ?: '') === $activeBase; ?>
                                <a class="app-topnav-link <?php echo $isActive ? 'active' : ''; ?>" href="<?php echo $link['href']; ?>">
                                    <?php echo e($link['label']); ?>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                        <div class="app-topnav-actions">
                            <?php if ($ctaLink !== null): ?>
                                <?php $ctaActive = basename(parse_url($ctaLink['href'], PHP_URL_PATH) ?: '') === $activeBase; ?>
                                <a class="btn app-topnav-cta <?php echo $ctaActive ? 'active' : ''; ?>" href="<?php echo $ctaLink['href']; ?>">
                                    <svg class="app-topnav-cta-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="12" y1="14" x2="12" y2="18"/><line x1="10" y1="16" x2="14" y2="16"/></svg>
                                    <?php echo e($ctaLink['label']); ?>
                                </a>
                            <?php endif; ?>
                            <div class="dropdown app-topnav-user">
                                <button class="app-topnav-user-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo e($user['name']); ?>">
                                    <span class="app-topnav-user-avatar" aria-hidden="true"><?php echo e(mb_strtoupper(mb_substr(trim((string)$user['name']), 0, 1, 'UTF-8'), 'UTF-8')); ?></span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm rounded-3 app-topnav-user-menu">
                                    <li class="px-3 py-2 border-bottom">
                                        <div class="fw-semibold small"><?php echo e($user['name']); ?></div>
                                        <div class="text-muted small text-capitalize"><?php echo e(str_replace('_', ' ', (string)$user['role'])); ?></div>
                                    </li>
                                    <li><a class="dropdown-item" href="<?php echo hms_url('change_password.php'); ?>">Change password</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <a class="dropdown-item text-danger" href="<?php echo hms_url('logout.php'); ?>"<?php echo hms_data_confirm('Log out? You will need to sign in again.'); ?>>Logout</a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </header>
            <main class="app-main">
                <div class="container-fluid app-main-inner py-4 px-lg-4 px-3">
                    <?php flash_render(); ?>
    <?php else: ?>
        <nav class="navbar navbar-expand-lg app-topnav app-topnav--guest sticky-top">
            <div class="container">
                <a class="app-topnav-brand" href="<?php echo hms_url(); ?>">
                    <span class="app-topnav-brand-mark" aria-hidden="true">H</span>
                    <span class="app-topnav-brand-text">Hostel MS</span>
                </a>
                <button class="navbar-toggler app-topnav-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain" aria-controls="navMain" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navMain">
                    <div class="app-topnav-actions ms-lg-auto">
                        <a class="app-topnav-link" href="<?php echo hms_url('login.php'); ?>">Login</a>
                        <a class="app-topnav-link" href="<?php echo hms_url('register.php'); ?>">Register</a>
                        <a class="btn app-topnav-cta" href="<?php echo hms_url('login.php'); ?>">Sign in</a>
                    </div>
                </div>
            </div>
        </nav>
        <div class="container app-main-inner py-4">
            <?php flash_render(); ?>
    <?php endif; ?>
    <?php
}

function layout_footer(): void
{
    $isAuthenticated = (bool)($GLOBALS['hms_layout_authenticated'] ?? false);
    ?>
    <?php if ($isAuthenticated): ?>
                </div>
            </main>
            <?php layout_site_footer(); ?>
        </div>
    <?php else: ?>
        </div>
        <?php layout_site_footer(); ?>
    <?php endif; ?>
    <div class="modal fade hms-lightbox-modal" id="hmsPhotoLightbox" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-fullscreen-md-down">
            <div class="modal-content hms-lightbox-content border-0">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-2 p-md-4 text-center position-relative">
                    <img id="hmsPhotoLightboxImg" src="" alt="" class="img-fluid hms-lightbox-image">
                    <button type="button" class="btn hms-lightbox-nav hms-lightbox-prev position-absolute top-50 start-0 translate-middle-y ms-2 d-none" aria-label="Previous photo">&lsaquo;</button>
                    <button type="button" class="btn hms-lightbox-nav hms-lightbox-next position-absolute top-50 end-0 translate-middle-y me-2 d-none" aria-label="Next photo">&rsaquo;</button>
                </div>
                <div class="modal-footer border-0 pt-0 justify-content-center pb-4">
                    <span id="hmsPhotoLightboxCaption" class="hms-lightbox-caption"></span>
                </div>
            </div>
        </div>
    </div>
    <?php $hmsAppJsV = (string)(@filemtime(__DIR__ . '/../public/app.js') ?: time()); ?>
    <script src="<?php echo hms_url('app.js?v=' . $hmsAppJsV); ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
}

