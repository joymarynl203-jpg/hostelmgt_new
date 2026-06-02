<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function layout_site_footer(): void
{
    $user = hms_current_user();
    $isStudent = $user && ($user['role'] ?? '') === 'student';
    $homeHref = $user ? hms_url('dashboard.php') : hms_url();
    $year = (int)date('Y');
    $whatsappUrl = 'https://wa.me/' . preg_replace('/\D+/', '', (string)HMS_SOCIAL_WHATSAPP);
    $gmailUrl = hms_gmail_compose_url((string)HMS_SOCIAL_GMAIL);
    $tiktokUrl = (string)HMS_SOCIAL_TIKTOK_URL;
    $xUrl = (string)HMS_SOCIAL_X_URL;
    ?>
    <footer class="app-footer">
        <div class="container app-footer-inner">
            <div class="row g-4 g-lg-5">
                <div class="col-lg-4 col-md-6">
                    <a class="app-footer-brand" href="<?php echo e($homeHref); ?>">
                        <span class="app-footer-brand-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 21V12h6v9"/><path d="M9 9l2 2 4-4"/></svg>
                        </span>
                        <span class="app-footer-brand-text">
                            <span class="app-footer-brand-title">Hostel MS</span>
                            <span class="app-footer-brand-sub">Management System</span>
                        </span>
                    </a>
                    <p class="app-footer-tagline">
                        Comfortable, affordable hostel accommodation for students — search, book, and manage stays in one place.
                    </p>
                    <div class="app-footer-social" aria-label="Social links">
                        <a class="app-footer-social-link" href="<?php echo e($whatsappUrl); ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp"><?php echo hms_footer_social_icon('whatsapp'); ?></a>
                        <a class="app-footer-social-link" href="<?php echo e($gmailUrl); ?>" target="_blank" rel="noopener noreferrer" aria-label="Gmail"><?php echo hms_footer_social_icon('gmail'); ?></a>
                        <a class="app-footer-social-link" href="<?php echo e($tiktokUrl !== '' ? $tiktokUrl : '#'); ?>"<?php echo $tiktokUrl !== '' ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> aria-label="TikTok"><?php echo hms_footer_social_icon('tiktok'); ?></a>
                        <a class="app-footer-social-link" href="<?php echo e($xUrl !== '' ? $xUrl : '#'); ?>"<?php echo $xUrl !== '' ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> aria-label="X"><?php echo hms_footer_social_icon('x'); ?></a>
                    </div>
                </div>
                <div class="col-6 col-lg-2 col-md-3">
                    <h3 class="app-footer-heading">Quick Links</h3>
                    <ul class="app-footer-links">
                        <li><a href="<?php echo e($homeHref); ?>">Home</a></li>
                        <?php if ($user): ?>
                            <li><a href="<?php echo hms_url('logout.php'); ?>"<?php echo hms_data_confirm('Log out? You will need to sign in again.'); ?>>Logout</a></li>
                        <?php else: ?>
                            <li><a href="<?php echo hms_url('login.php'); ?>">Login</a></li>
                            <li><a href="<?php echo hms_url('register.php'); ?>">Student register</a></li>
                        <?php endif; ?>
                        <?php if ($user): ?>
                            <li><a href="<?php echo hms_url('hostels.php'); ?>">Browse hostels</a></li>
                            <li><a href="<?php echo hms_url('notifications.php'); ?>">Notifications</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php if ($isStudent): ?>
                <div class="col-6 col-lg-3 col-md-3">
                    <h3 class="app-footer-heading">Platform</h3>
                    <ul class="app-footer-links">
                        <li><a href="<?php echo hms_url('hostels.php'); ?>">Hostel search</a></li>
                        <li><a href="<?php echo hms_url('bookings.php'); ?>">Room booking</a></li>
                        <li><a href="<?php echo hms_url('my_payments.php'); ?>">Online payments</a></li>
                        <li><a href="<?php echo hms_url('maintenance.php'); ?>">Maintenance requests</a></li>
                        <li><a href="<?php echo hms_url('change_password.php'); ?>">Account security</a></li>
                    </ul>
                </div>
                <?php endif; ?>
                <div class="col-lg-3 col-md-6">
                    <h3 class="app-footer-heading">Contact Us</h3>
                    <ul class="app-footer-contact">
                        <li>
                            <span class="app-footer-contact-icon" aria-hidden="true">☎</span>
                            <span>
                                <a href="https://wa.me/256787221531" target="_blank" rel="noopener noreferrer">+256 787 221531</a><br>
                                <a href="https://wa.me/256744016985" target="_blank" rel="noopener noreferrer">+256 744 016985</a>
                            </span>
                        </li>
                        <li>
                            <span class="app-footer-contact-icon" aria-hidden="true">✉</span>
                            <span>
                                <a href="<?php echo e(hms_gmail_compose_url('shamirah0mar915@gmail.com')); ?>" target="_blank" rel="noopener noreferrer">shamirah0mar915@gmail.com</a><br>
                                <a href="<?php echo e(hms_gmail_compose_url('joymarynl203@gmail.com')); ?>" target="_blank" rel="noopener noreferrer">joymarynl203@gmail.com</a>
                            </span>
                        </li>
                        <li>
                            <span class="app-footer-contact-icon" aria-hidden="true">⌖</span>
                            <span>Uganda — university hostel management platform</span>
                        </li>
                        <li>
                            <span class="app-footer-contact-icon" aria-hidden="true">◷</span>
                            <span>Mon — Sun &nbsp;8:00 AM — 10:00 PM</span>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="app-footer-copy">
                &copy; <?php echo e((string)$year); ?> Hostel Management System. All rights reserved.
            </div>
        </div>
    </footer>
    <?php
}
