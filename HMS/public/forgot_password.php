<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/mail.php';
require_once __DIR__ . '/../lib/password_reset.php';

if (hms_current_user()) {
    redirect_to(hms_url('dashboard.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');

    if (!hms_mail_configured()) {
        flash_set('error', 'Password reset email is not configured on this server. Ask your administrator to set up outbound mail in HMS configuration.');
        redirect_to(hms_url('forgot_password.php'));
    }

    $now = time();
    $win = $_SESSION['hms_forgot_pw'] ?? null;
    if (!is_array($win) || !isset($win['start'], $win['count']) || ($now - (int) $win['start']) > 900) {
        $_SESSION['hms_forgot_pw'] = ['start' => $now, 'count' => 0];
        $win = $_SESSION['hms_forgot_pw'];
    }
    $_SESSION['hms_forgot_pw']['count'] = (int) $win['count'] + 1;
    if ((int) $_SESSION['hms_forgot_pw']['count'] > 10) {
        flash_set('error', 'Too many reset attempts from this browser. Please wait about fifteen minutes and try again.');
        redirect_to(hms_url('forgot_password.php'));
    }

    $email = hms_password_reset_normalize_email((string) ($_POST['email'] ?? ''));
    $user = hms_password_reset_find_eligible_user($email);

    if ($user) {
        $raw = hms_password_reset_issue_token((int) $user['id']);
        if ($raw !== null) {
            $link = hms_abs_url('reset_password.php?t=' . rawurlencode($raw));
            $subject = 'Reset your HMS password';
            $text = "We received a request to reset the password for your Hostel Management System account.\r\n\r\n"
                . "Open this link in your browser (it expires in one hour):\r\n{$link}\r\n\r\n"
                . "If you did not request a password reset, you can ignore this message.\r\n";
            $html = '<p>We received a request to reset the password for your Hostel Management System account.</p>'
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Reset your password</a></p>'
                . '<p>This link expires in one hour. If you did not request a reset, you can ignore this email.</p>';

            if (!hms_send_mail((string) $user['email'], $subject, $text, $html)) {
                hms_password_reset_clear_token($raw);
                error_log('HMS forgot_password: failed to send mail to ' . $user['email']);
            }
        }
    }

    flash_set('success', 'If an account exists for that email, we have sent password reset instructions. The link expires in one hour.');
    redirect_to(hms_url('forgot_password.php'));
}

layout_header('Forgot password', ['body_class' => 'hms-page-login-bg']);
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm border-0 rounded-4 hms-auth-card">
            <div class="card-body p-4">
                <h2 class="h4 mb-3">Forgot password</h2>
                <?php if (!hms_mail_configured()): ?>
                    <div class="alert alert-warning small mb-3">
                        Outbound email is not configured (SMTP or PHP <code>mail</code>). Your administrator must set this in <code>config.local.php</code> before reset links can be sent.
                    </div>
                <?php endif; ?>
                <p class="text-muted small mb-3">
                    For <strong>students</strong>, <strong>wardens</strong>, and <strong>university administrators</strong> only. Enter the email you use to sign in.
                </p>
                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required autocomplete="email" maxlength="150">
                    </div>
                    <button class="btn btn-primary w-100" type="submit"<?php echo !hms_mail_configured() ? ' disabled' : ''; ?>>Send reset link</button>
                </form>
                <div class="mt-3 text-center small">
                    <a href="<?php echo hms_url('login.php'); ?>">Back to sign in</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php layout_footer(); ?>
