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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    $bookingId = (int)($_POST['booking_id'] ?? 0);

    if ($bookingId <= 0) {
        flash_set('error', 'Booking is required.');
        redirect_to(hms_url('admin/bookings.php'));
    }

    // Validate the booking is visible/allowed for the actor (important for warden).
    $chkStmt = $db->prepare('
        SELECT b.id, b.status, b.student_id, r.id AS room_id, h.id AS hostel_id, h.managed_by
        FROM bookings b
        JOIN rooms r ON r.id = b.room_id
        JOIN hostels h ON h.id = r.hostel_id
        WHERE b.id = ?
    ');
    $chkStmt->execute([$bookingId]);
    $booking = $chkStmt->fetch();

    if (!$booking) {
        flash_set('error', 'Booking not found.');
        redirect_to(hms_url('admin/bookings.php'));
    }

    if ($role === 'warden') {
        if ((int)($booking['managed_by'] ?? 0) !== $userId) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    } elseif ($role === 'university_admin') {
        $adminChk = $db->prepare('
            SELECT h.id
            FROM hostels h
            WHERE h.id = ?
              AND ' . $adminHostelScope . '
            LIMIT 1
        ');
        $adminChk->execute([(int)($booking['hostel_id'] ?? 0), $userId]);
        if (!$adminChk->fetch()) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

    $studentId = (int)$booking['student_id'];
    $roomId = (int)$booking['room_id'];

    if ($action === 'approve_booking') {
        if ($booking['status'] !== 'pending') {
            flash_set('error', 'Only pending bookings can be approved.');
            redirect_to(hms_url('admin/bookings.php'));
        }

        $depStmt = $db->prepare('
            SELECT
                b.total_due,
                (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.booking_id = b.id AND p.status = "successful") AS paid_amount
            FROM bookings b
            WHERE b.id = ?
            LIMIT 1
        ');
        $depStmt->execute([$bookingId]);
        $dep = $depStmt->fetch() ?: ['total_due' => 0, 'paid_amount' => 0];
        $requiredDeposit = ((float)$dep['total_due']) * 0.20;
        $paidAmount = (float)$dep['paid_amount'];
        if ($paidAmount < $requiredDeposit) {
            flash_set('error', 'Cannot approve booking yet. Student must pay at least 20% deposit first.');
            redirect_to(hms_url('admin/bookings.php'));
        }

        $db->prepare('
            UPDATE bookings
            SET status = "approved",
                approved_by = ?,
                approved_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ')->execute([$userId, $bookingId]);

        hms_notify($studentId, 'Your hostel booking request was approved.', 'booking');
        hms_audit_log($userId, 'booking_approved', 'booking', $bookingId, 'Approved by actor.');
        flash_set('success', 'Booking approved.');
        redirect_to(hms_url('admin/bookings.php'));
    }

    if ($action === 'reject_booking') {
        if (!in_array($booking['status'], ['pending'], true)) {
            flash_set('error', 'Only pending bookings can be rejected.');
            redirect_to(hms_url('admin/bookings.php'));
        }

        $db->prepare('UPDATE bookings SET status = "rejected", updated_at = NOW() WHERE id = ?')->execute([$bookingId]);
        hms_notify($studentId, 'Your hostel booking request was rejected.', 'booking');
        hms_audit_log($userId, 'booking_rejected', 'booking', $bookingId, 'Rejected by actor.');
        flash_set('success', 'Booking rejected.');
        redirect_to(hms_url('admin/bookings.php'));
    }

    if ($action === 'check_in') {
        if ($booking['status'] !== 'approved') {
            flash_set('error', 'Only approved bookings can be checked in.');
            redirect_to(hms_url('admin/bookings.php'));
        }

        $db->beginTransaction();
        try {
            // Lock room row for safe occupancy update
            $roomStmt = $db->prepare('SELECT id, current_occupancy, capacity FROM rooms WHERE id = ? FOR UPDATE');
            $roomStmt->execute([$roomId]);
            $room = $roomStmt->fetch();

            if (!$room) {
                throw new RuntimeException('Room not found.');
            }

            $current = (int)$room['current_occupancy'];
            $capacity = (int)$room['capacity'];
            if ($current >= $capacity) {
                throw new RuntimeException('Room is full. Cannot check in.');
            }

            $roomsUpdatedStmt = $db->prepare('
                UPDATE rooms
                SET current_occupancy = current_occupancy + 1
                WHERE id = ? AND current_occupancy < capacity
            ');
            $roomsUpdatedStmt->execute([$roomId]);
            if ($roomsUpdatedStmt->rowCount() !== 1) {
                throw new RuntimeException('Room occupancy update failed (room may have become full).');
            }

            $bookingUpdatedStmt = $db->prepare('
                UPDATE bookings
                SET status = "checked_in",
                    checked_in_by = ?,
                    checked_in_at = NOW(),
                    updated_at = NOW()
                WHERE id = ? AND status = "approved"
            ');
            $bookingUpdatedStmt->execute([$userId, $bookingId]);
            if ($bookingUpdatedStmt->rowCount() !== 1) {
                throw new RuntimeException('Booking status update failed (booking no longer approved).');
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            flash_set('error', 'Check-in failed: ' . $e->getMessage());
            redirect_to(hms_url('admin/bookings.php'));
        }

        hms_notify($studentId, 'You have been checked in to your hostel room.', 'booking');
        hms_audit_log($userId, 'booking_checked_in', 'booking', $bookingId, 'Checked in.');
        flash_set('success', 'Check-in completed.');
        redirect_to(hms_url('admin/bookings.php'));
    }

    if ($action === 'check_out') {
        if ($role === 'warden') {
            flash_set('error', 'Only university administrators can record departure (check-out).');
            redirect_to(hms_url('admin/bookings.php'));
        }
        // Departure is only recorded here (never inferred from booking end_date or hostel semester).
        // Until check-out, checked_in residents keep the bed; the room stays non-bookable for new requests.
        if ($booking['status'] !== 'checked_in') {
            flash_set('error', 'Only checked-in bookings can be checked out.');
            redirect_to(hms_url('admin/bookings.php'));
        }

        $db->beginTransaction();
        try {
            $roomStmt = $db->prepare('SELECT id, current_occupancy FROM rooms WHERE id = ? FOR UPDATE');
            $roomStmt->execute([$roomId]);
            $room = $roomStmt->fetch();
            if (!$room) {
                throw new RuntimeException('Room not found.');
            }

            $current = (int)$room['current_occupancy'];
            if ($current <= 0) {
                throw new RuntimeException('No occupancy to decrement.');
            }

            $roomsUpdatedStmt = $db->prepare('
                UPDATE rooms
                SET current_occupancy = current_occupancy - 1
                WHERE id = ? AND current_occupancy > 0
            ');
            $roomsUpdatedStmt->execute([$roomId]);
            if ($roomsUpdatedStmt->rowCount() !== 1) {
                throw new RuntimeException('Room occupancy decrement failed.');
            }

            $bookingUpdatedStmt = $db->prepare('
                UPDATE bookings
                SET status = "checked_out",
                    checked_out_by = ?,
                    checked_out_at = NOW(),
                    updated_at = NOW()
                WHERE id = ? AND status = "checked_in"
            ');
            $bookingUpdatedStmt->execute([$userId, $bookingId]);
            if ($bookingUpdatedStmt->rowCount() !== 1) {
                throw new RuntimeException('Booking status update failed (booking no longer checked-in).');
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            flash_set('error', 'Check-out failed: ' . $e->getMessage());
            redirect_to(hms_url('admin/bookings.php'));
        }

        hms_notify($studentId, 'Your hostel has recorded your departure from the room. Your bed is no longer reserved for you.', 'booking');
        hms_audit_log($userId, 'booking_checked_out', 'booking', $bookingId, 'Departure recorded; bed released for new bookings.');
        flash_set('success', 'Departure recorded. The bed is now available for new bookings.');
        redirect_to(hms_url('admin/bookings.php'));
    }
}

$queueSql = '
    SELECT b.*,
        u.name AS student_name,
        r.room_number,
        h.name AS hostel_name,
        h.id AS hostel_id,
        r.capacity,
        r.current_occupancy
    FROM bookings b
    JOIN users u ON u.id = b.student_id
    JOIN rooms r ON r.id = b.room_id
    JOIN hostels h ON h.id = r.hostel_id
    WHERE b.status IN ("pending","approved","checked_in")
';
if ($role === 'warden') {
    $queueSql .= ' AND h.managed_by = ? ';
} elseif ($role === 'university_admin') {
    $queueSql .= ' AND ' . $adminHostelScope . ' ';
}
$queueSql .= ' ORDER BY b.requested_at DESC ';

$params = [];
if ($role === 'warden' || $role === 'university_admin') {
    $params[] = $userId;
}
$stmt = $db->prepare($queueSql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

layout_header('Booking Approvals');
?>

<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h2 class="h4 mb-1">Booking Approvals & Room Allocation</h2>
        <div class="text-muted small">Approve pending requests, check in when the student takes the bed<?php echo $role === 'university_admin' ? ', and record departure only when they have actually left' : '; a university administrator records departure when the student has left'; ?>. Residents stay allocated (and the room stays unavailable to others) after the booking semester dates until check-out—nothing is cleared automatically when a semester ends.</div>
    </div>
    <a class="btn btn-outline-primary btn-sm" href="<?php echo hms_url('admin/reports.php'); ?>">Reports</a>
</div>

<?php if (empty($bookings)): ?>
    <div class="alert alert-info">No pending/active bookings in your queue.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th>Student</th>
                <th>Hostel</th>
                <th>Room</th>
                <th>Status</th>
                <th>Start</th>
                <th>Due</th>
                <th>Room Occupied</th>
                <th style="min-width: 260px;">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($bookings as $b): ?>
                <tr>
                    <td><?php echo e($b['student_name']); ?></td>
                    <td><?php echo e($b['hostel_name']); ?></td>
                    <td>Room <?php echo e($b['room_number']); ?></td>
                    <td>
                        <span class="badge
                            <?php
                                echo match ($b['status']) {
                                    'pending' => 'bg-warning text-dark',
                                    'approved' => 'bg-primary',
                                    'checked_in' => 'bg-success',
                                    default => 'bg-secondary'
                                };
                            ?>">
                            <?php echo e($b['status']); ?>
                        </span>
                    </td>
                    <td><?php echo e((string)($b['start_date'] ?? '')); ?></td>
                    <td><?php echo e((string)($b['total_due'] ?? 0)); ?> UGX</td>
                    <td class="text-muted small">
                        <?php echo e((string)$b['current_occupancy']); ?>/<?php echo e((string)$b['capacity']); ?>
                    </td>
                    <td>
                        <?php if ($b['status'] === 'pending'): ?>
                            <form method="post" action="" class="d-flex flex-wrap gap-2"<?php echo hms_data_confirm('Approve this booking request?'); ?>>
                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="approve_booking">
                                <input type="hidden" name="booking_id" value="<?php echo (int)$b['id']; ?>">
                                <button class="btn btn-sm btn-outline-success" type="submit">Approve</button>
                            </form>
                            <form method="post" action="" class="d-flex flex-wrap gap-2 mt-2"<?php echo hms_data_confirm('Reject this booking? The student will be notified.'); ?>>
                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="reject_booking">
                                <input type="hidden" name="booking_id" value="<?php echo (int)$b['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit">
                                    Reject
                                </button>
                            </form>
                        <?php elseif ($b['status'] === 'approved'): ?>
                            <form method="post" action=""<?php echo hms_data_confirm('Check in this student to the room?'); ?>>
                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="check_in">
                                <input type="hidden" name="booking_id" value="<?php echo (int)$b['id']; ?>">
                                <button class="btn btn-sm btn-success" type="submit">Check-in</button>
                            </form>
                        <?php elseif ($b['status'] === 'checked_in'): ?>
                            <?php if ($role === 'university_admin'): ?>
                            <form method="post" action=""<?php echo hms_data_confirm('Record that this student has left and release the bed? Occupancy will decrease and the room can be booked again.'); ?>>
                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="check_out">
                                <input type="hidden" name="booking_id" value="<?php echo (int)$b['id']; ?>">
                                <button class="btn btn-sm btn-outline-secondary" type="submit">Record departure</button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted small">Only a university administrator can record departure.</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php layout_footer(); ?>

