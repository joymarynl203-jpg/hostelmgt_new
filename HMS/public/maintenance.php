<?php
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../db.php';

require_login();
$user = hms_current_user();
$userId = (int)$user['id'];
$db = hms_db();

require_role(['student']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

                    // Notification/audit failures should not block request creation.
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
                        // Keep core flow working even if admin lookup fails.
                    }

                    $wardenAndAdmins = array_values(array_unique($wardenAndAdmins));
                    foreach ($wardenAndAdmins as $uid) {
                        if ($uid > 0) {
                            try {
                                hms_notify_maintenance_new_request($uid, $title, $description);
                            } catch (Throwable $e) {
                                // Ignore non-critical notification errors.
                            }
                        }
                    }

                    try {
                        hms_audit_log($userId, 'maintenance_created', 'maintenance_request', $maintenanceId, 'Maintenance request created.');
                    } catch (Throwable $e) {
                        // Ignore non-critical audit log errors.
                    }

                    flash_set('success', 'Maintenance request submitted successfully.');
                } catch (Throwable $e) {
                    flash_set('error', 'Could not submit maintenance request right now. Please try again.');
                }
                redirect_to(hms_url('maintenance.php'));
            }
        }
    }
}

$activeBookings = $db->prepare('
    SELECT b.id AS booking_id, b.status, r.room_number, h.name AS hostel_name
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN hostels h ON h.id = r.hostel_id
    WHERE b.student_id = ?
      AND b.status IN ("approved","checked_in")
    ORDER BY b.requested_at DESC
');
$activeBookings->execute([$userId]);
$activeBookings = $activeBookings->fetchAll();

$requests = $db->prepare('
    SELECT mr.*, b.status AS booking_status,
           r.room_number, h.name AS hostel_name
    FROM maintenance_requests mr
    LEFT JOIN bookings b ON b.id = mr.booking_id
    LEFT JOIN rooms r ON r.id = mr.room_id
    LEFT JOIN hostels h ON h.id = r.hostel_id
    WHERE mr.student_id = ?
    ORDER BY mr.created_at DESC
');
$requests->execute([$userId]);
$requests = $requests->fetchAll();

/**
 * @param array<string, mixed> $data
 */
function hms_maintenance_row_attr_json(array $data): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    return htmlspecialchars(json_encode($data, $flags), ENT_QUOTES, 'UTF-8');
}

layout_header('Maintenance Requests');
?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h2 class="h4 mb-3">Create Maintenance Request</h2>
                <?php if (empty($activeBookings)): ?>
                    <div class="alert alert-info">
                        No approved/checked-in bookings yet. Create a booking first, then request maintenance.
                    </div>
                <?php else: ?>
                    <form method="post" action=""<?php echo hms_data_confirm('Submit this maintenance request?'); ?>>
                        <input type="hidden" name="action" value="create_maintenance">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

                        <div class="mb-3">
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

                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" name="title" class="form-control" required minlength="3">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" required rows="4" minlength="10"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>

                        <button class="btn btn-primary w-100" type="submit">Submit Request</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h2 class="h4 mb-3">Your Maintenance Requests</h2>
                <p class="text-muted small mb-3">Click a row to read the full description and details.</p>
                <?php if (empty($requests)): ?>
                    <div class="alert alert-info">No maintenance requests yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Hostel/Room</th>
                                    <th>Requested</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($requests as $mr): ?>
                                <?php
                                    $mrPayload = [
                                        'title' => (string)($mr['title'] ?? ''),
                                        'description' => (string)($mr['description'] ?? ''),
                                        'status' => (string)($mr['status'] ?? ''),
                                        'priority' => (string)($mr['priority'] ?? ''),
                                        'hostel_name' => (string)($mr['hostel_name'] ?? ''),
                                        'room_number' => (string)($mr['room_number'] ?? ''),
                                        'created_at' => (string)($mr['created_at'] ?? ''),
                                    ];
                                ?>
                                <tr class="hms-mr-row align-middle"
                                    role="button" tabindex="0"
                                    style="cursor: pointer;"
                                    data-hms-mr="<?php echo hms_maintenance_row_attr_json($mrPayload); ?>">
                                    <td><?php echo e($mr['title']); ?></td>
                                    <td>
                                        <span class="badge
                                            <?php
                                                echo match ($mr['status']) {
                                                    'open' => 'bg-warning text-dark',
                                                    'in_progress' => 'bg-info text-dark',
                                                    'resolved' => 'bg-success',
                                                    'closed' => 'bg-secondary',
                                                    default => 'bg-secondary'
                                                };
                                            ?>">
                                            <?php echo e($mr['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo e($mr['priority']); ?></td>
                                    <td class="text-muted small">
                                        <?php echo e($mr['hostel_name'] ?? ''); ?> / <?php echo e('Room ' . ($mr['room_number'] ?? '')); ?>
                                    </td>
                                    <td class="text-muted small"><?php echo e((string)$mr['created_at']); ?></td>
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

<div class="modal fade" id="mrDetailModal" tabindex="-1" aria-labelledby="mrDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content rounded-4">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 mb-0" id="mrDetailModalLabel">Maintenance request</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-3">
                <dl class="row mb-0 small">
                    <dt class="col-sm-3 text-muted">Status</dt>
                    <dd class="col-sm-9"><span id="mrModalStatus"></span></dd>
                    <dt class="col-sm-3 text-muted">Priority</dt>
                    <dd class="col-sm-9" id="mrModalPriority"></dd>
                    <dt class="col-sm-3 text-muted">Hostel / room</dt>
                    <dd class="col-sm-9" id="mrModalPlace"></dd>
                    <dt class="col-sm-3 text-muted">Requested</dt>
                    <dd class="col-sm-9" id="mrModalWhen"></dd>
                </dl>
                <hr class="my-3">
                <div class="fw-semibold mb-2">Description</div>
                <div id="mrModalDescription" class="text-body" style="white-space: pre-wrap;"></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.hms-mr-row:hover { --bs-table-accent-bg: var(--bs-secondary-bg); }
</style>
<script>
(function () {
    var modalEl = document.getElementById('mrDetailModal');
    if (!modalEl) {
        return;
    }
    function badgeClass(status) {
        switch (status) {
            case 'open': return 'bg-warning text-dark';
            case 'in_progress': return 'bg-info text-dark';
            case 'resolved': return 'bg-success';
            case 'closed': return 'bg-secondary';
            default: return 'bg-secondary';
        }
    }
    function applyMrPayload(d) {
        var title = d.title || 'Maintenance request';
        var desc = d.description || '';
        var status = d.status || '';
        var priority = d.priority || '';
        var hostel = d.hostel_name || '';
        var room = d.room_number || '';
        var when = d.created_at || '';

        var titleEl = document.getElementById('mrDetailModalLabel');
        var statusEl = document.getElementById('mrModalStatus');
        var priEl = document.getElementById('mrModalPriority');
        var placeEl = document.getElementById('mrModalPlace');
        var whenEl = document.getElementById('mrModalWhen');
        var descEl = document.getElementById('mrModalDescription');

        if (titleEl) titleEl.textContent = title;
        if (statusEl) {
            statusEl.className = 'badge ' + badgeClass(status);
            statusEl.textContent = status;
        }
        if (priEl) priEl.textContent = priority;
        if (placeEl) {
            var place = (hostel || room) ? (hostel + (hostel && room ? ' — ' : '') + (room ? 'Room ' + room : '')) : '—';
            placeEl.textContent = place;
        }
        if (whenEl) whenEl.textContent = when || '—';
        if (descEl) descEl.textContent = desc || '—';
    }
    function openMrDetailFromRow(row) {
        var raw = row.getAttribute('data-hms-mr');
        var d = {};
        if (raw) {
            try { d = JSON.parse(raw); } catch (e) { d = {}; }
        }
        applyMrPayload(d);
        if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }
    document.querySelectorAll('.hms-mr-row').forEach(function (row) {
        row.addEventListener('click', function () {
            openMrDetailFromRow(row);
        });
        row.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openMrDetailFromRow(row);
            }
        });
    });
})();
</script>

<?php layout_footer(); ?>

