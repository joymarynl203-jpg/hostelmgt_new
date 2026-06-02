<?php
require_once __DIR__ . '/../../lib/layout.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/uploads.php';

require_login();
require_role(['warden', 'university_admin']);

$db = hms_db();
$user = hms_current_user();
$userId = (int)$user['id'];
$role = $user['role'];

$hasRoomGallery = hms_image_gallery_enabled($db);

$adminHostelScope = 'EXISTS (
    SELECT 1
    FROM audit_logs al
    WHERE al.entity_type = "hostel"
      AND al.action = "hostel_created"
      AND al.entity_id = h.id
      AND al.actor_user_id = ?
)';

$selectedHostelId = (int)($_GET['hostel_id'] ?? 0);
$showAddRoom = (int)($_GET['show_add'] ?? 0) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_room') {
        $hostelId = (int)($_POST['hostel_id'] ?? 0);
        $roomNumber = trim((string)($_POST['room_number'] ?? ''));
        $capacity = (int)($_POST['capacity'] ?? 0);
        $gender = (string)($_POST['gender'] ?? 'mixed');
        $semesterFee = (float)($_POST['monthly_fee'] ?? 0);

        $allowedGenders = ['male', 'female', 'mixed'];
        if ($hostelId <= 0 || $roomNumber === '' || $capacity < 1 || !in_array($gender, $allowedGenders, true) || $semesterFee <= 0) {
            flash_set('error', 'Provide valid hostel, room number, capacity, gender, and semester fee.');
        } else {
            if ($role === 'warden') {
                $chk = $db->prepare('SELECT id FROM hostels WHERE id = ? AND managed_by = ?');
                $chk->execute([$hostelId, $userId]);
                if (!$chk->fetch()) {
                    http_response_code(403);
                    echo 'Forbidden';
                    exit;
                }
            } elseif ($role === 'university_admin') {
                $scopeChk = $db->prepare('SELECT h.id FROM hostels h WHERE h.id = ? AND ' . $adminHostelScope . ' LIMIT 1');
                $scopeChk->execute([$hostelId, $userId]);
                if (!$scopeChk->fetch()) {
                    flash_set('error', 'You cannot add rooms to that hostel.');
                    redirect_to(hms_url('admin/rooms.php'));
                }
            }

            $photoUpload = hms_upload_images($_FILES['photos'] ?? [], 'rooms', false);
            if (!$hasRoomGallery) {
                flash_set('error', 'Photo gallery tables are missing. Run database migration 011 first.');
                redirect_to(hms_url('admin/rooms.php?hostel_id=' . $hostelId));
            }
            if (!$photoUpload['ok']) {
                flash_set('error', $photoUpload['error'] ?? 'At least one room photo is required.');
                redirect_to(hms_url('admin/rooms.php?hostel_id=' . $hostelId . '&show_add=1'));
            }

            try {
                $db->beginTransaction();
                $stmt = $db->prepare('
                    INSERT INTO rooms (hostel_id, room_number, capacity, current_occupancy, gender, monthly_fee)
                    VALUES (?, ?, ?, 0, ?, ?)
                ');
                if (!$stmt->execute([$hostelId, $roomNumber, $capacity, $gender, $semesterFee])) {
                    throw new RuntimeException('insert_failed');
                }
                $roomId = (int)$db->lastInsertId();
                hms_gallery_insert($db, 'room', $roomId, $photoUpload['paths']);
                $db->commit();
                hms_audit_log($userId, 'room_created', 'room', $roomId, 'Room created: ' . $roomNumber . ' with ' . count($photoUpload['paths']) . ' photo(s).');
                flash_set('success', 'Room created.');
                redirect_to(hms_url('admin/rooms.php?hostel_id=' . $hostelId));
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                hms_upload_delete_many($photoUpload['paths']);
                flash_set('error', 'Unable to create room. Ensure room number is unique per hostel.');
                redirect_to(hms_url('admin/rooms.php?hostel_id=' . $hostelId . '&show_add=1'));
            }
        }
    }

    if ($action === 'update_room') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $capacity = (int)($_POST['capacity'] ?? 0);
        $gender = (string)($_POST['gender'] ?? 'mixed');
        $semesterFee = (float)($_POST['monthly_fee'] ?? 0);

        if ($roomId <= 0 || $capacity < 1 || !in_array($gender, ['male', 'female', 'mixed'], true) || $semesterFee <= 0) {
            flash_set('error', 'Invalid room update values.');
        } else {
            if ($role === 'warden') {
                $chk = $db->prepare('
                    SELECT r.id
                    FROM rooms r
                    JOIN hostels h ON h.id = r.hostel_id
                    WHERE r.id = ? AND h.managed_by = ?
                ');
                $chk->execute([$roomId, $userId]);
                if (!$chk->fetch()) {
                    http_response_code(403);
                    echo 'Forbidden';
                    exit;
                }
            } elseif ($role === 'university_admin') {
                $scopeChk = $db->prepare('
                    SELECT r.id
                    FROM rooms r
                    JOIN hostels h ON h.id = r.hostel_id
                    WHERE r.id = ? AND ' . $adminHostelScope . '
                    LIMIT 1
                ');
                $scopeChk->execute([$roomId, $userId]);
                if (!$scopeChk->fetch()) {
                    flash_set('error', 'You cannot update that room.');
                    redirect_to(hms_url('admin/rooms.php'));
                }
            }

            $stmt = $db->prepare('UPDATE rooms SET capacity = ?, gender = ?, monthly_fee = ? WHERE id = ?');
            $stmt->execute([$capacity, $gender, $semesterFee, $roomId]);
            hms_audit_log($userId, 'room_updated', 'room', $roomId, 'Room updated (capacity/gender/fee).');
            flash_set('success', 'Room updated.');
            $hostelIdStmt = $db->prepare('SELECT hostel_id FROM rooms WHERE id = ?');
            $hostelIdStmt->execute([$roomId]);
            $hostelId = (int)($hostelIdStmt->fetch()['hostel_id'] ?? 0);
            redirect_to(hms_url('admin/rooms.php?hostel_id=' . $hostelId));
        }
    }

    if ($action === 'upload_room_images' && $hasRoomGallery) {
        $roomId = (int)($_POST['room_id'] ?? 0);
        if ($roomId <= 0) {
            flash_set('error', 'Invalid room.');
            redirect_to(hms_url('admin/rooms.php'));
        }
        if ($role === 'warden') {
            $chk = $db->prepare('
                SELECT r.id, r.hostel_id
                FROM rooms r
                JOIN hostels h ON h.id = r.hostel_id
                WHERE r.id = ? AND h.managed_by = ?
            ');
            $chk->execute([$roomId, $userId]);
            $row = $chk->fetch();
            if (!$row) {
                http_response_code(403);
                echo 'Forbidden';
                exit;
            }
        } elseif ($role === 'university_admin') {
            $scopeChk = $db->prepare('
                SELECT r.id, r.hostel_id
                FROM rooms r
                JOIN hostels h ON h.id = r.hostel_id
                WHERE r.id = ? AND ' . $adminHostelScope . '
                LIMIT 1
            ');
            $scopeChk->execute([$roomId, $userId]);
            $row = $scopeChk->fetch();
            if (!$row) {
                flash_set('error', 'You cannot upload photos for that room.');
                redirect_to(hms_url('admin/rooms.php'));
            }
        } else {
            $row = null;
        }
        $hostelId = (int)($row['hostel_id'] ?? 0);
        $upload = hms_upload_images($_FILES['photos'] ?? [], 'rooms', false);
        if (!$upload['ok']) {
            flash_set('error', $upload['error'] ?? 'Photo upload failed.');
            redirect_to(hms_url('admin/rooms.php?hostel_id=' . $hostelId));
        }
        hms_gallery_insert($db, 'room', $roomId, $upload['paths']);
        hms_audit_log($userId, 'room_images_uploaded', 'room', $roomId, count($upload['paths']) . ' photo(s) added.');
        flash_set('success', count($upload['paths']) . ' photo(s) added.');
        redirect_to(hms_url('admin/rooms.php?hostel_id=' . $hostelId));
    }

    if ($action === 'delete_room_image' && $hasRoomGallery) {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $imageId = (int)($_POST['image_id'] ?? 0);
        if ($roomId <= 0 || $imageId <= 0) {
            flash_set('error', 'Invalid room photo.');
            redirect_to(hms_url('admin/rooms.php'));
        }
        if ($role === 'warden') {
            $chk = $db->prepare('
                SELECT r.id, r.hostel_id
                FROM rooms r
                JOIN hostels h ON h.id = r.hostel_id
                WHERE r.id = ? AND h.managed_by = ?
            ');
            $chk->execute([$roomId, $userId]);
            $row = $chk->fetch();
            if (!$row) {
                http_response_code(403);
                echo 'Forbidden';
                exit;
            }
        } elseif ($role === 'university_admin') {
            $scopeChk = $db->prepare('
                SELECT r.id, r.hostel_id
                FROM rooms r
                JOIN hostels h ON h.id = r.hostel_id
                WHERE r.id = ? AND ' . $adminHostelScope . '
                LIMIT 1
            ');
            $scopeChk->execute([$roomId, $userId]);
            $row = $scopeChk->fetch();
            if (!$row) {
                flash_set('error', 'You cannot remove the photo for that room.');
                redirect_to(hms_url('admin/rooms.php'));
            }
        } else {
            $row = null;
        }
        $hostelId = (int)($row['hostel_id'] ?? 0);
        $result = hms_gallery_delete_image($db, 'room', $imageId, $roomId);
        if (!$result['ok']) {
            flash_set('error', $result['error'] ?? 'Unable to remove photo.');
            redirect_to(hms_url('admin/rooms.php?hostel_id=' . $hostelId));
        }
        hms_audit_log($userId, 'room_image_deleted', 'room', $roomId, 'Room photo removed.');
        flash_set('success', 'Photo removed.');
        redirect_to(hms_url('admin/rooms.php?hostel_id=' . $hostelId));
    }
}

// Hostels selection (admins: same scope as Manage Hostels; wardens: assigned hostels only)
if ($role === 'warden') {
    $hostelsStmt = $db->prepare('SELECT id, name FROM hostels WHERE managed_by = ? ORDER BY name ASC');
    $hostelsStmt->execute([$userId]);
    $hostels = $hostelsStmt->fetchAll();
} else {
    $hostelsStmt = $db->prepare('SELECT h.id, h.name FROM hostels h WHERE ' . $adminHostelScope . ' ORDER BY h.name ASC');
    $hostelsStmt->execute([$userId]);
    $hostels = $hostelsStmt->fetchAll();
}

$allowedHostelIds = array_map(static function (array $h): int {
    return (int)$h['id'];
}, $hostels);

if ($selectedHostelId > 0 && !in_array($selectedHostelId, $allowedHostelIds, true)) {
    flash_set('error', 'That hostel is not available.');
    $selectedHostelId = 0;
}

if ($role === 'warden' && $selectedHostelId <= 0 && !empty($hostels)) {
    $selectedHostelId = (int)$hostels[0]['id'];
}

if ($role === 'university_admin' && $showAddRoom && $selectedHostelId <= 0) {
    $showAddRoom = false;
}

$selectedHostelName = '';
if ($selectedHostelId > 0) {
    foreach ($hostels as $h) {
        if ((int)$h['id'] === $selectedHostelId) {
            $selectedHostelName = (string)$h['name'];
            break;
        }
    }
}

$rooms = [];
if ($selectedHostelId > 0) {
    $roomsStmt = $db->prepare('
        SELECT *
        FROM rooms
        WHERE hostel_id = ?
        ORDER BY room_number ASC
    ');
    $roomsStmt->execute([$selectedHostelId]);
    $rooms = $roomsStmt->fetchAll();
}

$roomGalleryById = $hasRoomGallery
    ? hms_gallery_fetch_bulk($db, 'room', array_map(static fn (array $r): int => (int)($r['id'] ?? 0), $rooms))
    : [];

layout_header('Manage Rooms');
?>

<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h2 class="h4 mb-1">Rooms</h2>
        <div class="text-muted small"><?php echo $role === 'university_admin' ? 'Choose a hostel, then create or edit rooms for that hostel.' : 'Create rooms and manage their capacity and fees.'; ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="<?php echo hms_url('admin/hostels.php'); ?>">Back to Hostels</a>
        <?php if ($selectedHostelId > 0): ?>
            <a class="btn btn-outline-secondary" href="<?php echo hms_url('admin/students_by_room.php?hostel_id=' . $selectedHostelId); ?>">Students in this hostel</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <?php if ($role === 'university_admin'): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <label class="form-label fw-semibold mb-2" for="hms_admin_rooms_hostel">Hostel</label>
                <select id="hms_admin_rooms_hostel" class="form-select" style="max-width: 28rem;" onchange="var v=this.value;window.location.href=v?'<?php echo e(hms_url('admin/rooms.php?hostel_id=')); ?>'+v:'<?php echo e(hms_url('admin/rooms.php')); ?>';">
                    <option value=""><?php echo empty($hostels) ? 'No hostels available' : '— Select a hostel —'; ?></option>
                    <?php foreach ($hostels as $h): ?>
                        <option value="<?php echo (int)$h['id']; ?>" <?php echo ((int)$h['id'] === $selectedHostelId) ? 'selected' : ''; ?>>
                            <?php echo e($h['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-muted small mb-0 mt-2">Rooms load after you choose a hostel.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h2 class="h4 mb-3">Existing Rooms<?php echo ($selectedHostelId > 0 && $selectedHostelName !== '') ? ' — ' . e($selectedHostelName) : ''; ?></h2>
                <?php if ($role === 'university_admin' && $selectedHostelId <= 0): ?>
                    <div class="alert alert-secondary mb-0">Select a hostel above to view and manage its rooms.</div>
                <?php elseif (empty($rooms)): ?>
                    <div class="alert alert-info">No rooms found. Add one using the form.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Room</th>
                                    <?php if ($hasRoomGallery): ?><th>Photos</th><?php endif; ?>
                                    <th>Gender</th>
                                    <th>Capacity</th>
                                    <th>Occupied</th>
                                    <th>Semester Fee</th>
                                    <th style="min-width: 360px;">Update</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rooms as $r): ?>
                                    <?php
                                        $rid = (int)$r['id'];
                                        $roomImages = $roomGalleryById[$rid] ?? [];
                                    ?>
                                    <tr>
                                        <td><?php echo e($r['room_number']); ?></td>
                                        <?php if ($hasRoomGallery): ?>
                                            <td style="min-width: 180px;">
                                                <?php if (!empty($roomImages)): ?>
                                                    <div class="d-flex flex-wrap gap-1 mb-1">
                                                        <?php foreach ($roomImages as $img): ?>
                                                            <?php $imgUrl = hms_upload_url((string)($img['image_path'] ?? '')); ?>
                                                            <?php if ($imgUrl !== null): ?>
                                                                <div class="position-relative">
                                                                    <img src="<?php echo e($imgUrl); ?>" alt="Room <?php echo e($r['room_number']); ?>" class="rounded border" style="width: 56px; height: 42px; object-fit: cover;">
                                                                    <?php if (count($roomImages) > 1): ?>
                                                                        <form method="post" action="" class="d-inline"<?php echo hms_data_confirm('Remove this photo?'); ?>>
                                                                            <input type="hidden" name="action" value="delete_room_image">
                                                                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                                                            <input type="hidden" name="room_id" value="<?php echo $rid; ?>">
                                                                            <input type="hidden" name="image_id" value="<?php echo (int)($img['id'] ?? 0); ?>">
                                                                            <button class="btn btn-link btn-sm text-danger p-0 ms-1" type="submit" title="Remove">&times;</button>
                                                                        </form>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-danger small">Required — add photos</span>
                                                <?php endif; ?>
                                                <form method="post" action="" enctype="multipart/form-data" class="d-flex flex-wrap gap-1 align-items-center">
                                                    <input type="hidden" name="action" value="upload_room_images">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                                    <input type="hidden" name="room_id" value="<?php echo $rid; ?>">
                                                    <input type="file" name="photos[]" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" multiple required style="max-width: 150px;">
                                                    <button class="btn btn-sm btn-outline-primary" type="submit">Add</button>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                        <td class="text-muted small"><?php echo e($r['gender']); ?></td>
                                        <td><?php echo e((string)$r['capacity']); ?></td>
                                        <td><?php echo e((string)$r['current_occupancy']); ?></td>
                                        <td><?php echo e((string)$r['monthly_fee']); ?> UGX</td>
                                        <td>
                                            <form method="post" action="" class="d-flex flex-wrap gap-2 align-items-end"<?php echo hms_data_confirm('Save changes to this room?'); ?>>
                                                <input type="hidden" name="action" value="update_room">
                                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                                <input type="hidden" name="room_id" value="<?php echo $rid; ?>">

                                                <div class="input-group input-group-sm" style="min-width: 120px;">
                                                    <span class="input-group-text">Cap</span>
                                                    <input type="number" name="capacity" min="1" class="form-control" value="<?php echo (int)$r['capacity']; ?>" required>
                                                </div>

                                                <select name="gender" class="form-select form-select-sm" style="min-width: 130px;" required>
                                                    <option value="mixed" <?php echo $r['gender'] === 'mixed' ? 'selected' : ''; ?>>Mixed</option>
                                                    <option value="male" <?php echo $r['gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
                                                    <option value="female" <?php echo $r['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                                                </select>

                                                <div class="input-group input-group-sm" style="min-width: 170px;">
                                                    <span class="input-group-text">Fee</span>
                                                    <input type="number" name="monthly_fee" min="1" class="form-control" value="<?php echo (float)$r['monthly_fee']; ?>" required>
                                                </div>

                                                <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($selectedHostelId > 0): ?>
                <div class="mt-3">
                    <a class="btn btn-primary" href="<?php echo hms_url('admin/rooms.php?hostel_id=' . $selectedHostelId . '&show_add=1'); ?>">
                        Add Room
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($showAddRoom): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h2 class="h4 mb-0">Add Room</h2>
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo hms_url('admin/rooms.php?hostel_id=' . $selectedHostelId); ?>">
                        Close
                    </a>
                </div>
                <form method="post" action="" enctype="multipart/form-data"<?php echo hms_data_confirm('Create this room with the details you entered?'); ?>>
                    <input type="hidden" name="action" value="create_room">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

                    <div class="mb-3">
                        <label class="form-label">Hostel</label>
                        <select name="hostel_id" class="form-select" required onchange="window.location=this.value ? '<?php echo hms_url('admin/rooms.php?hostel_id='); ?>'+this.value+'&show_add=1' : '';">
                            <?php foreach ($hostels as $h): ?>
                                <option value="<?php echo (int)$h['id']; ?>" <?php echo ((int)$h['id'] === $selectedHostelId) ? 'selected' : ''; ?>>
                                    <?php echo e($h['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Room Number</label>
                        <input type="text" name="room_number" class="form-control" required placeholder="e.g. 101, A-1, Room 3">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Capacity</label>
                        <input type="number" name="capacity" min="1" class="form-control" required value="1">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="mixed" selected>Mixed</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Semester Fee (UGX)</label>
                        <input type="number" name="monthly_fee" min="1" class="form-control" required value="500000">
                        <div class="form-text">A semester is 4 months.</div>
                    </div>

                    <?php if ($hasRoomGallery): ?>
                    <div class="mb-3">
                        <label class="form-label">Photos <span class="text-danger">*</span></label>
                        <input type="file" name="photos[]" class="form-control" accept="image/jpeg,image/png,image/webp" multiple required>
                        <div class="form-text">Upload one or more photos (required). Students see all photos when choosing a room. JPEG, PNG, or WebP, up to 5 MB each.</div>
                    </div>
                    <?php endif; ?>

                    <button class="btn btn-primary w-100" type="submit">Create Room</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php layout_footer(); ?>

