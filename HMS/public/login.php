<?php
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';

if (hms_current_user()) {
    redirect_to(hms_url('dashboard.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');

    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')), 'UTF-8');
    $password = (string)($_POST['password'] ?? '');

    if (auth_login($email, $password)) {
        flash_set('success', 'Login successful.');
        hms_audit_log(null, 'login', 'session', null, 'User logged in with email.');
        redirect_to(hms_url('dashboard.php'));
    }

    if (!empty($GLOBALS['hms_auth_login_student_inactive'])) {
        flash_set('error', 'This student account has been deactivated. Contact your university administrator.');
    } elseif (!empty($GLOBALS['hms_auth_login_super_admin_portal'])) {
        flash_set('error', 'Super admin accounts use the separate Super Admin portal, not this login page.');
    } else {
        flash_set('error', 'Invalid email or password.');
    }
}

layout_header('Login', ['body_class' => 'hms-page-login-bg']);
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm border-0 rounded-4 hms-auth-card">
            <div class="card-body p-4">
                <h2 class="h4 mb-3">Sign in</h2>
                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required autocomplete="email">
                    </div>
                    <?php
                        hms_render_password_field('password', 'Password', [
                            'required' => true,
                            'autocomplete' => 'current-password',
                            'mb_class' => 'mb-3',
                        ]);
                    ?>
                    <button class="btn btn-primary w-100" type="submit">Login</button>
                </form>
                <div class="mt-3 text-center small">
                    <a href="<?php echo hms_url('forgot_password.php'); ?>">Forgot password?</a>
                </div>
                <div class="mt-2 text-center small text-muted">
                    No account yet? <a href="<?php echo hms_url('register.php'); ?>">Register as a student</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php layout_footer(); ?>

