<?php
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../db.php';

require_login();

$user = hms_current_user();

$role = $user['role'];
$userId = (int) $user['id'];

// Basic metrics using direct queries for clarity.
$db = hms_db();

if ($role === 'student' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    csrf_verify($_POST['csrf_token'] ?? '');

    if ($action === 'create_maintenance') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $priority = (string)($_POST['priority'] ?? 'medium');

        if ($bookingId <= 0 || mb_strlen($title) < 3 || mb_strlen($description) < 10) {
            flash_set('error', 'Provide booking, a meaningful title, and description (min 10 chars).');
        } elseif (!in_array($priority, ['low', 'medium', 'high'], true)) {
            flash_set('error', 'Invalid priority.');
        } else {
            $stmt = $db->prepare('
                SELECT b.id AS booking_id, b.status, r.id AS room_id
                FROM bookings b
                JOIN rooms r ON r.id = b.room_id
                WHERE b.id = ? AND b.student_id = ?
            ');
            $stmt->execute([$bookingId, $userId]);
            $booking = $stmt->fetch();

            if (!$booking) {
                flash_set('error', 'Booking not found for your account.');
            } elseif (!in_array($booking['status'], ['approved', 'checked_in'], true)) {
                flash_set('error', 'Maintenance can be requested for approved/checked-in bookings.');
            } else {
                try {
                    $insert = $db->prepare('
                        INSERT INTO maintenance_requests (booking_id, student_id, room_id, title, description, status, priority)
                        VALUES (?, ?, ?, ?, ?, "open", ?)
                    ');
                    $insert->execute([$bookingId, $userId, (int)$booking['room_id'], $title, $description, $priority]);
                    $maintenanceId = (int)$db->lastInsertId();

                    $wardenAndAdmins = [];
                    $roomStmt = $db->prepare('
                        SELECT r.id AS room_id, h.managed_by
                        FROM rooms r
                        JOIN hostels h ON h.id = r.hostel_id
                        WHERE r.id = ?
                    ');
                    $roomStmt->execute([(int)$booking['room_id']]);
                    $room = $roomStmt->fetch();

                    if ($room && $room['managed_by'] !== null) {
                        $wardenAndAdmins[] = (int)$room['managed_by'];
                    }

                    try {
                        $adminStmt = $db->prepare('
                            SELECT al.actor_user_id AS id
                            FROM audit_logs al
                            WHERE al.entity_type = "hostel"
                              AND al.action = "hostel_created"
                              AND al.entity_id = (
                                  SELECT hostel_id FROM rooms WHERE id = ?
                              )
                        ');
                        $adminStmt->execute([(int)$booking['room_id']]);
                        $admins = $adminStmt->fetchAll();
                        foreach ($admins as $a) {
                            $wardenAndAdmins[] = (int)($a['id'] ?? 0);
                        }
                    } catch (Throwable $e) {
                    }

                    $wardenAndAdmins = array_values(array_unique($wardenAndAdmins));
                    foreach ($wardenAndAdmins as $uid) {
                        if ($uid > 0) {
                            try {
                                hms_notify_maintenance_new_request($uid, $title, $description);
                            } catch (Throwable $e) {
                            }
                        }
                    }

                    try {
                        hms_audit_log($userId, 'maintenance_created', 'maintenance_request', $maintenanceId, 'Maintenance request created from dashboard.');
                    } catch (Throwable $e) {
                    }

                    flash_set('success', 'Maintenance request submitted successfully.');
                } catch (Throwable $e) {
                    flash_set('error', 'Could not submit maintenance request right now. Please try again.');
                }
            }
        }

        redirect_to(hms_url('dashboard.php'));
    }
}

function fetch_one(PDO $db, string $sql, array $params = []): ?array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_all(PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Common: unread notification count
$unreadCount = (int) (fetch_one($db, 'SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0', [$userId])['c'] ?? 0);

layout_header('Dashboard');
?>

<div class="hms-welcome-banner mb-4">
    <div class="hms-welcome-copy">
        <p class="hms-welcome-kicker">Welcome back</p>
        <h2 class="hms-welcome-title"><?php echo e($user['name']); ?></h2>
        <p class="hms-welcome-sub text-muted mb-0">
            <?php if ($role === 'student'): ?>
                Track bookings, payments, and maintenance from your dashboard.
            <?php elseif ($role === 'warden'): ?>
                Manage hostel operations, approvals, and resident requests.
            <?php else: ?>
                Oversee hostels, staff, and institutional reporting.
            <?php endif; ?>
        </p>
    </div>
    <div class="hms-welcome-actions d-flex flex-wrap align-items-center gap-2">
        <?php if ($unreadCount > 0): ?>
            <a class="btn btn-light btn-sm hms-welcome-cta" href="<?php echo hms_url('notifications.php'); ?>">
                <?php echo e((string)$unreadCount); ?> unread notification<?php echo $unreadCount === 1 ? '' : 's'; ?>
            </a>
        <?php else: ?>
            <a class="btn btn-light btn-sm hms-welcome-cta hms-welcome-cta--muted" href="<?php echo hms_url('notifications.php'); ?>">Notifications</a>
        <?php endif; ?>
        <?php if ($role === 'student'): ?>
            <a class="btn btn-sm btn-outline-primary hms-welcome-cta-outline" href="<?php echo hms_url('hostels.php'); ?>">Browse hostels</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($role === 'student'): ?>
<div class="row g-4 hms-dash-metrics-row">
    <?php
        $pendingCount = (int) (fetch_one($db, 'SELECT COUNT(*) AS c FROM bookings WHERE student_id = ? AND status = "pending"', [$userId])['c'] ?? 0);
        $approvedCount = (int) (fetch_one($db, 'SELECT COUNT(*) AS c FROM bookings WHERE student_id = ? AND status = "approved"', [$userId])['c'] ?? 0);
        $openMaint = (int) (fetch_one($db, 'SELECT COUNT(*) AS c FROM maintenance_requests WHERE student_id = ? AND status IN ("open","in_progress")', [$userId])['c'] ?? 0);
        $activeBookings = fetch_all(
            $db,
            'SELECT b.id AS booking_id, b.status, r.room_number, h.name AS hostel_name
             FROM bookings b
             JOIN rooms r ON r.id = b.room_id
             JOIN hostels h ON h.id = r.hostel_id
             WHERE b.student_id = ?
               AND b.status IN ("approved","checked_in")
             ORDER BY b.requested_at DESC',
            [$userId]
        );

        $nextBooking = fetch_one(
            $db,
            'SELECT b.id, b.status, b.requested_at, b.start_date, b.end_date, r.room_number, h.name AS hostel_name
             FROM bookings b
             JOIN rooms r ON r.id = b.room_id
             JOIN hostels h ON h.id = r.hostel_id
             WHERE b.student_id = ?
             ORDER BY COALESCE(b.start_date, b.requested_at) DESC
             LIMIT 1',
            [$userId]
        );

        // Payment totals from payments table
        $totals = fetch_one(
            $db,
            'SELECT
                COALESCE(SUM(p.amount), 0) AS paid,
                COALESCE(SUM(b.total_due), 0) AS due
             FROM bookings b
             LEFT JOIN payments p ON p.booking_id = b.id AND p.status = "successful"
             WHERE b.student_id = ?',
            [$userId]
        );
        $paid = (string) ($totals['paid'] ?? '0');
        $due = (string) ($totals['due'] ?? '0');
    ?>

    <div class="col-md-6 col-lg-4 d-flex">
        <div class="card border-0 shadow-sm rounded-4 h-100 w-100 hms-stat-card hms-stat-card--blue hms-dash-metric-card">
            <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="hms-stat-icon mb-0"><?php echo hms_stat_icon_svg('bookings'); ?></span>
                    <h3 class="h5 mb-0">Bookings</h3>
                </div>
                <p class="hms-dash-card-lead mb-0">Room requests and approval status.</p>
                <div class="hms-dash-stat-grid flex-grow-1">
                    <div class="hms-dash-stat">
                        <div class="hms-dash-stat-label">Pending</div>
                        <div class="hms-dash-stat-value"><?php echo e((string)$pendingCount); ?></div>
                    </div>
                    <div class="hms-dash-stat">
                        <div class="hms-dash-stat-label">Approved</div>
                        <div class="hms-dash-stat-value"><?php echo e((string)$approvedCount); ?></div>
                    </div>
                </div>
                <div class="hms-dash-card-foot pt-3 mt-auto">
                    <a class="btn btn-sm btn-outline-primary w-100" href="<?php echo hms_url('bookings.php'); ?>">View bookings</a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-lg-4 d-flex">
        <div class="card border-0 shadow-sm rounded-4 h-100 w-100 hms-stat-card hms-stat-card--amber hms-dash-metric-card">
            <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="hms-stat-icon mb-0"><?php echo hms_stat_icon_svg('payments'); ?></span>
                    <h3 class="h5 mb-0">Payments</h3>
                </div>
                <p class="hms-dash-card-lead mb-0">Deposits and balance on your bookings.</p>
                <div class="hms-dash-stat-grid flex-grow-1">
                    <div class="hms-dash-stat">
                        <div class="hms-dash-stat-label">Paid</div>
                        <div class="hms-dash-stat-value hms-dash-stat-value--sm"><?php echo e(number_format((float)$paid, 0)); ?></div>
                        <div class="hms-dash-stat-unit">UGX</div>
                    </div>
                    <div class="hms-dash-stat">
                        <div class="hms-dash-stat-label">Total due</div>
                        <div class="hms-dash-stat-value hms-dash-stat-value--sm"><?php echo e(number_format((float)$due, 0)); ?></div>
                        <div class="hms-dash-stat-unit">UGX</div>
                    </div>
                </div>
                <div class="hms-dash-card-foot pt-3 mt-auto">
                    <a class="btn btn-sm btn-outline-primary w-100" href="<?php echo hms_url('my_payments.php'); ?>">View payments</a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-12 col-lg-4 d-flex">
        <div class="card border-0 shadow-sm rounded-4 h-100 w-100 hms-stat-card hms-stat-card--mint hms-dash-metric-card">
            <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="hms-stat-icon mb-0"><?php echo hms_stat_icon_svg('maintenance'); ?></span>
                    <h3 class="h5 mb-0">Maintenance</h3>
                </div>
                <p class="hms-dash-card-lead mb-0">Report issues for your active stay.</p>
                <div class="hms-dash-stat-grid hms-dash-stat-grid--single flex-grow-1">
                    <div class="hms-dash-stat hms-dash-stat--wide">
                        <div class="hms-dash-stat-label">Open requests</div>
                        <div class="hms-dash-stat-value"><?php echo e((string)$openMaint); ?></div>
                    </div>
                </div>
                <div class="hms-dash-card-foot pt-3 mt-auto">
                    <a class="btn btn-sm btn-outline-primary w-100" href="<?php echo hms_url('maintenance.php'); ?>">Manage requests</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<div class="row g-4 hms-dash-metrics-row">
    <?php
        // For wardens and university admins, use the same scope/rules as booking approvals queue.
        if ($role === 'warden') {
            $pendingCount = (int) (fetch_one(
                $db,
                'SELECT COUNT(*) AS c
                 FROM bookings b
                 JOIN rooms r ON r.id = b.room_id
                 JOIN hostels h ON h.id = r.hostel_id
                 WHERE b.status = "pending"
                   AND h.managed_by = ?',
                [$userId]
            )['c'] ?? 0);
        } else {
            $pendingCount = (int) (fetch_one(
                $db,
                'SELECT COUNT(*) AS c
                 FROM bookings b
                 JOIN rooms r ON r.id = b.room_id
                 JOIN hostels h ON h.id = r.hostel_id
                 WHERE b.status = "pending"
                   AND EXISTS (
                       SELECT 1
                       FROM audit_logs al
                       WHERE al.entity_type = "hostel"
                         AND al.action = "hostel_created"
                         AND al.entity_id = h.id
                         AND al.actor_user_id = ?
                   )',
                [$userId]
            )['c'] ?? 0);
        }
    ?>
    <div class="col-lg-6 d-flex">
        <div class="card border-0 shadow-sm rounded-4 h-100 w-100 hms-stat-card hms-stat-card--blue hms-dash-metric-card">
            <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="hms-stat-icon mb-0"><?php echo hms_stat_icon_svg('queue'); ?></span>
                    <h3 class="h5 mb-0">Operations queue</h3>
                </div>
                <p class="hms-dash-card-lead mb-0">Bookings waiting for your review.</p>
                <div class="hms-dash-stat-grid hms-dash-stat-grid--single flex-grow-1">
                    <div class="hms-dash-stat hms-dash-stat--wide">
                        <div class="hms-dash-stat-label">Pending bookings</div>
                        <div class="hms-dash-stat-value"><?php echo e((string)$pendingCount); ?></div>
                    </div>
                </div>
                <div class="hms-dash-card-foot pt-3 mt-auto d-flex flex-wrap gap-2">
                    <a class="btn btn-sm btn-primary" href="<?php echo hms_url('admin/bookings.php'); ?>">Booking approvals</a>
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo hms_url('admin/maintenance.php'); ?>">Maintenance</a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6 d-flex">
        <div class="card border-0 shadow-sm rounded-4 h-100 w-100 hms-stat-card hms-stat-card--indigo hms-dash-metric-card">
            <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="hms-stat-icon mb-0"><?php echo hms_stat_icon_svg('audit'); ?></span>
                    <h3 class="h5 mb-0">Accountability</h3>
                </div>
                <p class="hms-dash-card-lead mb-0">Your actions in the last 7 days.</p>
                <div class="hms-dash-stat-grid hms-dash-stat-grid--single flex-grow-1">
                    <div class="hms-dash-stat hms-dash-stat--wide">
                        <div class="hms-dash-stat-label">Audit entries</div>
                        <div class="hms-dash-stat-value">
                            <?php
                                $recentAudit = fetch_one($db, 'SELECT COUNT(*) AS c FROM audit_logs WHERE actor_user_id = ? AND created_at >= (NOW() - INTERVAL 7 DAY)', [$userId]);
                                echo e((string)($recentAudit['c'] ?? 0));
                            ?>
                        </div>
                    </div>
                </div>
                <div class="hms-dash-card-foot pt-3 mt-auto">
                    <a class="btn btn-sm btn-outline-primary w-100" href="<?php echo hms_url('admin/reports.php'); ?>">View reports</a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($role === 'university_admin'): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h3 class="h5 mb-2">Hostel Setup</h3>
                <p class="text-muted mb-3">Register hostels and then add rooms for each hostel.</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-primary" href="<?php echo hms_url('admin/bookings.php'); ?>">Booking Approvals</a>
                    <a class="btn btn-primary" href="<?php echo hms_url('admin/hostels.php'); ?>">Register Hostel</a>
                    <a class="btn btn-outline-primary" href="<?php echo hms_url('admin/rooms.php'); ?>">Register Rooms</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<?php if ($role === 'student'): ?>
<div class="row g-4 mt-1">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <div class="d-flex align-items-start justify-content-between">
                    <div>
                        <h3 class="h5 mb-2">Next / Recent Booking</h3>
                        <p class="text-muted mb-0">Your latest booking activity with hostel and room details.</p>
                    </div>
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo hms_url('bookings.php'); ?>">View all</a>
                </div>
                <hr>
                <?php if (!$nextBooking): ?>
                    <div class="text-muted">No bookings yet. Browse hostels and request a room.</div>
                <?php else: ?>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="small text-muted">Hostel</div>
                            <div class="fw-semibold"><?php echo e($nextBooking['hostel_name'] ?? ''); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted">Room</div>
                            <div class="fw-semibold"><?php echo e($nextBooking['room_number'] ?? ''); ?></div>
                        </div>
                        <div class="col-md-6 mt-3">
                            <div class="small text-muted">Status</div>
                            <div class="fw-semibold"><?php echo e($nextBooking['status'] ?? ''); ?></div>
                        </div>
                        <div class="col-md-6 mt-3">
                            <div class="small text-muted">Requested at</div>
                            <div class="fw-semibold"><?php echo e((string)($nextBooking['requested_at'] ?? '')); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h3 class="h5 mb-2">Quick actions</h3>
                <div class="d-grid gap-2">
                    <a class="btn btn-primary" href="<?php echo hms_url('hostels.php'); ?>">Browse Hostels</a>
                    <a class="btn btn-outline-primary" href="<?php echo hms_url('maintenance.php'); ?>">Create Maintenance Request</a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h3 class="h5 mb-2">Create Maintenance Request</h3>
                <p class="text-muted small mb-3">Submit maintenance issues directly from your dashboard.</p>
                <?php if (empty($activeBookings)): ?>
                    <div class="alert alert-info mb-0">
                        No approved/checked-in bookings yet. Create a booking first, then request maintenance.
                    </div>
                <?php else: ?>
                    <form method="post" action=""<?php echo hms_data_confirm('Submit this maintenance request?'); ?>>
                        <input type="hidden" name="action" value="create_maintenance">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <div class="row g-3">
                            <div class="col-lg-4">
                                <label class="form-label">Booking</label>
                                <select name="booking_id" class="form-select" required>
                                    <option value="">Select booking...</option>
                                    <?php foreach ($activeBookings as $b): ?>
                                        <option value="<?php echo (int)$b['booking_id']; ?>">
                                            <?php echo e($b['hostel_name']); ?> - Room <?php echo e($b['room_number']); ?> (<?php echo e($b['status']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label">Title</label>
                                <input type="text" name="title" class="form-control" required minlength="3">
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label">Priority</label>
                                <select name="priority" class="form-select">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="3" required minlength="10"></textarea>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" type="submit">Submit Request</button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php layout_footer(); ?>

