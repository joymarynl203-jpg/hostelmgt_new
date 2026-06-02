<?php
require_once __DIR__ . '/../../lib/layout.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../db.php';

require_login();
require_role(['warden', 'university_admin']);

$db = hms_db();
$user = hms_current_user();
$userId = (int)$user['id'];
$role = $user['role'];

$adminHostelScope = 'EXISTS (
    SELECT 1
    FROM audit_logs al
    WHERE al.entity_type = "hostel"
      AND al.action = "hostel_created"
      AND al.entity_id = h.id
      AND al.actor_user_id = ?
)';

if ($role === 'warden') {
    $hostelsStmt = $db->prepare('SELECT id, name FROM hostels WHERE managed_by = ? ORDER BY name ASC');
    $hostelsStmt->execute([$userId]);
} else {
    $hostelsStmt = $db->prepare('
        SELECT h.id, h.name
        FROM hostels h
        WHERE ' . $adminHostelScope . '
        ORDER BY h.name ASC
    ');
    $hostelsStmt->execute([$userId]);
}
$hostelList = $hostelsStmt->fetchAll();
$allowedHostelIds = array_map(static fn ($row) => (int)($row['id'] ?? 0), $hostelList);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'toggle_student_active') {
        if ($role !== 'university_admin') {
            flash_set('error', 'Only university administrators can activate or deactivate student accounts.');
            $rh = (int)($_POST['redirect_hostel_id'] ?? 0);
            $rv = (string)($_POST['redirect_view'] ?? 'current');
            if ($rv !== 'all') {
                $rv = 'current';
            }
            if ($rh > 0 && !in_array($rh, $allowedHostelIds, true)) {
                $rh = 0;
            }
            $url = hms_url('admin/students_by_room.php?view=' . rawurlencode($rv) . ($rh > 0 ? '&hostel_id=' . $rh : ''));
            redirect_to($url);
        }

        $studentId = (int)($_POST['student_id'] ?? 0);
        $newActive = (int)($_POST['is_active'] ?? 0);
        if ($newActive !== 0 && $newActive !== 1) {
            flash_set('error', 'Invalid request.');
        } elseif ($studentId <= 0 || $studentId === $userId) {
            flash_set('error', 'Invalid student.');
        } else {
            $chk = $db->prepare('
                SELECT u.id
                FROM users u
                WHERE u.id = ?
                  AND u.role = "student"
                  AND EXISTS (
                    SELECT 1
                    FROM bookings b2
                    JOIN rooms r2 ON r2.id = b2.room_id
                    JOIN hostels h ON h.id = r2.hostel_id
                    WHERE b2.student_id = u.id
                      AND ' . $adminHostelScope . '
                  )
                LIMIT 1
            ');
            $chk->execute([$studentId, $userId]);
            if (!$chk->fetch()) {
                flash_set('error', 'You can only change accounts for students linked to your hostels.');
            } else {
                $db->prepare('UPDATE users SET is_active = ? WHERE id = ? AND role = "student"')->execute([$newActive, $studentId]);
                hms_audit_log($userId, $newActive === 1 ? 'student_activated' : 'student_deactivated', 'user', $studentId, 'Student is_active set to ' . $newActive);
                flash_set('success', $newActive === 1 ? 'Student account reactivated.' : 'Student account deactivated. They can no longer sign in.');
            }
        }
        $rh = (int)($_POST['redirect_hostel_id'] ?? 0);
        $rv = (string)($_POST['redirect_view'] ?? 'current');
        if ($rv !== 'all') {
            $rv = 'current';
        }
        if ($rh > 0 && !in_array($rh, $allowedHostelIds, true)) {
            $rh = 0;
        }
        redirect_to(hms_url('admin/students_by_room.php?view=' . rawurlencode($rv) . ($rh > 0 ? '&hostel_id=' . $rh : '')));
    }
}

$hostelId = (int)($_GET['hostel_id'] ?? 0);
if ($hostelId > 0 && !in_array($hostelId, $allowedHostelIds, true)) {
    $hostelId = 0;
}

$view = (string)($_GET['view'] ?? 'current');
if ($view !== 'all') {
    $view = 'current';
}

if ($view === 'all') {
    $statusClause = 'b.status <> "rejected"';
} else {
    $statusClause = 'b.status IN ("pending","approved","checked_in")';
}

// Same rule as booking approvals: at least 20% of total_due must be paid (successful payments only).
$depositMetClause = '(
        (SELECT COALESCE(SUM(p2.amount), 0) FROM payments p2 WHERE p2.booking_id = b.id AND p2.status = "successful")
        >= (b.total_due * 0.20)
    )';

$scopeSql = $role === 'warden'
    ? 'h.managed_by = ?'
    : $adminHostelScope;

$params = [$userId];
$hostelFilterSql = '';
if ($hostelId > 0) {
    $hostelFilterSql = ' AND h.id = ? ';
    $params[] = $hostelId;
}

$sql = '
    SELECT
        b.id AS booking_id,
        b.status AS booking_status,
        b.start_date,
        b.end_date,
        b.total_due,
        b.requested_at,
        h.id AS hostel_id,
        h.name AS hostel_name,
        h.location AS hostel_location,
        r.id AS room_id,
        r.room_number,
        r.gender AS room_gender,
        r.capacity,
        r.current_occupancy,
        u.id AS student_id,
        u.name AS student_name,
        u.email,
        u.reg_no,
        u.phone,
        u.institution AS student_institution,
        u.is_active AS student_is_active,
        (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.booking_id = b.id AND p.status = "successful") AS paid_amount
    FROM bookings b
    INNER JOIN users u ON u.id = b.student_id AND u.role = "student"
    INNER JOIN rooms r ON r.id = b.room_id
    INNER JOIN hostels h ON h.id = r.hostel_id
    WHERE ' . $statusClause . '
      AND ' . $depositMetClause . '
      AND ' . $scopeSql . '
      ' . $hostelFilterSql . '
    ORDER BY h.name ASC, r.room_number ASC, b.status ASC, u.name ASC
';

$rowsStmt = $db->prepare($sql);
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

layout_header('Students by room');
?>

<div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h2 class="h4 mb-1">Students by room</h2>
        <p class="text-muted small mb-0">
            Students with bookings where at least <strong>20% of the booking fee</strong> has been paid (same rule as approvals).
            <?php echo $role === 'university_admin' ? ' <strong>Account</strong> deactivate/reactivate is for university administrators only; wardens see status but cannot change it.' : ''; ?>
            <?php echo $view === 'all' ? ' Including completed stays.' : ' Showing active pipeline (pending, approved, checked in). Checked-in residents remain listed after their booking end date until staff record departure (check-out); the bed is not freed automatically when the semester ends.'; ?>
        </p>
    </div>
    <a class="btn btn-outline-primary btn-sm" href="<?php echo hms_url('admin/hostels.php'); ?>">Hostels</a>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <form method="get" action="" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Hostel</label>
                <select name="hostel_id" class="form-select" onchange="this.form.submit()">
                    <option value="0">All hostels in your scope</option>
                    <?php foreach ($hostelList as $h): ?>
                        <option value="<?php echo (int)$h['id']; ?>" <?php echo (int)$h['id'] === $hostelId ? 'selected' : ''; ?>>
                            <?php echo e($h['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Booking scope</label>
                <select name="view" class="form-select" onchange="this.form.submit()">
                    <option value="current" <?php echo $view === 'current' ? 'selected' : ''; ?>>Active pipeline (pending / approved / checked in)</option>
                    <option value="all" <?php echo $view === 'all' ? 'selected' : ''; ?>>All non-rejected (includes checked out)</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-secondary">Apply</button>
            </div>
        </form>
    </div>
</div>

<?php if (empty($hostelList)): ?>
    <div class="alert alert-info">No hostels are available for your account.</div>
<?php elseif (empty($rows)): ?>
    <div class="alert alert-info">No matching student bookings for this filter.</div>
<?php else: ?>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Hostel</th>
                            <th>Room</th>
                            <th>Student</th>
                            <th>Institution</th>
                            <th>Email</th>
                            <th>Reg. no.</th>
                            <th>Phone</th>
                            <th>Booking</th>
                            <th>Stay</th>
                            <th class="text-end">Due / Paid</th>
                            <th class="text-end" style="min-width: <?php echo $role === 'university_admin' ? '140' : '90'; ?>px;">Account</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo e($row['hostel_name']); ?></div>
                                    <div class="text-muted small"><?php echo e((string)($row['hostel_location'] ?? '')); ?></div>
                                </td>
                                <td>
                                    <span class="fw-semibold">Room <?php echo e((string)$row['room_number']); ?></span>
                                    <div class="text-muted small">
                                        <?php echo e((string)$row['room_gender']); ?> ·
                                        cap <?php echo e((string)$row['capacity']); ?> ·
                                        occ <?php echo e((string)$row['current_occupancy']); ?>
                                    </div>
                                </td>
                                <td><?php echo e($row['student_name']); ?></td>
                                <td class="text-muted small"><?php echo e((string)($row['student_institution'] ?? '') !== '' ? (string)$row['student_institution'] : '—'); ?></td>
                                <td class="small"><a href="mailto:<?php echo e((string)$row['email']); ?>"><?php echo e((string)$row['email']); ?></a></td>
                                <td class="text-muted small"><?php echo e((string)($row['reg_no'] ?? '') ?: '—'); ?></td>
                                <td class="text-muted small"><?php echo e((string)($row['phone'] ?? '') ?: '—'); ?></td>
                                <td>
                                    <span class="badge
                                        <?php
                                            echo match ($row['booking_status']) {
                                                'pending' => 'bg-warning text-dark',
                                                'approved' => 'bg-primary',
                                                'checked_in' => 'bg-success',
                                                'checked_out' => 'bg-secondary',
                                                default => 'bg-secondary',
                                            };
                                        ?>">
                                        <?php echo e((string)$row['booking_status']); ?>
                                    </span>
                                    <div class="text-muted small mt-1">Booking #<?php echo (int)$row['booking_id']; ?></div>
                                </td>
                                <td class="small">
                                    <?php echo e((string)($row['start_date'] ?? '')); ?>
                                    → <?php echo e((string)($row['end_date'] ?? '')); ?>
                                </td>
                                <td class="text-end small">
                                    <div><?php echo e(number_format((float)($row['total_due'] ?? 0), 2)); ?> UGX</div>
                                    <div class="text-muted">Paid <?php echo e(number_format((float)($row['paid_amount'] ?? 0), 2)); ?> UGX</div>
                                </td>
                                <?php
                                    $sActive = (int)($row['student_is_active'] ?? 1) === 1;
                                ?>
                                <td class="text-end small">
                                    <span class="badge <?php echo $sActive ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $sActive ? 'Active' : 'Deactivated'; ?></span>
                                    <?php if ($role === 'university_admin'): ?>
                                    <form method="post" action="" class="d-inline-block mt-1"<?php echo hms_data_confirm($sActive ? 'Deactivate this student account? They will be signed out and unable to log in until reactivated.' : 'Reactivate this student account? They will be able to log in again.'); ?>>
                                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="toggle_student_active">
                                        <input type="hidden" name="student_id" value="<?php echo (int)$row['student_id']; ?>">
                                        <input type="hidden" name="is_active" value="<?php echo $sActive ? '0' : '1'; ?>">
                                        <input type="hidden" name="redirect_hostel_id" value="<?php echo (int)$hostelId; ?>">
                                        <input type="hidden" name="redirect_view" value="<?php echo e($view); ?>">
                                        <button type="submit" class="btn btn-sm <?php echo $sActive ? 'btn-outline-danger' : 'btn-outline-success'; ?>">
                                            <?php echo $sActive ? 'Deactivate' : 'Reactivate'; ?>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php layout_footer(); ?>
