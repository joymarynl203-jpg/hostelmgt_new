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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    $requestId = (int)($_POST['request_id'] ?? 0);
    $newStatus = (string)($_POST['new_status'] ?? 'in_progress');

    if ($requestId <= 0 || !in_array($newStatus, ['in_progress', 'resolved', 'closed'], true)) {
        flash_set('error', 'Invalid request or status.');
        redirect_to(hms_url('admin/maintenance.php'));
    }

    // Validate visibility
    $chk = $db->prepare('
        SELECT mr.id, mr.status, mr.student_id, mr.title, mr.description, r.id AS room_id, h.managed_by
        FROM maintenance_requests mr
        JOIN rooms r ON r.id = COALESCE(mr.room_id, (SELECT room_id FROM bookings WHERE id = mr.booking_id))
        JOIN hostels h ON h.id = r.hostel_id
        WHERE mr.id = ?
    ');
    $chk->execute([$requestId]);
    $req = $chk->fetch();

    if (!$req) {
        flash_set('error', 'Maintenance request not found.');
        redirect_to(hms_url('admin/maintenance.php'));
    }

    if ($role === 'warden' && (int)($req['managed_by'] ?? 0) !== $userId) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    if ($action === 'update_status') {
        $resolvedAt = ($newStatus === 'resolved' || $newStatus === 'closed') ? 'NOW()' : 'NULL';
        $stmt = $db->prepare('
            UPDATE maintenance_requests
            SET status = ?, resolved_at = ' . $resolvedAt . ', updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$newStatus, $requestId]);

        hms_notify_maintenance_status_changed(
            (int)$req['student_id'],
            $newStatus,
            (string)($req['title'] ?? ''),
            (string)($req['description'] ?? '')
        );
        hms_audit_log($userId, 'maintenance_status_updated', 'maintenance_request', $requestId, 'Status set to ' . $newStatus);
        flash_set('success', 'Maintenance request updated.');
        redirect_to(hms_url('admin/maintenance.php'));
    }
}

$sql = '
    SELECT mr.*,
        u.name AS student_name,
        r.room_number,
        h.name AS hostel_name
    FROM maintenance_requests mr
    JOIN users u ON u.id = mr.student_id
    LEFT JOIN rooms r ON r.id = mr.room_id
    LEFT JOIN hostels h ON h.id = r.hostel_id
    WHERE mr.status IN ("open","in_progress","resolved","closed")
';
if ($role === 'warden') {
    $sql .= ' AND h.managed_by = ? ';
}
$sql .= ' ORDER BY mr.created_at DESC ';

$params = $role === 'warden' ? [$userId] : [];
$stmt = $db->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

/**
 * @param array<string, mixed> $data
 */
function hms_admin_mr_attr_json(array $data): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    return htmlspecialchars(json_encode($data, $flags), ENT_QUOTES, 'UTF-8');
}

layout_header('Maintenance Management');
?>

<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h2 class="h4 mb-1">Maintenance Requests</h2>
        <div class="text-muted small">Use <strong>View</strong> to read the full description. Update statuses to improve response time and accountability.</div>
    </div>
    <a class="btn btn-outline-primary btn-sm" href="<?php echo hms_url('admin/bookings.php'); ?>">Bookings</a>
</div>

<?php if (empty($requests)): ?>
    <div class="alert alert-info">No maintenance requests found.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th>Student</th>
                <th>Hostel/Room</th>
                <th>Title</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Requested</th>
                <th>Details</th>
                <th style="min-width: 260px;">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $mr): ?>
                <?php
                    $mrDetailPayload = [
                        'title' => (string)($mr['title'] ?? ''),
                        'description' => (string)($mr['description'] ?? ''),
                        'status' => (string)($mr['status'] ?? ''),
                        'priority' => (string)($mr['priority'] ?? ''),
                        'hostel_name' => (string)($mr['hostel_name'] ?? ''),
                        'room_number' => (string)($mr['room_number'] ?? ''),
                        'created_at' => (string)($mr['created_at'] ?? ''),
                        'student_name' => (string)($mr['student_name'] ?? ''),
                    ];
                ?>
                <tr>
                    <td><?php echo e($mr['student_name']); ?></td>
                    <td class="text-muted small">
                        <?php echo e($mr['hostel_name'] ?? ''); ?>
                        / <?php echo e('Room ' . ($mr['room_number'] ?? '')); ?>
                    </td>
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
                    <td class="text-muted small"><?php echo e((string)$mr['created_at']); ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-bs-toggle="modal" data-bs-target="#adminMrDetailModal"
                                data-hms-mr="<?php echo hms_admin_mr_attr_json($mrDetailPayload); ?>">
                            View
                        </button>
                    </td>
                    <td>
                        <?php if (in_array($mr['status'], ['open', 'in_progress'], true)): ?>
                            <form method="post" action="" class="d-flex flex-wrap gap-2"<?php echo hms_data_confirm('Update the status of this maintenance request?'); ?>>
                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="request_id" value="<?php echo (int)$mr['id']; ?>">
                                <select name="new_status" class="form-select form-select-sm" style="min-width: 140px;">
                                    <option value="in_progress" <?php echo $mr['status']==='open'?'selected':''; ?>>In Progress</option>
                                    <option value="resolved" <?php echo $mr['status']!=='resolved' ? '' : 'selected'; ?>>Resolved</option>
                                    <option value="closed" <?php echo $mr['status']!=='closed' ? '' : 'selected'; ?>>Closed</option>
                                </select>
                                <button class="btn btn-sm btn-primary" type="submit">Update</button>
                            </form>
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

<div class="modal fade" id="adminMrDetailModal" tabindex="-1" aria-labelledby="adminMrDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content rounded-4">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 mb-0" id="adminMrDetailModalLabel">Maintenance request</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-3">
                <dl class="row mb-0 small">
                    <dt class="col-sm-3 text-muted">Student</dt>
                    <dd class="col-sm-9" id="adminMrModalStudent"></dd>
                    <dt class="col-sm-3 text-muted">Status</dt>
                    <dd class="col-sm-9"><span id="adminMrModalStatus"></span></dd>
                    <dt class="col-sm-3 text-muted">Priority</dt>
                    <dd class="col-sm-9" id="adminMrModalPriority"></dd>
                    <dt class="col-sm-3 text-muted">Hostel / room</dt>
                    <dd class="col-sm-9" id="adminMrModalPlace"></dd>
                    <dt class="col-sm-3 text-muted">Requested</dt>
                    <dd class="col-sm-9" id="adminMrModalWhen"></dd>
                </dl>
                <hr class="my-3">
                <div class="fw-semibold mb-2">Description</div>
                <div id="adminMrModalDescription" class="text-body" style="white-space: pre-wrap;"></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var modalEl = document.getElementById('adminMrDetailModal');
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
    modalEl.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;
        var raw = trigger.getAttribute('data-hms-mr');
        var d = {};
        if (raw) {
            try { d = JSON.parse(raw); } catch (e) { d = {}; }
        }
        var title = d.title || 'Maintenance request';
        var desc = d.description || '';
        var status = d.status || '';
        var priority = d.priority || '';
        var hostel = d.hostel_name || '';
        var room = d.room_number || '';
        var when = d.created_at || '';
        var student = d.student_name || '';

        var titleEl = document.getElementById('adminMrDetailModalLabel');
        var studentEl = document.getElementById('adminMrModalStudent');
        var statusEl = document.getElementById('adminMrModalStatus');
        var priEl = document.getElementById('adminMrModalPriority');
        var placeEl = document.getElementById('adminMrModalPlace');
        var whenEl = document.getElementById('adminMrModalWhen');
        var descEl = document.getElementById('adminMrModalDescription');

        if (titleEl) titleEl.textContent = title;
        if (studentEl) studentEl.textContent = student || '—';
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
    });
})();
</script>

<?php layout_footer(); ?>

