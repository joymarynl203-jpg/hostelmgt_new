<?php
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/booking_pay_first.php';

require_login();

$user = hms_current_user();
require_role(['student']);

$db = hms_db();
$userId = (int) $user['id'];

$selectedRoomId = (int) ($_GET['room_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    csrf_verify($_POST['csrf_token'] ?? '');

    if ($action === 'cancel_booking') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            flash_set('error', 'Booking is required.');
        } else {
            $stmt = $db->prepare('
                SELECT b.*, r.room_number, h.name AS hostel_name, h.id AS hostel_id
                FROM bookings b
                JOIN rooms r ON r.id = b.room_id
                JOIN hostels h ON h.id = r.hostel_id
                WHERE b.id = ? AND b.student_id = ?
            ');
            $stmt->execute([$bookingId, $userId]);
            $booking = $stmt->fetch();
            if (!$booking) {
                flash_set('error', 'Booking not found.');
            } elseif ($booking['status'] !== 'pending') {
                flash_set('error', 'Only pending bookings can be cancelled.');
            } else {
                $db->prepare('UPDATE bookings SET status = "rejected", updated_at = NOW() WHERE id = ?')->execute([$bookingId]);
                hms_audit_log($userId, 'booking_cancelled', 'booking', $bookingId, 'Booking rejected/cancelled by student.');
                // Notify admins if needed
                $admins = $db->prepare('
                    SELECT al.actor_user_id AS id
                    FROM audit_logs al
                    WHERE al.entity_type = "hostel"
                      AND al.action = "hostel_created"
                      AND al.entity_id = ?
                ');
                $admins->execute([(int)($booking['hostel_id'] ?? 0)]);
                $admins = $admins->fetchAll();
                foreach ($admins as $a) {
                    $adminId = (int)($a['id'] ?? 0);
                    if ($adminId > 0) {
                        hms_notify($adminId, 'A booking request was cancelled by a student for ' . $booking['hostel_name'] . ' room ' . $booking['room_number'] . '.', 'booking');
                    }
                }
                flash_set('success', 'Booking cancelled.');
            }
        }
        redirect_to(hms_url('bookings.php'));
    }
}

// Room list for booking form (available rooms)
$availableRooms = $db->query('
    SELECT
        r.id,
        r.room_number,
        r.gender,
        r.capacity,
        r.current_occupancy,
        r.monthly_fee,
        h.name AS hostel_name,
        h.rent_period_start,
        h.rent_period_end,
        (SELECT COUNT(*) FROM bookings b
         WHERE b.room_id = r.id AND b.status IN ("pending", "approved", "checked_in")) AS reserved_bookings,
        (SELECT COUNT(*) FROM payments p
         WHERE p.room_id = r.id AND p.booking_id IS NULL
           AND p.status IN ("pending", "successful")) AS reserved_prebook
    FROM rooms r
    JOIN hostels h ON h.id = r.hostel_id
    WHERE h.is_active = 1
      AND h.rent_period_start IS NOT NULL
      AND h.rent_period_end IS NOT NULL
      AND (
          (SELECT COUNT(*) FROM bookings b
           WHERE b.room_id = r.id AND b.status IN ("pending", "approved", "checked_in"))
          + (SELECT COUNT(*) FROM payments p
             WHERE p.room_id = r.id AND p.booking_id IS NULL
               AND p.status IN ("pending", "successful"))
      ) < r.capacity
    ORDER BY h.name ASC, r.room_number ASC
')->fetchAll();

$prefRoom = null;
$prefRoomLinkNotice = null;
if ($selectedRoomId > 0) {
    $stmt = $db->prepare('
        SELECT r.*, h.name AS hostel_name, h.is_active,
            h.rent_period_start, h.rent_period_end,
            (SELECT COUNT(*) FROM bookings b
             WHERE b.room_id = r.id AND b.status IN ("pending", "approved", "checked_in")) AS reserved_bookings,
            (SELECT COUNT(*) FROM payments p
             WHERE p.room_id = r.id AND p.booking_id IS NULL
               AND p.status IN ("pending", "successful")) AS reserved_prebook
        FROM rooms r
        JOIN hostels h ON h.id = r.hostel_id
        WHERE r.id = ?
    ');
    $stmt->execute([$selectedRoomId]);
    $prefRoom = $stmt->fetch();
    if ($prefRoom) {
        $prs = trim((string)($prefRoom['rent_period_start'] ?? ''));
        $pre = trim((string)($prefRoom['rent_period_end'] ?? ''));
        if ((int)$prefRoom['is_active'] !== 1) {
            $prefRoom = null;
        } elseif ($prs === '' || $pre === '') {
            $prefRoom = null;
        } elseif (!hms_room_available_for_pay_first_deposit($db, $selectedRoomId)) {
            $prefRoom = null;
            $prefRoomLinkNotice = 'That room is not available (another student may be completing a deposit, or the room is allocated). Choose another room below.';
        }
    }
}
if ($selectedRoomId > 0 && !$prefRoom && $prefRoomLinkNotice === null) {
    $prefRoomLinkNotice = 'The room you opened is not available for booking (inactive, full, or semester dates not set for that hostel). Choose another room below.';
}

$bookings = $db->prepare('
    SELECT b.*,
        r.room_number,
        h.name AS hostel_name,
        (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.booking_id = b.id AND p.status = "successful") AS paid_amount
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN hostels h ON h.id = r.hostel_id
    WHERE b.student_id = ?
    ORDER BY b.requested_at DESC
');
$bookings->execute([$userId]);
$bookings = $bookings->fetchAll();

layout_header('My Bookings');
?>

<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h2 class="h4 mb-1">Book / Request a Room</h2>
        <div class="text-muted small">Your stay follows the hostel semester dates. You pay the <strong>20% deposit first via Pesapal</strong>; the booking is only created after payment succeeds, so two students cannot hold the same room while &quot;awaiting&quot; payment.</div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <?php if ($prefRoomLinkNotice): ?>
            <div class="alert alert-warning"><?php echo e($prefRoomLinkNotice); ?></div>
        <?php endif; ?>
        <?php if (!$availableRooms): ?>
            <div class="alert alert-info">No available rooms right now. Please check back later.</div>
        <?php else: ?>
            <form method="post" action="<?php echo hms_url('payment_start.php'); ?>">
                <input type="hidden" name="purpose" value="new_booking_deposit">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

                <div class="row g-3">
                    <div class="col-lg-6">
                        <label class="form-label">Room</label>
                        <?php if ($prefRoom): ?>
                            <input type="hidden" name="room_id" value="<?php echo (int)$prefRoom['id']; ?>">
                            <div class="form-control bg-light">
                                Room <?php echo e($prefRoom['room_number']); ?> - <?php echo e($prefRoom['hostel_name']); ?>
                                <div class="small text-muted mt-2">
                                    Shared by up to <?php echo e((string)(int)($prefRoom['capacity'] ?? 1)); ?> student(s).
                                    <?php
                                        $rb = (int)($prefRoom['reserved_bookings'] ?? 0);
                                        $rp = (int)($prefRoom['reserved_prebook'] ?? 0);
                                        $capP = (int)($prefRoom['capacity'] ?? 1);
                                        $taken = $rb + $rp;
                                        $left = max(0, $capP - $taken);
                                    ?>
                                    Places currently taken (requests + deposits in progress): <?php echo e((string)$taken); ?> / <?php echo e((string)$capP); ?>.
                                    <?php if ($left > 0): ?>
                                        <span class="text-success"><?php echo e((string)$left); ?> place(s) still open for booking.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <select name="room_id" class="form-select" required>
                                <option value="">Select a room...</option>
                                <?php foreach ($availableRooms as $r): ?>
                                    <?php
                                        $rb2 = (int)($r['reserved_bookings'] ?? 0);
                                        $rp2 = (int)($r['reserved_prebook'] ?? 0);
                                        $cap2 = (int)($r['capacity'] ?? 1);
                                        $taken2 = $rb2 + $rp2;
                                        $left2 = max(0, $cap2 - $taken2);
                                    ?>
                                    <option value="<?php echo (int)$r['id']; ?>"
                                        data-period-start="<?php echo e((string)($r['rent_period_start'] ?? '')); ?>"
                                        data-period-end="<?php echo e((string)($r['rent_period_end'] ?? '')); ?>">
                                        <?php echo e($r['hostel_name']); ?> - Room <?php echo e($r['room_number']); ?> (<?php echo e($r['gender']); ?>)
                                        — shared up to <?php echo e((string)$cap2); ?> people, <?php echo e((string)$left2); ?> place(s) left
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="col-lg-6">
                        <label class="form-label">Your stay (this semester)</label>
                        <?php if ($prefRoom): ?>
                            <?php
                                $ps = (string)($prefRoom['rent_period_start'] ?? '');
                                $pe = (string)($prefRoom['rent_period_end'] ?? '');
                            ?>
                            <div class="form-control bg-light">
                                <?php echo e($ps); ?> <span class="text-muted">to</span> <?php echo e($pe); ?>
                            </div>
                            <div class="form-text">These are the hostel's published dates; your booking will use them exactly.</div>
                        <?php else: ?>
                            <div id="hms-semester-stay" class="form-control bg-light text-muted" style="min-height: 2.5rem;">
                                Select a room to see the stay dates for this semester.
                            </div>
                            <div class="form-text">You cannot change dates; they always match the hostel's current rental semester.</div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12">
                        <button class="btn btn-primary" type="submit">Pay 20% deposit to request this room</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h2 class="h4 mb-1">Your Booking Requests</h2>
        <div class="text-muted small">Track status and view recorded payments. If you are checked in, you keep your allocation after the semester dates on this booking until hostel staff record your departure.</div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-4">
        <?php if (empty($bookings)): ?>
            <div class="alert alert-info">You have no bookings yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Hostel</th>
                            <th>Room</th>
                            <th>Status</th>
                            <th>Start</th>
                            <th>Due</th>
                            <th>Paid</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $b): ?>
                            <?php
                                $due = (float)($b['total_due'] ?? 0);
                                $paid = (float)($b['paid_amount'] ?? 0);
                                $outstanding = max(0, $due - $paid);
                                $depositRequired = $due * 0.20;
                                $depositRemaining = max(0, $depositRequired - $paid);
                            ?>
                            <tr>
                                <td><?php echo e($b['hostel_name']); ?></td>
                                <td>Room <?php echo e($b['room_number']); ?></td>
                                <td>
                                    <span class="badge
                                        <?php
                                            echo match ($b['status']) {
                                                'pending' => 'bg-warning text-dark',
                                                'approved' => 'bg-primary',
                                                'rejected' => 'bg-danger',
                                                'checked_in' => 'bg-success',
                                                'checked_out' => 'bg-secondary',
                                                default => 'bg-secondary',
                                            };
                                        ?>">
                                        <?php echo e($b['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo e((string)($b['start_date'] ?? '')); ?></td>
                                <td><?php echo e((string)$outstanding); ?> UGX</td>
                                <td><?php echo e((string)$paid); ?> UGX</td>
                                <td>
                                    <?php if ($b['status'] === 'pending' && $depositRemaining > 0): ?>
                                        <a class="btn btn-sm btn-warning" href="<?php echo hms_url('payment_start.php?booking_id=' . (int)$b['id'] . '&purpose=deposit'); ?>">
                                            Pay 20% Deposit
                                        </a>
                                        <form method="post" action="" class="d-inline ms-1"<?php echo hms_data_confirm('Cancel this booking request? This cannot be undone.'); ?>>
                                            <input type="hidden" name="action" value="cancel_booking">
                                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                            <input type="hidden" name="booking_id" value="<?php echo (int)$b['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">
                                                Cancel
                                            </button>
                                        </form>
                                    <?php elseif ($b['status'] === 'pending'): ?>
                                        <span class="badge bg-info text-dark">Awaiting admin review</span>
                                        <form method="post" action="" class="d-inline ms-1"<?php echo hms_data_confirm('Cancel this booking request? This cannot be undone.'); ?>>
                                            <input type="hidden" name="action" value="cancel_booking">
                                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                            <input type="hidden" name="booking_id" value="<?php echo (int)$b['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">
                                                Cancel
                                            </button>
                                        </form>
                                    <?php elseif (in_array($b['status'], ['approved', 'checked_in'], true) && $outstanding > 0): ?>
                                        <a class="btn btn-sm btn-success" href="<?php echo hms_url('payment_start.php?booking_id=' . (int)$b['id']); ?>">
                                            Pay with Pesapal
                                        </a>
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
    </div>
</div>

<?php if (!$prefRoom): ?>
<script>
(function () {
    var sel = document.querySelector('select[name="room_id"]');
    var box = document.getElementById('hms-semester-stay');
    if (!sel || !box) {
        return;
    }
    function applyRoom() {
        var opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) {
            box.textContent = 'Select a room to see the stay dates for this semester.';
            box.classList.add('text-muted');
            return;
        }
        var ps = opt.getAttribute('data-period-start') || '';
        var pe = opt.getAttribute('data-period-end') || '';
        if (!ps || !pe) {
            box.textContent = 'This hostel has not set semester dates yet; you cannot book this room.';
            box.classList.add('text-muted');
            return;
        }
        box.classList.remove('text-muted');
        box.textContent = ps + ' to ' + pe + ' (your stay will match these dates exactly).';
    }
    sel.addEventListener('change', applyRoom);
    applyRoom();
})();
</script>
<?php endif; ?>

<?php layout_footer(); ?>

