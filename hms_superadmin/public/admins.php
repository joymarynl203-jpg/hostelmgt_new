<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth_sa.php';
require_once __DIR__ . '/../lib/sa_csrf.php';
require_once __DIR__ . '/../lib/sa_layout.php';

sa_require_login();
$actor = sa_current_user();

$db = hms_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_university_admin') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
        $phone = trim((string)($_POST['phone'] ?? ''));
        $nin = trim((string)($_POST['nin'] ?? ''));

        $pwCheck = hms_passwords_match($password, $passwordConfirm);
        if ($name === '' || $email === '' || $password === '') {
            flash_set('error', 'Name, email, and password are required.');
        } elseif (!$pwCheck['ok']) {
            flash_set('error', $pwCheck['error'] ?? 'Passwords do not match.');
        } elseif ($phone === '') {
            flash_set('error', 'Telephone number is required.');
        } elseif (mb_strlen($phone) < 7 || mb_strlen($phone) > 30) {
            flash_set('error', 'Enter a valid telephone number (7–30 characters).');
        } elseif ($nin === '' || mb_strlen($nin) < 8 || mb_strlen($nin) > 28 || !preg_match('/^[A-Za-z0-9\-]+$/', $nin)) {
            flash_set('error', 'Enter a valid NIN (National Identification Number): 8–28 letters, digits, or hyphens only.');
        } elseif (mb_strlen($password) < 8) {
            flash_set('error', 'Password must be at least 8 characters.');
        } else {
            $ok = auth_create_user($name, $email, $password, 'university_admin', null, $phone, $nin);
            if ($ok) {
                $uidStmt = $db->prepare('SELECT id FROM users WHERE email = ? AND role = "university_admin" ORDER BY id DESC LIMIT 1');
                $uidStmt->execute([mb_strtolower($email, 'UTF-8')]);
                $newId = (int)($uidStmt->fetch()['id'] ?? 0);
                hms_audit_log(null, 'user_created', 'user', $newId, 'Super admin (' . ($actor['email'] ?? '') . ') created university_admin: ' . $email);
                flash_set('success', 'University administrator account created.');
            } else {
                flash_set('error', 'Could not create user. Check email is unique and fields are valid.');
            }
        }
    }
}

$admins = $db->query('
    SELECT id, name, email, phone, nin, created_at
    FROM users
    WHERE role = "university_admin"
    ORDER BY created_at DESC
')->fetchAll();

sa_layout_header('University administrators');
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
    <div>
        <h2 class="h4 mb-1">University administrators</h2>
        <p class="text-muted small mb-0">These accounts sign in to the main HMS site to manage hostels they create. Same database as this portal.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?php echo e(rtrim(HMS_APP_URL, '/') . hms_url()); ?>" target="_blank" rel="noopener">Open main HMS</a>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h3 class="h5 mb-3">Add university admin</h3>
                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="create_university_admin">
                    <div class="mb-3">
                        <label class="form-label">Full name</label>
                        <input type="text" name="name" class="form-control" required minlength="2">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">NIN (National Identification Number) <span class="text-danger">*</span></label>
                        <input type="text" name="nin" class="form-control" required minlength="8" maxlength="28" pattern="[A-Za-z0-9\-]+" placeholder="e.g. CM960882EB01" autocomplete="off">
                        <div class="form-text">Required. Letters, digits, and hyphens only (8–28 characters).</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Telephone <span class="text-danger">*</span></label>
                        <input type="tel" name="phone" class="form-control" required minlength="7" maxlength="30" placeholder="07XXXXXXXX" autocomplete="tel">
                    </div>
                    <?php
                        hms_render_password_field('password', 'Initial password', [
                            'required' => true,
                            'minlength' => 8,
                            'autocomplete' => 'new-password',
                            'help' => 'Minimum 8 characters. Ask them to change it after first login if you add that flow later.',
                        ]);
                        hms_render_password_field('password_confirm', 'Confirm password', [
                            'required' => true,
                            'minlength' => 8,
                            'autocomplete' => 'new-password',
                        ]);
                    ?>
                    <button class="btn btn-dark w-100" type="submit">Create account</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h3 class="h5 mb-3">Existing accounts</h3>
                <?php if (empty($admins)): ?>
                    <div class="alert alert-secondary mb-0">No university administrators yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>NIN</th>
                                    <th>Phone</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($admins as $a): ?>
                                    <tr>
                                        <td><?php echo e($a['name']); ?></td>
                                        <td><?php echo e($a['email']); ?></td>
                                        <td class="text-muted small"><?php echo e((string)($a['nin'] ?? '') !== '' ? (string)$a['nin'] : '—'); ?></td>
                                        <td class="text-muted small"><?php echo e((string)($a['phone'] ?? '') !== '' ? (string)$a['phone'] : '—'); ?></td>
                                        <td class="text-muted small"><?php echo e((string)($a['created_at'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
sa_layout_footer();
