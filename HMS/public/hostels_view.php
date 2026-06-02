<?php
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/maps.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/uploads.php';

require_login();

$db = hms_db();
$user = hms_current_user();
$userId = (int) $user['id'];
$hasNearbyInstitutions = hms_table_has_column($db, 'hostels', 'nearby_institutions');
$hasHostelGallery = hms_image_gallery_enabled($db);
$hasRoomGallery = $hasHostelGallery;

$hostelId = (int) ($_GET['hostel_id'] ?? 0);
if ($hostelId <= 0) {
    http_response_code(400);
    echo 'hostel_id is required.';
    exit;
}

$hostelStmt = $db->prepare('SELECT * FROM hostels WHERE id = ?');
$hostelStmt->execute([$hostelId]);
$hostel = $hostelStmt->fetch();
if (!$hostel || (int)$hostel['is_active'] !== 1) {
    http_response_code(404);
    echo 'Hostel not found.';
    exit;
}

// For warden, restrict to hostels they manage unless hostel has no manager.
if ($user['role'] === 'warden' && (int)$hostel['managed_by'] !== 0 && (int)$hostel['managed_by'] !== $userId) {
    // If managed_by is NULL, the cast becomes 0. We allow that. For any other mismatch, deny.
    if ($hostel['managed_by'] !== null) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$roomsSql = '
    SELECT
        r.*,
        (SELECT COUNT(*) FROM bookings b
         WHERE b.room_id = r.id AND b.status IN ("pending", "approved", "checked_in")) AS reserved_bookings,
        (SELECT COUNT(*) FROM payments p
         WHERE p.room_id = r.id AND p.booking_id IS NULL
           AND p.status IN ("pending", "successful")) AS reserved_prebook
    FROM rooms r
    WHERE r.hostel_id = ?
    ORDER BY r.gender ASC, r.room_number ASC
';
$roomsStmt = $db->prepare($roomsSql);
$roomsStmt->execute([$hostelId]);
$rooms = $roomsStmt->fetchAll();

$hostelImages = $hasHostelGallery ? hms_gallery_fetch($db, 'hostel', $hostelId) : [];
$roomGalleryById = $hasRoomGallery
    ? hms_gallery_fetch_bulk($db, 'room', array_map(static fn (array $r): int => (int)($r['id'] ?? 0), $rooms))
    : [];

layout_header('Hostel Rooms - ' . ($hostel['name'] ?? ''));
?>

<div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-3">
    <div class="flex-grow-1">
        <?php if (!empty($hostelImages)): ?>
            <?php echo hms_render_image_gallery($hostelImages, 'hostel-view-' . $hostelId, (string)$hostel['name'], 280, 'rounded-4 border shadow-sm mb-3 w-100', true); ?>
        <?php endif; ?>
        <h2 class="h4 mb-1"><?php echo e($hostel['name']); ?></h2>
        <div class="text-muted small"><?php echo e($hostel['location']); ?></div>
        <?php if ($hasNearbyInstitutions && !empty($hostel['nearby_institutions'])): ?>
            <div class="text-muted small">Nearby institutions: <?php echo e((string)$hostel['nearby_institutions']); ?></div>
        <?php endif; ?>
        <?php
            $pin = hms_hostel_map_lat_lng($hostel);
            if ($pin !== null):
                $extMap = hms_google_maps_external_url($pin['lat'], $pin['lng']);
                $embedSrc = hms_google_maps_embed_url($pin['lat'], $pin['lng']);
        ?>
            <div class="mt-3">
                <?php if ($embedSrc !== ''): ?>
                    <div class="small text-muted mb-1">Location on map</div>
                    <iframe class="rounded w-100 border-0 shadow-sm" style="min-height: 220px; max-width: 520px;" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Map: <?php echo e($hostel['name']); ?>" src="<?php echo e($embedSrc); ?>"></iframe>
                <?php else: ?>
                    <div class="small text-muted mb-1">Location on map</div>
                <?php endif; ?>
                <a class="btn btn-outline-primary btn-sm mt-2" href="<?php echo e($extMap); ?>" target="_blank" rel="noopener noreferrer">Open in Google Maps</a>
            </div>
        <?php endif; ?>
        <?php
            $rpS = (string)($hostel['rent_period_start'] ?? '');
            $rpE = (string)($hostel['rent_period_end'] ?? '');
        ?>
        <?php if ($rpS !== '' && $rpE !== ''): ?>
            <div class="small mt-2">
                <span class="badge bg-light text-dark border">Rental semester</span>
                <span class="fw-semibold"><?php echo e($rpS); ?></span>
                <span class="text-muted">to</span>
                <span class="fw-semibold"><?php echo e($rpE); ?></span>
            </div>
            <div class="text-muted small mt-1">Student bookings use exactly these dates for the stay (no other start or end).</div>
        <?php elseif ($user['role'] === 'student'): ?>
            <div class="alert alert-warning mt-2 mb-0 py-2 small">This hostel has not published rental dates yet. You cannot book here until the university admin sets the semester window.</div>
        <?php endif; ?>
    </div>
    <?php if ($user['role'] !== 'student'): ?>
        <a class="btn btn-outline-primary" href="<?php echo hms_url('admin/rooms.php?hostel_id=' . $hostelId); ?>">Manage Rooms</a>
    <?php endif; ?>
</div>

<?php if (empty($rooms)): ?>
    <div class="alert alert-info">No rooms have been created for this hostel yet.</div>
<?php endif; ?>

<div class="row g-3">
    <?php foreach ($rooms as $r): ?>
        <?php
            $cap = (int)$r['capacity'];
            $occ = (int)$r['current_occupancy'];
            $rb = (int)($r['reserved_bookings'] ?? 0);
            $rp = (int)($r['reserved_prebook'] ?? 0);
            $reserved = $rb + $rp;
            $slotsLeft = max(0, $cap - $reserved);
            $available = $slotsLeft > 0;
            $roomImages = $roomGalleryById[(int)($r['id'] ?? 0)] ?? [];
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 border-0 shadow-sm rounded-4 overflow-hidden">
                <?php if (!empty($roomImages)): ?>
                    <?php echo hms_render_image_gallery($roomImages, 'room-card-' . (int)$r['id'], 'Room ' . $r['room_number'], 160, 'card-img-top', true); ?>
                <?php endif; ?>
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold">Room <?php echo e($r['room_number']); ?></div>
                            <div class="text-muted small">Gender: <?php echo e($r['gender']); ?></div>
                        </div>
                        <span class="badge <?php echo $available ? 'bg-success' : 'bg-secondary'; ?>">
                            <?php echo $available ? 'Available' : 'Full'; ?>
                        </span>
                    </div>

                    <div class="mt-3 small text-muted">
                        <div class="mb-1">Shared room for up to <span class="fw-semibold text-dark"><?php echo e((string)$cap); ?></span> student(s).</div>
                        <div>Checked in (physical): <span class="fw-semibold text-dark"><?php echo e((string)$occ); ?></span> / <?php echo e((string)$cap); ?></div>
                        <div>Reserved slots (paid requests + deposits in progress): <span class="fw-semibold text-dark"><?php echo e((string)$reserved); ?></span> / <?php echo e((string)$cap); ?></div>
                        <div class="mt-1"><?php if ($slotsLeft > 0): ?>
                            <span class="text-success"><?php echo e((string)$slotsLeft); ?> place(s) still open to book.</span>
                        <?php else: ?>
                            <span class="text-secondary">No open booking slots right now.</span>
                        <?php endif; ?></div>
                    </div>

                    <div class="mt-3 small text-muted">
                        Semester fee: <span class="fw-semibold text-dark"><?php echo e((string)$r['monthly_fee']); ?> UGX</span>
                    </div>

                    <?php if ($user['role'] === 'student'): ?>
                        <div class="mt-3 d-grid gap-2">
                            <?php if ($rpS !== '' && $rpE !== '' && $available): ?>
                                <a class="btn btn-primary" href="<?php echo hms_url('bookings.php?room_id=' . (int)$r['id']); ?>">
                                    Request this room
                                </a>
                            <?php elseif ($rpS === '' || $rpE === ''): ?>
                                <button class="btn btn-outline-secondary" disabled>Booking closed</button>
                            <?php else: ?>
                                <button class="btn btn-outline-secondary" disabled>Full — all <?php echo e((string)$cap); ?> slots taken</button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="mt-3 d-grid gap-2">
                            <a class="btn btn-outline-primary" href="<?php echo hms_url('admin/rooms.php?room_id=' . (int)$r['id']); ?>">
                                Edit room
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php layout_footer(); ?>

