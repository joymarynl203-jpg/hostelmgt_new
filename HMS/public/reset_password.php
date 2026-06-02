<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/password_reset.php';

if (hms_current_user()) {
    redirect_to(hms_url('dashboard.php'));
}

$tokenGet = trim((string) ($_GET['t'] ?? ''));
$tokenPost = trim((string) ($_POST['token'] ?? ''));
$token = $tokenPost !== '' ? $tokenPost : $tokenGet;
$userId = $token !== '' ? hms_password_reset_lookup_user_id($token) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');

    if ($userId === null) {
        flash_set('error', 'This reset link is invalid or has expired. Request a new one from the forgot password page.');
        redirect_to(hms_url('forgot_password.php'));
    }

    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['new_password_confirm'] ?? '');

    if ($new === '' || $confirm === '') {
        flash_set('error', 'Enter and confirm your new password.');
        redirect_to(hms_abs_url('reset_password.php?t=' . rawurlencode($token)));
    }
    if ($new !== $confirm) {
        flash_set('error', 'The two password fields do not match.');
        redirect_to(hms_abs_url('reset_password.php?t=' . rawurlencode($token)));
    }
    if (mb_strlen($new) < 8) {
        flash_set('error', 'Password must be at least 8 characters.');
        redirect_to(hms_abs_url('reset_password.php?t=' . rawurlencode($token)));
    }

    if (!hms_password_reset_apply($userId, $new)) {
        flash_set('error', 'Password must be at least 8 characters.');
        redirect_to(hms_abs_url('reset_password.php?t=' . rawurlencode($token)));
    }

    flash_set('success', 'Your password has been reset. You can sign in now.');
    redirect_to(hms_url('login.php'));
}

layout_header('Set new password', ['body_class' => 'hms-page-login-bg']);

if ($userId === null) {
    ?>
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-sm border-0 rounded-4 hms-auth-card">
                <div class="card-body p-4">
                    <h2 class="h4 mb-3">Reset link</h2>
                    <p class="text-muted mb-3">This reset link is invalid or has expired.</p>
                    <a class="btn btn-primary" href="<?php echo hms_url('forgot_password.php'); ?>">Request a new link</a>
                    <div class="mt-3 small"><a href="<?php echo hms_url('login.php'); ?>">Back to sign in</a></div>
                </div>
            </div>
        </div>
    </div>
    <?php
    layout_footer();
    exit;
}
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm border-0 rounded-4 hms-auth-card">
            <div class="card-body p-4">
                <h2 class="h4 mb-3">Choose a new password</h2>
                <p class="text-muted small mb-3">Pick a strong password you have not used here before.</p>
                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="token" value="<?php echo e($token); ?>">
                    <?php
                        hms_render_password_field('new_password', 'New password', [
                            'required' => true,
                            'minlength' => 8,
                            'autocomplete' => 'new-password',
                            'help' => 'At least 8 characters.',
                        ]);
                        hms_render_password_field('new_password_confirm', 'Confirm new password', [
                            'required' => true,
                            'minlength' => 8,
                            'autocomplete' => 'new-password',
                        ]);
                    ?>
                    <button class="btn btn-primary w-100" type="submit">Save password</button>
                </form>
                <div class="mt-3 text-center small"><a href="<?php echo hms_url('login.php'); ?>">Back to sign in</a></div>
            </div>
        </div>
    </div>
</div>

<?php layout_footer(); ?>
