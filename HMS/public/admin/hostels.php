<?php
require_once __DIR__ . '/../../lib/layout.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/maps.php';
require_once __DIR__ . '/../../lib/uploads.php';

require_login();
require_role(['warden', 'university_admin']);

$db = hms_db();
$user = hms_current_user();
$userId = (int)$user['id'];
$role = $user['role'];
$hasNearbyInstitutions = hms_table_has_column($db, 'hostels', 'nearby_institutions');
$hasHostelGallery = hms_image_gallery_enabled($db);

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

    if ($action === 'create_hostel') {
        if ($role !== 'university_admin') {
            http_response_code(403);
            flash_set('error', 'Only university admins can create hostels.');
            redirect_to(hms_url('admin/hostels.php'));
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $nearbyInstitutions = trim((string)($_POST['nearby_institutions'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $managedBy = ((int)($_POST['managed_by'] ?? 0) ?: null);
        $managedBy = ($managedBy === 0 ? null : $managedBy);

        if ($name === '' || $location === '') {
            flash_set('error', 'Hostel name and location are required.');
            redirect_to(hms_url('admin/hostels.php'));
        }
        if (!$hasHostelGallery) {
            flash_set('error', 'Photo gallery tables are missing. Run database migration 011 first.');
            redirect_to(hms_url('admin/hostels.php'));
        }

        $photoUpload = hms_upload_images($_FILES['photos'] ?? [], 'hostels', false);
        if (!$photoUpload['ok']) {
            flash_set('error', $photoUpload['error'] ?? 'At least one hostel photo is required.');
            redirect_to(hms_url('admin/hostels.php'));
        }

        $latP = trim((string)($_POST['map_latitude'] ?? ''));
        $lngP = trim((string)($_POST['map_longitude'] ?? ''));
        $mc = hms_parse_map_coords($latP, $lngP);
        if ($mc === null) {
            hms_upload_delete_many($photoUpload['paths']);
            flash_set('error', 'Map location is required. Click the map to drop a pin, or enter valid latitude and longitude (decimal degrees).');
            redirect_to(hms_url('admin/hostels.php'));
        }
        $mapLat = $mc['lat'];
        $mapLng = $mc['lng'];

        try {
            $db->beginTransaction();
            if ($hasNearbyInstitutions) {
                $stmt = $db->prepare('INSERT INTO hostels (name, location, nearby_institutions, description, is_active, managed_by, map_latitude, map_longitude) VALUES (?, ?, ?, ?, 1, ?, ?, ?)');
                $ok = $stmt->execute([$name, $location, $nearbyInstitutions !== '' ? $nearbyInstitutions : null, $description, $managedBy, $mapLat, $mapLng]);
            } else {
                $stmt = $db->prepare('INSERT INTO hostels (name, location, description, is_active, managed_by, map_latitude, map_longitude) VALUES (?, ?, ?, 1, ?, ?, ?)');
                $ok = $stmt->execute([$name, $location, $description, $managedBy, $mapLat, $mapLng]);
            }
            if (!$ok) {
                throw new RuntimeException('insert_failed');
            }
            $newHostelId = (int)$db->lastInsertId();
            hms_gallery_insert($db, 'hostel', $newHostelId, $photoUpload['paths']);
            $db->commit();
            hms_audit_log($userId, 'hostel_created', 'hostel', $newHostelId, 'Hostel created by ' . $role . ' with ' . count($photoUpload['paths']) . ' photo(s).');
            flash_set('success', 'Hostel created.');
            redirect_to(hms_url('admin/hostels.php'));
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            hms_upload_delete_many($photoUpload['paths']);
            flash_set('error', 'Unable to create hostel. Check input values.');
            redirect_to(hms_url('admin/hostels.php'));
        }
    }

    if ($action === 'toggle_active') {
        $hostelId = (int)($_POST['hostel_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 1);
        if ($hostelId <= 0) {
            flash_set('error', 'Invalid hostel.');
        } else {
            if ($role === 'warden' && $isActive === 0) {
                flash_set('error', 'Only university admins can deactivate a hostel.');
                redirect_to(hms_url('admin/hostels.php'));
            }
            // Warden may activate their own managed hostel only; admins may toggle any hostel.
            if ($role === 'warden') {
                $chk = $db->prepare('SELECT id FROM hostels WHERE id = ? AND managed_by = ?');
                $chk->execute([$hostelId, $userId]);
                if (!$chk->fetch()) {
                    http_response_code(403);
                    echo 'Forbidden';
                    exit;
                }
            }
            $db->prepare('UPDATE hostels SET is_active = ?, managed_by = CASE WHEN ? = 1 THEN managed_by ELSE managed_by END WHERE id = ?')
                ->execute([$isActive, $isActive, $hostelId]);
            hms_audit_log($userId, 'hostel_toggled', 'hostel', $hostelId, 'is_active set to ' . $isActive);
            flash_set('success', 'Hostel updated.');
            redirect_to(hms_url('admin/hostels.php'));
        }
    }

    if ($action === 'assign_manager' && $role === 'university_admin') {
        $hostelId = (int)($_POST['hostel_id'] ?? 0);
        $managedBy = (int)($_POST['managed_by'] ?? 0);
        if ($hostelId <= 0) {
            flash_set('error', 'Invalid hostel.');
        } else {
            $scopeChk = $db->prepare('SELECT h.id FROM hostels h WHERE h.id = ? AND ' . $adminHostelScope . ' LIMIT 1');
            $scopeChk->execute([$hostelId, $userId]);
            if (!$scopeChk->fetch()) {
                flash_set('error', 'You cannot assign a manager for that hostel.');
            } else {
                $managedBy = $managedBy > 0 ? $managedBy : null;
                $db->prepare('UPDATE hostels SET managed_by = ? WHERE id = ?')->execute([$managedBy, $hostelId]);
                hms_audit_log($userId, 'hostel_manager_updated', 'hostel', $hostelId, 'Managed_by updated.');
                flash_set('success', 'Manager assigned.');
                redirect_to(hms_url('admin/hostels.php'));
            }
        }
    }

    if ($action === 'update_rent_period' && $role === 'university_admin') {
        $hostelId = (int)($_POST['hostel_id'] ?? 0);
        $rs = trim((string)($_POST['rent_period_start'] ?? ''));
        $re = trim((string)($_POST['rent_period_end'] ?? ''));
        if ($hostelId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rs) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $re)) {
            flash_set('error', 'Valid semester start and end dates are required (YYYY-MM-DD).');
        } elseif ($rs > $re) {
            flash_set('error', 'Semester start must be on or before semester end.');
        } else {
            $scopeChk = $db->prepare('SELECT h.id FROM hostels h WHERE h.id = ? AND ' . $adminHostelScope . ' LIMIT 1');
            $scopeChk->execute([$hostelId, $userId]);
            if (!$scopeChk->fetch()) {
                flash_set('error', 'You cannot edit rental dates for that hostel.');
            } else {
                $db->prepare('UPDATE hostels SET rent_period_start = ?, rent_period_end = ? WHERE id = ?')->execute([$rs, $re, $hostelId]);
                hms_audit_log($userId, 'hostel_rent_period_updated', 'hostel', $hostelId, 'Semester rental window set to ' . $rs . ' – ' . $re . '.');
                flash_set('success', 'Rental semester dates updated.');
                redirect_to(hms_url('admin/hostels.php'));
            }
        }
    }

    if ($action === 'update_hostel_map') {
        $hostelId = (int)($_POST['hostel_id'] ?? 0);
        $latP = trim((string)($_POST['map_latitude'] ?? ''));
        $lngP = trim((string)($_POST['map_longitude'] ?? ''));
        if ($hostelId <= 0) {
            flash_set('error', 'Invalid hostel.');
            redirect_to(hms_url('admin/hostels.php'));
        }
        $allowed = false;
        if ($role === 'university_admin') {
            $scopeChk = $db->prepare('SELECT h.id FROM hostels h WHERE h.id = ? AND ' . $adminHostelScope . ' LIMIT 1');
            $scopeChk->execute([$hostelId, $userId]);
            $allowed = (bool)$scopeChk->fetch();
        } elseif ($role === 'warden') {
            $wchk = $db->prepare('SELECT id FROM hostels WHERE id = ? AND managed_by = ?');
            $wchk->execute([$hostelId, $userId]);
            $allowed = (bool)$wchk->fetch();
        }
        if (!$allowed) {
            flash_set('error', 'You cannot update the map for that hostel.');
            redirect_to(hms_url('admin/hostels.php'));
        }
        if ($latP === '' || $lngP === '') {
            flash_set('error', 'Map location is required. Enter both latitude and longitude, or use the map to set a pin.');
            redirect_to(hms_url('admin/hostels.php'));
        }
        $mc = hms_parse_map_coords($latP, $lngP);
        if ($mc === null) {
            flash_set('error', 'Invalid map coordinates. Use decimal degrees (latitude −90…90, longitude −180…180).');
            redirect_to(hms_url('admin/hostels.php'));
        }
        $mapLat = $mc['lat'];
        $mapLng = $mc['lng'];
        $db->prepare('UPDATE hostels SET map_latitude = ?, map_longitude = ? WHERE id = ?')->execute([$mapLat, $mapLng, $hostelId]);
        hms_audit_log($userId, 'hostel_map_updated', 'hostel', $hostelId, 'Map coordinates updated.');
        flash_set('success', 'Hostel map location saved.');
        redirect_to(hms_url('admin/hostels.php'));
    }

    if ($action === 'update_nearby_institutions' && $role === 'university_admin') {
        if (!$hasNearbyInstitutions) {
            flash_set('error', 'Nearby institutions feature is pending database migration. Run migration 009 first.');
            redirect_to(hms_url('admin/hostels.php'));
        }
        $hostelId = (int)($_POST['hostel_id'] ?? 0);
        $nearbyInstitutions = trim((string)($_POST['nearby_institutions'] ?? ''));
        if ($hostelId <= 0) {
            flash_set('error', 'Invalid hostel.');
        } else {
            $scopeChk = $db->prepare('SELECT h.id FROM hostels h WHERE h.id = ? AND ' . $adminHostelScope . ' LIMIT 1');
            $scopeChk->execute([$hostelId, $userId]);
            if (!$scopeChk->fetch()) {
                flash_set('error', 'You cannot update nearby institutions for that hostel.');
            } else {
                $db->prepare('UPDATE hostels SET nearby_institutions = ? WHERE id = ?')
                    ->execute([$nearbyInstitutions !== '' ? $nearbyInstitutions : null, $hostelId]);
                hms_audit_log($userId, 'hostel_nearby_institutions_updated', 'hostel', $hostelId, 'Nearby institutions updated.');
                flash_set('success', 'Nearby institutions updated.');
                redirect_to(hms_url('admin/hostels.php'));
            }
        }
    }

    if ($action === 'upload_hostel_images' && $hasHostelGallery) {
        $hostelId = (int)($_POST['hostel_id'] ?? 0);
        if ($hostelId <= 0) {
            flash_set('error', 'Invalid hostel.');
            redirect_to(hms_url('admin/hostels.php'));
        }
        $allowed = false;
        if ($role === 'university_admin') {
            $scopeChk = $db->prepare('SELECT h.id FROM hostels h WHERE h.id = ? AND ' . $adminHostelScope . ' LIMIT 1');
            $scopeChk->execute([$hostelId, $userId]);
            $allowed = (bool)$scopeChk->fetch();
        } elseif ($role === 'warden') {
            $wchk = $db->prepare('SELECT id FROM hostels WHERE id = ? AND managed_by = ?');
            $wchk->execute([$hostelId, $userId]);
            $allowed = (bool)$wchk->fetch();
        }
        if (!$allowed) {
            flash_set('error', 'You cannot upload photos for that hostel.');
            redirect_to(hms_url('admin/hostels.php'));
        }
        $upload = hms_upload_images($_FILES['photos'] ?? [], 'hostels', false);
        if (!$upload['ok']) {
            flash_set('error', $upload['error'] ?? 'Photo upload failed.');
            redirect_to(hms_url('admin/hostels.php'));
        }
        hms_gallery_insert($db, 'hostel', $hostelId, $upload['paths']);
        hms_audit_log($userId, 'hostel_images_uploaded', 'hostel', $hostelId, count($upload['paths']) . ' photo(s) added.');
        flash_set('success', count($upload['paths']) . ' photo(s) added.');
        redirect_to(hms_url('admin/hostels.php'));
    }

    if ($action === 'delete_hostel_image' && $hasHostelGallery) {
        $hostelId = (int)($_POST['hostel_id'] ?? 0);
        $imageId = (int)($_POST['image_id'] ?? 0);
        if ($hostelId <= 0 || $imageId <= 0) {
            flash_set('error', 'Invalid hostel photo.');
            redirect_to(hms_url('admin/hostels.php'));
        }
        $allowed = false;
        if ($role === 'university_admin') {
            $scopeChk = $db->prepare('SELECT h.id FROM hostels h WHERE h.id = ? AND ' . $adminHostelScope . ' LIMIT 1');
            $scopeChk->execute([$hostelId, $userId]);
            $allowed = (bool)$scopeChk->fetch();
        } elseif ($role === 'warden') {
            $wchk = $db->prepare('SELECT id FROM hostels WHERE id = ? AND managed_by = ?');
            $wchk->execute([$hostelId, $userId]);
            $allowed = (bool)$wchk->fetch();
        }
        if (!$allowed) {
            flash_set('error', 'You cannot remove photos for that hostel.');
            redirect_to(hms_url('admin/hostels.php'));
        }
        $result = hms_gallery_delete_image($db, 'hostel', $imageId, $hostelId);
        if (!$result['ok']) {
            flash_set('error', $result['error'] ?? 'Unable to remove photo.');
            redirect_to(hms_url('admin/hostels.php'));
        }
        hms_audit_log($userId, 'hostel_image_deleted', 'hostel', $hostelId, 'Hostel photo removed.');
        flash_set('success', 'Photo removed.');
        redirect_to(hms_url('admin/hostels.php'));
    }
}

$wardens = $db->query('SELECT id, name, email FROM users WHERE role = "warden" ORDER BY name ASC')->fetchAll();
$institutionOptions = $db->query('SELECT DISTINCT TRIM(institution) AS institution FROM users WHERE institution IS NOT NULL AND TRIM(institution) <> "" ORDER BY institution ASC')->fetchAll();

if ($role === 'warden') {
    $hostels = $db->prepare('
        SELECT h.*,
            (SELECT COUNT(*) FROM rooms r WHERE r.hostel_id = h.id) AS room_count,
            (SELECT COALESCE(SUM(r.current_occupancy),0) FROM rooms r WHERE r.hostel_id = h.id) AS occupancy
        FROM hostels h
        WHERE h.managed_by = ?
        ORDER BY h.is_active DESC, h.name ASC
    ');
    $hostels->execute([$userId]);
    $hostels = $hostels->fetchAll();
} else {
    $hostels = $db->prepare('
        SELECT h.*,
            (SELECT COUNT(*) FROM rooms r WHERE r.hostel_id = h.id) AS room_count,
            (SELECT COALESCE(SUM(r.current_occupancy),0) FROM rooms r WHERE r.hostel_id = h.id) AS occupancy
        FROM hostels h
        WHERE ' . $adminHostelScope . '
        ORDER BY h.is_active DESC, h.name ASC
    ');
    $hostels->execute([$userId]);
    $hostels = $hostels->fetchAll();
}

$hostelGalleryById = $hasHostelGallery
    ? hms_gallery_fetch_bulk($db, 'hostel', array_map(static fn (array $h): int => (int)($h['id'] ?? 0), $hostels))
    : [];

layout_header('Manage Hostels');
?>

<div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h2 class="h4 mb-1">Hostels</h2>
        <div class="text-muted small">Summary list; open <strong>Details</strong> to edit semester dates, warden, map pin, or activation.</div>
        <?php if (!hms_google_maps_configured()): ?>
            <div class="alert alert-secondary py-2 small mt-2 mb-0">Interactive maps are off. Add <code>HMS_GOOGLE_MAPS_API_KEY</code> in <code>config.local.php</code> and enable <strong>Maps JavaScript API</strong> + <strong>Maps Embed API</strong> (restrict the key by HTTP referrer). You can still enter latitude/longitude manually.</div>
        <?php endif; ?>
    </div>
</div>

<?php if ($role === 'warden'): ?>
    <div class="alert alert-info py-2 small mb-4">
        You can only manage hostels allocated to your account. New hostels are created by university admins.
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <?php if (empty($hostels)): ?>
            <div class="alert alert-info mb-0">No hostels found for your account.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Location</th>
                            <?php if ($hasNearbyInstitutions): ?><th>Nearby institutions</th><?php endif; ?>
                            <th>Rooms</th>
                            <th>Occupied</th>
                            <th>Status</th>
                            <th>Rental semester</th>
                            <th class="text-end" style="min-width: 120px;">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                            <?php foreach ($hostels as $h): ?>
                            <?php
                                $hid = (int)$h['id'];
                                $isActive = (int)$h['is_active'];
                                $badge = $isActive === 1 ? 'bg-success' : 'bg-secondary';
                                $rpS = (string)($h['rent_period_start'] ?? '');
                                $rpE = (string)($h['rent_period_end'] ?? '');
                                $collapseId = 'hostel-detail-' . $hid;
                                $canEditHostelMap = ($role === 'university_admin') || ($role === 'warden' && (int)($h['managed_by'] ?? 0) === $userId);
                                $canEditHostelPhoto = $canEditHostelMap;
                                $hostelImages = $hostelGalleryById[$hid] ?? [];
                                $hasMapPin = hms_hostel_has_map_pin($h);
                                $mapLatVal = (string)($h['map_latitude'] ?? '');
                                $mapLngVal = (string)($h['map_longitude'] ?? '');
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo hms_url('hostels_view.php?hostel_id=' . $hid); ?>">
                                        <?php echo e($h['name']); ?>
                                    </a>
                                </td>
                                <td class="text-muted small"><?php echo e($h['location']); ?></td>
                                <?php if ($hasNearbyInstitutions): ?>
                                    <td class="text-muted small"><?php echo e((string)($h['nearby_institutions'] ?? '')); ?></td>
                                <?php endif; ?>
                                <td><?php echo e((string)($h['room_count'] ?? 0)); ?></td>
                                <td><?php echo e((string)($h['occupancy'] ?? 0)); ?></td>
                                <td><span class="badge <?php echo $badge; ?>"><?php echo $isActive === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                <td class="small text-muted">
                                    <?php if ($rpS !== '' && $rpE !== ''): ?>
                                        <?php echo e($rpS); ?> → <?php echo e($rpE); ?>
                                    <?php else: ?>
                                        <span class="text-warning">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo e($collapseId); ?>" aria-expanded="false" aria-controls="<?php echo e($collapseId); ?>">
                                        Details
                                    </button>
                                </td>
                            </tr>
                            <tr class="border-0">
                                <td colspan="<?php echo $hasNearbyInstitutions ? '8' : '7'; ?>" class="p-0 border-0">
                                    <div class="collapse" id="<?php echo e($collapseId); ?>">
                                        <div class="px-3 py-4 bg-light border-bottom rounded-bottom-3">
                                            <div class="row g-4">
                                                <div class="col-lg-6">
                                                    <h3 class="h6 mb-3">Quick links</h3>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <a class="btn btn-sm btn-outline-primary" href="<?php echo hms_url('admin/rooms.php?hostel_id=' . $hid); ?>">Manage rooms</a>
                                                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo hms_url('admin/students_by_room.php?hostel_id=' . $hid); ?>">Students by room</a>
                                                    </div>
                                                </div>
                                                <div class="col-lg-6">
                                                    <?php
                                                        $wardenMayToggle = ($role === 'warden' && $isActive === 0);
                                                        $adminMayToggle = ($role === 'university_admin');
                                                        $showHostelActiveToggle = $wardenMayToggle || $adminMayToggle;
                                                    ?>
                                                    <?php if ($showHostelActiveToggle): ?>
                                                        <h3 class="h6 mb-3">Activation</h3>
                                                        <form method="post" action="" class="d-inline"<?php echo hms_data_confirm($isActive === 1 ? 'Deactivate this hostel? It will be hidden from students until reactivated.' : 'Activate this hostel? It will become visible to students again.'); ?>>
                                                            <input type="hidden" name="action" value="toggle_active">
                                                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                                            <input type="hidden" name="hostel_id" value="<?php echo $hid; ?>">
                                                            <input type="hidden" name="is_active" value="<?php echo $isActive === 1 ? 0 : 1; ?>">
                                                            <button class="btn btn-sm <?php echo $isActive === 1 ? 'btn-outline-secondary' : 'btn-outline-success'; ?>" type="submit">
                                                                <?php echo $isActive === 1 ? 'Deactivate hostel' : 'Activate hostel'; ?>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <p class="text-muted small mb-0">Activation changes are limited by your role for this hostel.</p>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if ($role === 'university_admin'): ?>
                                                    <div class="col-12">
                                                        <hr class="my-0 opacity-25">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <h3 class="h6 mb-3">Rental semester</h3>
                                                        <form method="post" class="vstack gap-2" style="max-width: 22rem;"<?php echo hms_data_confirm('Save rental semester dates for this hostel? New student bookings will use exactly this start and end date.'); ?>>
                                                            <input type="hidden" name="action" value="update_rent_period">
                                                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                                            <input type="hidden" name="hostel_id" value="<?php echo $hid; ?>">
                                                            <div>
                                                                <label class="form-label small text-muted mb-0">Start</label>
                                                                <input type="date" name="rent_period_start" class="form-control form-control-sm" value="<?php echo e($rpS); ?>" required>
                                                            </div>
                                                            <div>
                                                                <label class="form-label small text-muted mb-0">End</label>
                                                                <input type="date" name="rent_period_end" class="form-control form-control-sm" value="<?php echo e($rpE); ?>" required>
                                                            </div>
                                                            <button class="btn btn-sm btn-primary align-self-start" type="submit">Save dates</button>
                                                        </form>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <h3 class="h6 mb-3">Assign warden</h3>
                                                        <form method="post" action="" class="d-flex flex-wrap align-items-end gap-2"<?php echo hms_data_confirm('Save the warden assignment for this hostel?'); ?>>
                                                            <input type="hidden" name="action" value="assign_manager">
                                                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                                            <input type="hidden" name="hostel_id" value="<?php echo $hid; ?>">
                                                            <div class="flex-grow-1" style="min-width: 200px;">
                                                                <label class="form-label small text-muted mb-0">Managed by</label>
                                                                <select name="managed_by" class="form-select form-select-sm">
                                                                    <option value="0">Unassigned</option>
                                                                    <?php foreach ($wardens as $w): ?>
                                                                        <option value="<?php echo (int)$w['id']; ?>" <?php echo ((int)($h['managed_by'] ?? 0) === (int)$w['id']) ? 'selected' : ''; ?>>
                                                                            <?php echo e($w['name']); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <button class="btn btn-sm btn-primary" type="submit">Save</button>
                                                        </form>
                                                    </div>
                                                    <?php if ($hasNearbyInstitutions): ?>
                                                        <div class="col-12">
                                                            <h3 class="h6 mb-3">Nearby institutions for student search</h3>
                                                            <form method="post" class="vstack gap-2" style="max-width: 42rem;"<?php echo hms_data_confirm('Save nearby institutions for this hostel? Students can search by these names.'); ?>>
                                                                <input type="hidden" name="action" value="update_nearby_institutions">
                                                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                                                <input type="hidden" name="hostel_id" value="<?php echo $hid; ?>">
                                                                <input type="text" name="nearby_institutions" class="form-control form-control-sm" list="institution-options" value="<?php echo e((string)($h['nearby_institutions'] ?? '')); ?>" placeholder="e.g. Makerere University, Kyambogo University">
                                                                <div class="form-text">Use comma-separated names. Students can search hostels by these nearby institutions.</div>
                                                                <button class="btn btn-sm btn-primary align-self-start" type="submit">Save nearby institutions</button>
                                                            </form>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <div class="col-12 col-md-6">
                                                        <h3 class="h6 mb-2">Rental semester</h3>
                                                        <?php if ($rpS !== '' && $rpE !== ''): ?>
                                                            <p class="small mb-0"><span class="fw-semibold text-dark"><?php echo e($rpS); ?></span> <span class="text-muted">to</span> <span class="fw-semibold text-dark"><?php echo e($rpE); ?></span></p>
                                                        <?php else: ?>
                                                            <p class="text-muted small mb-0">Not set yet by a university admin.</p>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($hasHostelGallery && $canEditHostelPhoto): ?>
                                                    <div class="col-12">
                                                        <hr class="my-0 opacity-25">
                                                        <h3 class="h6 mt-3 mb-2">Photos <span class="text-danger">*</span></h3>
                                                        <p class="text-muted small mb-2">At least one photo is required. Students see all photos when browsing. JPEG, PNG, or WebP, up to 5 MB each.</p>
                                                        <?php if (!empty($hostelImages)): ?>
                                                            <div class="d-flex flex-wrap gap-2 mb-3">
                                                                <?php foreach ($hostelImages as $img): ?>
                                                                    <?php $imgUrl = hms_upload_url((string)($img['image_path'] ?? '')); ?>
                                                                    <?php if ($imgUrl !== null): ?>
                                                                        <div class="position-relative">
                                                                            <img src="<?php echo e($imgUrl); ?>" alt="Photo of <?php echo e($h['name']); ?>" class="rounded border" style="width: 120px; height: 90px; object-fit: cover;">
                                                                            <?php if (count($hostelImages) > 1): ?>
                                                                                <form method="post" action="" class="position-absolute top-0 end-0 m-1"<?php echo hms_data_confirm('Remove this photo?'); ?>>
                                                                                    <input type="hidden" name="action" value="delete_hostel_image">
                                                                                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                                                                    <input type="hidden" name="hostel_id" value="<?php echo $hid; ?>">
                                                                                    <input type="hidden" name="image_id" value="<?php echo (int)($img['id'] ?? 0); ?>">
                                                                                    <button class="btn btn-sm btn-danger py-0 px-1" type="submit" title="Remove photo">&times;</button>
                                                                                </form>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="alert alert-warning py-2 small">No photos yet. Upload at least one photo below.</div>
                                                        <?php endif; ?>
                                                        <form method="post" action="" enctype="multipart/form-data" class="d-flex flex-wrap align-items-end gap-2 mb-2">
                                                            <input type="hidden" name="action" value="upload_hostel_images">
                                                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                                            <input type="hidden" name="hostel_id" value="<?php echo $hid; ?>">
                                                            <input type="file" name="photos[]" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" multiple required style="max-width: 22rem;">
                                                            <button class="btn btn-sm btn-primary" type="submit">Add photos</button>
                                                        </form>
                                                    </div>
                                                <?php elseif ($hasHostelGallery && !empty($hostelImages)): ?>
                                                    <div class="col-12">
                                                        <hr class="my-0 opacity-25">
                                                        <h3 class="h6 mt-3 mb-2">Photos</h3>
                                                        <?php echo hms_render_image_gallery($hostelImages, 'hostel-admin-' . $hid, $h['name'], 220, 'img-fluid rounded border'); ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($canEditHostelMap): ?>
                                                    <div class="col-12">
                                                        <hr class="my-0 opacity-25">
                                                        <h3 class="h6 mt-3 mb-2">Map location <span class="text-danger">*</span></h3>
                                                        <p class="text-muted small mb-2">Required for students to see the hostel on a map. Click the map to place or drag the marker, use <strong>Use my current location</strong> on the map, then save.</p>
                                                        <form method="post" action="" class="vstack gap-2" style="max-width: 42rem;">
                                                            <input type="hidden" name="action" value="update_hostel_map">
                                                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                                            <input type="hidden" name="hostel_id" value="<?php echo $hid; ?>">
                                                            <?php if (hms_google_maps_configured()): ?>
                                                                <input type="hidden" name="map_latitude" id="hostel_map_lat_<?php echo $hid; ?>" value="<?php echo $hasMapPin ? e($mapLatVal) : ''; ?>">
                                                                <input type="hidden" name="map_longitude" id="hostel_map_lng_<?php echo $hid; ?>" value="<?php echo $hasMapPin ? e($mapLngVal) : ''; ?>">
                                                                <div class="w-100 rounded border bg-white hostel-admin-map" style="min-height: 260px;" data-hms-map-picker="1" data-lat-target="hostel_map_lat_<?php echo $hid; ?>" data-lng-target="hostel_map_lng_<?php echo $hid; ?>"></div>
                                                            <?php else: ?>
                                                                <div class="row g-2">
                                                                    <div class="col-md-5">
                                                                        <label class="form-label small text-muted mb-0">Latitude <span class="text-danger">*</span></label>
                                                                        <input type="text" name="map_latitude" class="form-control form-control-sm" value="<?php echo $hasMapPin ? e($mapLatVal) : ''; ?>" placeholder="e.g. 0.3476" required>
                                                                    </div>
                                                                    <div class="col-md-5">
                                                                        <label class="form-label small text-muted mb-0">Longitude <span class="text-danger">*</span></label>
                                                                        <input type="text" name="map_longitude" class="form-control form-control-sm" value="<?php echo $hasMapPin ? e($mapLngVal) : ''; ?>" placeholder="e.g. 32.5825" required>
                                                                    </div>
                                                                </div>
                                                                <p class="text-muted small mb-0">Set <code>HMS_GOOGLE_MAPS_API_KEY</code> in <code>config.local.php</code> for an interactive map.</p>
                                                            <?php endif; ?>
                                                            <button class="btn btn-sm btn-primary align-self-start" type="submit">Save map pin</button>
                                                        </form>
                                                        <?php
                                                            $embedPreview = ($hasMapPin && hms_google_maps_configured()) ? hms_google_maps_embed_url((float)$mapLatVal, (float)$mapLngVal) : '';
                                                        ?>
                                                        <?php if ($embedPreview !== ''): ?>
                                                            <div class="mt-2 small text-muted">Preview (same as students):</div>
                                                            <iframe class="rounded border w-100 mt-1" style="min-height: 200px; border: 0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Map preview" src="<?php echo e($embedPreview); ?>"></iframe>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($role === 'university_admin'): ?>
<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-4">
        <h2 class="h4 mb-3">Create hostel</h2>
        <form method="post" action="" enctype="multipart/form-data"<?php echo hms_data_confirm('Create this hostel with the details you entered?'); ?>>
            <input type="hidden" name="action" value="create_hostel">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Hostel name</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Location</label>
                    <input type="text" name="location" class="form-control" required placeholder="e.g. Kampala (near campus)">
                </div>
                <?php if ($hasNearbyInstitutions): ?>
                    <div class="col-12">
                        <label class="form-label">Nearby institutions/universities</label>
                        <input type="text" name="nearby_institutions" class="form-control" list="institution-options" placeholder="e.g. Makerere University, Kyambogo University">
                        <div class="form-text">Comma-separated institutions near this hostel. Students can search by these names.</div>
                    </div>
                <?php endif; ?>
                <div class="col-12">
                    <label class="form-label">Description (optional)</label>
                    <textarea name="description" class="form-control" rows="3"></textarea>
                </div>
                <?php if ($hasHostelGallery): ?>
                <div class="col-12">
                    <label class="form-label">Photos <span class="text-danger">*</span></label>
                    <input type="file" name="photos[]" class="form-control" accept="image/jpeg,image/png,image/webp" multiple required>
                    <div class="form-text">Upload one or more photos. Students will see all of them when browsing hostels. JPEG, PNG, or WebP, up to 5 MB each.</div>
                </div>
                <?php endif; ?>
                <div class="col-12">
                    <label class="form-label">Map location <span class="text-danger">*</span></label>
                    <p class="text-muted small mb-2"><?php echo hms_google_maps_configured() ? 'Click the map once to drop a pin (you can drag it), or use <strong>Use my current location</strong> on the map. Coordinates are required before you can create the hostel.' : 'Enter latitude and longitude in decimal degrees (required).'; ?></p>
                    <?php if (hms_google_maps_configured()): ?>
                        <input type="hidden" name="map_latitude" id="create_map_latitude" value="">
                        <input type="hidden" name="map_longitude" id="create_map_longitude" value="">
                        <div class="w-100 rounded border bg-light hostel-admin-map" style="min-height: 280px;" data-hms-map-picker="1" data-lat-target="create_map_latitude" data-lng-target="create_map_longitude"></div>
                    <?php else: ?>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small text-muted mb-0">Latitude</label>
                                <input type="text" name="map_latitude" class="form-control" placeholder="e.g. 0.3476" required inputmode="decimal">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted mb-0">Longitude</label>
                                <input type="text" name="map_longitude" class="form-control" placeholder="e.g. 32.5825" required inputmode="decimal">
                            </div>
                        </div>
                        <p class="text-muted small mb-0">Add <code>HMS_GOOGLE_MAPS_API_KEY</code> in <code>config.local.php</code> (Maps JavaScript + Embed APIs) for an interactive map.</p>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Assign warden</label>
                    <select name="managed_by" class="form-select">
                        <option value="0">Unassigned</option>
                        <?php foreach ($wardens as $w): ?>
                            <option value="<?php echo (int)$w['id']; ?>"><?php echo e($w['name']); ?> (<?php echo e($w['email']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Create hostel</button>
                </div>
            </div>
        </form>
        <p class="text-muted small mt-3 mb-0">
            Map coordinates are <strong>required</strong> when creating a hostel. After creating, open <strong>Details</strong> above to set <strong>rental semester dates</strong> (each booking uses exactly that range).
        </p>
    </div>
</div>
<?php endif; ?>

<?php if ($hasNearbyInstitutions): ?>
    <datalist id="institution-options">
        <?php foreach ($institutionOptions as $inst): ?>
            <?php $opt = trim((string)($inst['institution'] ?? '')); ?>
            <?php if ($opt !== ''): ?>
                <option value="<?php echo e($opt); ?>"></option>
            <?php endif; ?>
        <?php endforeach; ?>
    </datalist>
<?php endif; ?>

<?php if (hms_google_maps_configured()): ?>
    <?php $hmsHostelMapJsV = (string)(@filemtime(__DIR__ . '/../hostel_map_admin.js') ?: time()); ?>
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo rawurlencode(hms_google_maps_api_key()); ?>&amp;callback=hmsHostelMapPickersBoot"></script>
    <script src="<?php echo hms_url('hostel_map_admin.js?v=' . $hmsHostelMapJsV); ?>"></script>
<?php endif; ?>

<?php layout_footer(); ?>

