<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth_sa.php';
require_once __DIR__ . '/../lib/sa_csrf.php';
require_once __DIR__ . '/../lib/sa_layout.php';

if (sa_current_user()) {
    sa_redirect('admins.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')), 'UTF-8');
    $password = (string)($_POST['password'] ?? '');

    if (sa_login($email, $password)) {
        flash_set('success', 'Signed in.');
        $u = sa_current_user();
        hms_audit_log(null, 'sa_login', 'session', null, 'Super admin signed in: ' . ($u['email'] ?? ''));
        sa_redirect('admins.php');
    }
    flash_set('error', 'Invalid email or password.');
}

sa_layout_header('Sign in');
?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body p-4">
                <h2 class="h4 mb-3">Super Admin sign in</h2>
                <p class="text-muted small">Access is restricted to authorised super-admin accounts. Manage university administrators for the main HMS site (same database).</p>
                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required autocomplete="username">
                    </div>
                    <?php
                        hms_render_password_field('password', 'Password', [
                            'required' => true,
                            'autocomplete' => 'current-password',
                        ]);
                    ?>
                    <button class="btn btn-dark w-100" type="submit">Sign in</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
sa_layout_footer();
