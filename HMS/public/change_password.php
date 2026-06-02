<?php
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';

require_login();
require_role(['student', 'warden', 'university_admin']);

$db = hms_db();
$user = hms_current_user();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['new_password_confirm'] ?? '');

    if ($current === '' || $new === '' || $confirm === '') {
        flash_set('error', 'Fill in all password fields.');
    } elseif ($new !== $confirm) {
        flash_set('error', 'New password and confirmation do not match.');
    } elseif (mb_strlen($new) < 8) {
        flash_set('error', 'New password must be at least 8 characters.');
    } else {
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($current, (string)$row['password_hash'])) {
            flash_set('error', 'Current password is incorrect.');
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
            hms_audit_log($userId, 'password_changed', 'user', $userId, 'User changed their own password.');
            flash_set('success', 'Your password has been updated.');
            redirect_to(hms_url('change_password.php'));
        }
    }
}

layout_header('Change password');
?>

<div class="row justify-content-center">
    <div class="col-lg-6 col-xl-5">
        <div class="card border-0 shadow-sm rounded-4 hms-auth-card">
            <div class="card-body p-4">
                <h2 class="h4 mb-2">Change password</h2>
                <p class="text-muted small mb-4">Signed in as <span class="fw-semibold text-dark"><?php echo e($user['email']); ?></span> (<?php echo e($user['role']); ?>).</p>
                <form method="post" action=""<?php echo hms_data_confirm('Update your password to the new one you entered?'); ?>>
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <?php
                        hms_render_password_field('current_password', 'Current password', [
                            'required' => true,
                            'autocomplete' => 'current-password',
                        ]);
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
                    <button class="btn btn-primary" type="submit">Save new password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
layout_footer();
