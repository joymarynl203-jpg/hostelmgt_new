<?php
require_once __DIR__ . '/../../lib/layout.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../db.php';

require_login();
require_role(['university_admin']);

$db = hms_db();
$user = hms_current_user();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_warden') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
        $nin = trim((string)($_POST['nin'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $hostelId = (int)($_POST['hostel_id'] ?? 0);

        $pwCheck = hms_passwords_match($password, $passwordConfirm);
        if ($name === '' || $email === '' || $password === '' || $hostelId <= 0) {
            flash_set('error', 'Name, email, password, and hostel are required.');
        } elseif (!$pwCheck['ok']) {
            flash_set('error', $pwCheck['error'] ?? 'Passwords do not match.');
        } elseif ($nin === '' || mb_strlen($nin) < 8 || mb_strlen($nin) > 28 || !preg_match('/^[A-Za-z0-9\-]+$/', $nin)) {
            flash_set('error', 'Enter a valid NIN (National Identification Number): 8–28 letters, digits, or hyphens only.');
        } else {
            $hostelStmt = $db->prepare('SELECT id, name, managed_by FROM hostels WHERE id = ? LIMIT 1');
            $hostelStmt->execute([$hostelId]);
            $hostel = $hostelStmt->fetch();
            if (!$hostel) {
                flash_set('error', 'Selected hostel does not exist.');
            } elseif (($hostel['managed_by'] ?? null) !== null) {
                flash_set('error', 'Selected hostel already has a warden assigned. Choose another hostel.');
            } else {
                $db->beginTransaction();
                try {
                    $ok = auth_create_user($name, $email, $password, 'warden', null, $phone !== '' ? $phone : null, $nin);
                    if (!$ok) {
                        throw new RuntimeException('Unable to create warden. Check email uniqueness, NIN format (8–28 alphanumeric/hyphen), and password length.');
                    }

                    $wardenStmt = $db->prepare('SELECT id FROM users WHERE email = ? AND role = "warden" ORDER BY id DESC LIMIT 1');
                    $wardenStmt->execute([mb_strtolower($email, 'UTF-8')]);
                    $wardenId = (int)($wardenStmt->fetch()['id'] ?? 0);
                    if ($wardenId <= 0) {
                        throw new RuntimeException('Warden account created but could not be resolved.');
                    }

                    $assignStmt = $db->prepare('UPDATE hostels SET managed_by = ? WHERE id = ? AND managed_by IS NULL');
                    $assignStmt->execute([$wardenId, $hostelId]);
                    if ($assignStmt->rowCount() !== 1) {
                        throw new RuntimeException('Hostel assignment failed. Hostel may already be assigned.');
                    }

                    hms_audit_log($userId, 'user_created', 'user', $wardenId, 'Created warden account for ' . $email . ' and assigned hostel #' . $hostelId . '.');
                    hms_audit_log($userId, 'hostel_manager_updated', 'hostel', $hostelId, 'Assigned new warden #' . $wardenId . ' during account creation.');
                    $db->commit();

                    flash_set('success', 'Warden account created and hostel assigned.');
                    redirect_to(hms_url('admin/users.php'));
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    flash_set('error', $e->getMessage());
                }
            }
        }
    }
}

$availableHostels = $db->query('
    SELECT id, name, location
    FROM hostels
    WHERE managed_by IS NULL
    ORDER BY is_active DESC, name ASC
')->fetchAll();

$wardens = $db->query('
    SELECT
        u.id,
        u.name,
        u.email,
        u.nin,
        u.phone,
        u.created_at,
        GROUP_CONCAT(h.name ORDER BY h.name SEPARATOR ", ") AS assigned_hostels
    FROM users u
    LEFT JOIN hostels h ON h.managed_by = u.id
    WHERE u.role = "warden"
    GROUP BY u.id, u.name, u.email, u.nin, u.phone, u.created_at
    ORDER BY u.created_at DESC
')->fetchAll();

layout_header('Warden Management');
?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h2 class="h4 mb-3">Create Warden Account</h2>
                <form method="post" action=""<?php echo hms_data_confirm('Create this warden account and assign the hostel? This cannot be undone from this screen.'); ?>>
                    <input type="hidden" name="action" value="create_warden">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">NIN (National Identification Number) <span class="text-danger">*</span></label>
                        <input type="text" name="nin" class="form-control" required minlength="8" maxlength="28" pattern="[A-Za-z0-9\-]+" placeholder="e.g. CM960882EB01" autocomplete="off">
                        <div class="form-text">Required for wardens. Letters, digits, and hyphens only (8–28 characters).</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Phone (optional)</label>
                        <input type="tel" name="phone" class="form-control" placeholder="07XXXXXXXX">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>

                    <?php
                        hms_render_password_field('password', 'Password', [
                            'required' => true,
                            'minlength' => 8,
                            'autocomplete' => 'new-password',
                            'help' => 'Minimum 8 characters.',
                        ]);
                        hms_render_password_field('password_confirm', 'Confirm password', [
                            'required' => true,
                            'minlength' => 8,
                            'autocomplete' => 'new-password',
                        ]);
                    ?>

                    <div class="mb-3">
                        <label class="form-label">Assign Hostel</label>
                        <select name="hostel_id" class="form-select" required>
                            <option value="">Select hostel...</option>
                            <?php foreach ($availableHostels as $h): ?>
                                <option value="<?php echo (int)$h['id']; ?>">
                                    <?php echo e($h['name']); ?> (<?php echo e($h['location']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($availableHostels)): ?>
                            <div class="form-text text-danger">No unassigned hostels available. Create a hostel first or unassign one.</div>
                        <?php endif; ?>
                    </div>

                    <button class="btn btn-primary w-100" type="submit">Create Warden</button>
                </form>
                <div class="text-muted small mt-3">
                    Tip: Each new warden must be assigned one available hostel during account creation.
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h2 class="h4 mb-3">Wardens</h2>
                <?php if (empty($wardens)): ?>
                    <div class="alert alert-info">No wardens created yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Assigned Hostel(s)</th>
                                    <th>NIN</th>
                                    <th>Phone</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($wardens as $w): ?>
                                    <tr>
                                        <td><?php echo e($w['name']); ?></td>
                                        <td><?php echo e($w['email']); ?></td>
                                        <td class="text-muted small"><?php echo e($w['assigned_hostels'] ?? 'Unassigned'); ?></td>
                                        <td class="text-muted small"><?php echo e((string)($w['nin'] ?? '') ?: '—'); ?></td>
                                        <td class="text-muted small"><?php echo e($w['phone'] ?? ''); ?></td>
                                        <td class="text-muted small"><?php echo e((string)$w['created_at']); ?></td>
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

<?php layout_footer(); ?>

