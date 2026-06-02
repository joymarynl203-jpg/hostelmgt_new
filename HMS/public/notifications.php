<?php
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../db.php';

require_login();

$db = hms_db();
$user = hms_current_user();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'mark_read_all') {
        $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$userId]);
        flash_set('success', 'All notifications marked as read.');
        redirect_to(hms_url('notifications.php'));
    }

    if ($action === 'mark_read_section') {
        $section = (string)($_POST['section'] ?? '');
        if ($section === 'general') {
            $db->prepare(
                'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0 AND type IN ("booking", "payment", "system")'
            )->execute([$userId]);
            flash_set('success', 'All general notifications marked as read.');
        } elseif ($section === 'maintenance') {
            $db->prepare(
                'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0 AND type = "maintenance"'
            )->execute([$userId]);
            flash_set('success', 'All maintenance notifications marked as read.');
        }
        redirect_to(hms_url('notifications.php'));
    }

    if ($action === 'mark_read_one') {
        $nid = (int)($_POST['notification_id'] ?? 0);
        if ($nid > 0) {
            $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
            $stmt->execute([$nid, $userId]);
            if ($stmt->rowCount() > 0) {
                flash_set('success', 'Notification marked as read.');
            }
        }
        redirect_to(hms_url('notifications.php'));
    }
}

$unreadGeneralStmt = $db->prepare(
    'SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0 AND type IN ("booking", "payment", "system")'
);
$unreadGeneralStmt->execute([$userId]);
$unreadGeneral = (int)($unreadGeneralStmt->fetch()['c'] ?? 0);

$unreadMaintStmt = $db->prepare(
    'SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0 AND type = "maintenance"'
);
$unreadMaintStmt->execute([$userId]);
$unreadMaintenance = (int)($unreadMaintStmt->fetch()['c'] ?? 0);

$unreadTotal = $unreadGeneral + $unreadMaintenance;

$stmt = $db->prepare('
    SELECT *
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 150
');
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

$generalUnread = [];
$generalRead = [];
$maintenanceUnread = [];
$maintenanceRead = [];

foreach ($notifications as $n) {
    $isMaint = ($n['type'] ?? '') === 'maintenance';
    $isRead = (int)($n['is_read'] ?? 0) === 1;
    if ($isMaint) {
        if ($isRead) {
            $maintenanceRead[] = $n;
        } else {
            $maintenanceUnread[] = $n;
        }
    } else {
        if ($isRead) {
            $generalRead[] = $n;
        } else {
            $generalUnread[] = $n;
        }
    }
}

/**
 * @param array<int, array<string, mixed>> $items
 */
function hms_notif_preview(array $n, int $maxLen = 96): string
{
    $msg = (string)($n['message'] ?? '');
    $msg = preg_replace('/\s+/u', ' ', trim($msg)) ?? $msg;
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($msg, 'UTF-8') <= $maxLen) {
            return $msg;
        }
        return mb_substr($msg, 0, max(1, $maxLen - 1), 'UTF-8') . '…';
    }
    if (strlen($msg) <= $maxLen) {
        return $msg;
    }
    return substr($msg, 0, $maxLen - 1) . '…';
}

/**
 * @param array<string, mixed> $n
 */
function hms_notif_type_badge_class(string $type): string
{
    return match ($type) {
        'booking' => 'bg-primary',
        'payment' => 'bg-success',
        'maintenance' => 'bg-warning text-dark',
        default => 'bg-secondary',
    };
}

/**
 * @param array<string, mixed> $n
 */
function hms_notif_type_label(string $type): string
{
    return match ($type) {
        'booking' => 'Booking',
        'payment' => 'Payment',
        'maintenance' => 'Maintenance',
        'system' => 'System',
        default => ucfirst($type),
    };
}

/**
 * JSON for a single data attribute (handles quotes and newlines in messages).
 *
 * @param array<string, mixed> $data
 */
function hms_notif_attr_json(array $data): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    return htmlspecialchars(json_encode($data, $flags), ENT_QUOTES, 'UTF-8');
}

layout_header('Notifications');
?>

<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
    <div>
        <h2 class="h4 mb-1">Notifications</h2>
        <div class="text-muted small">
            General updates (bookings, payments, system) are separate from maintenance request alerts.
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <?php if (!empty($notifications)): ?>
            <div class="hms-notif-filters d-flex flex-wrap gap-2" role="group" aria-label="Filter notifications">
                <button type="button" class="badge hms-notif-filter border-0 <?php echo $unreadTotal > 0 ? 'bg-warning text-dark' : 'bg-secondary'; ?>"
                    data-hms-notif-filter="unread" aria-pressed="false">
                    Unread: <?php echo e((string)$unreadTotal); ?>
                </button>
                <button type="button" class="badge hms-notif-filter border bg-light text-dark"
                    data-hms-notif-filter="general" aria-pressed="false">
                    General <?php echo e((string)$unreadGeneral); ?>
                </button>
                <button type="button" class="badge hms-notif-filter border bg-light text-dark"
                    data-hms-notif-filter="maintenance" aria-pressed="false">
                    Maintenance <?php echo e((string)$unreadMaintenance); ?>
                </button>
            </div>
        <?php else: ?>
            <span class="badge <?php echo $unreadTotal > 0 ? 'bg-warning text-dark' : 'bg-secondary'; ?>">
                Unread: <?php echo e((string)$unreadTotal); ?>
            </span>
        <?php endif; ?>
        <form method="post" action="" class="d-inline"<?php echo hms_data_confirm('Mark all notifications as read?'); ?>>
            <input type="hidden" name="action" value="mark_read_all">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <button class="btn btn-sm btn-outline-primary" type="submit" <?php echo $unreadTotal === 0 ? 'disabled' : ''; ?>>
                Mark all read
            </button>
        </form>
    </div>
</div>

<?php if (empty($notifications)): ?>
    <div class="alert alert-info">No notifications yet.</div>
<?php else: ?>
<div id="notif-sections" class="vstack gap-4" data-hms-notif-page data-hms-unread-total="<?php echo e((string)$unreadTotal); ?>">
    <section id="notif-general" class="card border-0 shadow-sm rounded-4 overflow-hidden hms-notif-section" data-hms-notif-section="general">
        <div class="card-header bg-white border-bottom py-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h3 class="h6 mb-0">Bookings, payments &amp; system</h3>
                <div class="text-muted small">Not related to maintenance tickets.</div>
            </div>
            <?php if ($unreadGeneral > 0): ?>
                <form method="post" action="" class="mb-0"<?php echo hms_data_confirm('Mark all general notifications as read?'); ?>>
                    <input type="hidden" name="action" value="mark_read_section">
                    <input type="hidden" name="section" value="general">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Mark section read</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if ($generalUnread === [] && $generalRead === []): ?>
                <div class="p-4 text-muted small">No notifications in this category yet.</div>
            <?php else: ?>
                <?php if ($generalUnread !== []): ?>
                    <div class="px-4 pt-3 pb-1 hms-notif-subhead" data-hms-notif-subhead="unread">
                        <span class="text-uppercase text-muted small fw-semibold">Unread</span>
                    </div>
                    <div class="list-group list-group-flush hms-notif-list">
                        <?php foreach ($generalUnread as $n): ?>
                            <?php
                                $nid = (int)$n['id'];
                                $type = (string)$n['type'];
                                $preview = hms_notif_preview($n);
                                $fullMsg = (string)$n['message'];
                                $when = (string)$n['created_at'];
                                $label = hms_notif_type_label($type);
                                $badgeClass = hms_notif_type_badge_class($type);
                            ?>
                            <div class="list-group-item list-group-item-action d-flex gap-3 align-items-start py-3 px-4 bg-light cursor-pointer hms-notif-row"
                                 role="button" tabindex="0" data-hms-notif-read="0"
                                 data-bs-toggle="modal" data-bs-target="#notifDetailModal"
                                 data-hms-notif="<?php echo hms_notif_attr_json([
                                     'id' => $nid,
                                     'typeLabel' => $label,
                                     'badgeClass' => $badgeClass,
                                     'when' => $when,
                                     'msg' => $fullMsg,
                                     'unread' => true,
                                 ]); ?>">
                                <div style="width: 10px; height: 10px; border-radius: 999px; margin-top: 6px;" class="bg-primary flex-shrink-0"></div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex justify-content-between gap-2 flex-wrap">
                                        <div>
                                            <span class="badge <?php echo e($badgeClass); ?>"><?php echo e($label); ?></span>
                                            <span class="badge bg-warning text-dark ms-1">New</span>
                                        </div>
                                        <div class="text-muted small text-nowrap"><?php echo e($when); ?></div>
                                    </div>
                                    <div class="mt-2 text-body small"><?php echo e($preview); ?></div>
                                    <div class="text-muted small mt-1">Click to view full details.</div>
                                </div>
                                <form method="post" class="flex-shrink-0" onclick="event.stopPropagation();">
                                    <input type="hidden" name="action" value="mark_read_one">
                                    <input type="hidden" name="notification_id" value="<?php echo e((string)$nid); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Mark as read without opening">Read</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($generalRead !== []): ?>
                    <div class="px-4 pt-3 pb-1 border-top hms-notif-subhead" data-hms-notif-subhead="read">
                        <span class="text-uppercase text-muted small fw-semibold">Read</span>
                    </div>
                    <div class="list-group list-group-flush hms-notif-list">
                        <?php foreach ($generalRead as $n): ?>
                            <?php
                                $nid = (int)$n['id'];
                                $type = (string)$n['type'];
                                $preview = hms_notif_preview($n);
                                $fullMsg = (string)$n['message'];
                                $when = (string)$n['created_at'];
                                $label = hms_notif_type_label($type);
                                $badgeClass = hms_notif_type_badge_class($type);
                            ?>
                            <div class="list-group-item list-group-item-action d-flex gap-3 align-items-start py-3 px-4 opacity-75 cursor-pointer hms-notif-row"
                                 role="button" tabindex="0" data-hms-notif-read="1"
                                 data-bs-toggle="modal" data-bs-target="#notifDetailModal"
                                 data-hms-notif="<?php echo hms_notif_attr_json([
                                     'id' => $nid,
                                     'typeLabel' => $label,
                                     'badgeClass' => $badgeClass,
                                     'when' => $when,
                                     'msg' => $fullMsg,
                                     'unread' => false,
                                 ]); ?>">
                                <div style="width: 10px; height: 10px; border-radius: 999px; margin-top: 6px;" class="bg-secondary flex-shrink-0"></div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex justify-content-between gap-2 flex-wrap">
                                        <span class="badge <?php echo e($badgeClass); ?>"><?php echo e($label); ?></span>
                                        <div class="text-muted small text-nowrap"><?php echo e($when); ?></div>
                                    </div>
                                    <div class="mt-2 text-muted small" style="white-space: pre-wrap;"><?php echo e($preview); ?></div>
                                    <div class="text-muted small mt-1">Click for full message.</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <section id="notif-maintenance" class="card border-0 shadow-sm rounded-4 overflow-hidden hms-notif-section" data-hms-notif-section="maintenance">
        <div class="card-header bg-white border-bottom py-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h3 class="h6 mb-0">Maintenance requests</h3>
                <div class="text-muted small">Alerts when you submit a ticket or when staff update its status.</div>
            </div>
            <?php if ($unreadMaintenance > 0): ?>
                <form method="post" action="" class="mb-0"<?php echo hms_data_confirm('Mark all maintenance notifications as read?'); ?>>
                    <input type="hidden" name="action" value="mark_read_section">
                    <input type="hidden" name="section" value="maintenance">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Mark section read</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if ($maintenanceUnread === [] && $maintenanceRead === []): ?>
                <div class="p-4 text-muted small">No maintenance notifications yet.</div>
            <?php else: ?>
                <?php if ($maintenanceUnread !== []): ?>
                    <div class="px-4 pt-3 pb-1 hms-notif-subhead" data-hms-notif-subhead="unread">
                        <span class="text-uppercase text-muted small fw-semibold">Unread</span>
                    </div>
                    <div class="list-group list-group-flush hms-notif-list">
                        <?php foreach ($maintenanceUnread as $n): ?>
                            <?php
                                $nid = (int)$n['id'];
                                $preview = hms_notif_preview($n);
                                $fullMsg = (string)$n['message'];
                                $when = (string)$n['created_at'];
                                $label = hms_notif_type_label('maintenance');
                                $badgeClass = hms_notif_type_badge_class('maintenance');
                            ?>
                            <div class="list-group-item list-group-item-action d-flex gap-3 align-items-start py-3 px-4 bg-light cursor-pointer hms-notif-row"
                                 role="button" tabindex="0" data-hms-notif-read="0"
                                 data-bs-toggle="modal" data-bs-target="#notifDetailModal"
                                 data-hms-notif="<?php echo hms_notif_attr_json([
                                     'id' => $nid,
                                     'typeLabel' => $label,
                                     'badgeClass' => $badgeClass,
                                     'when' => $when,
                                     'msg' => $fullMsg,
                                     'unread' => true,
                                 ]); ?>">
                                <div style="width: 10px; height: 10px; border-radius: 999px; margin-top: 6px;" class="bg-primary flex-shrink-0"></div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex justify-content-between gap-2 flex-wrap">
                                        <div>
                                            <span class="badge <?php echo e($badgeClass); ?>"><?php echo e($label); ?></span>
                                            <span class="badge bg-warning text-dark ms-1">New</span>
                                        </div>
                                        <div class="text-muted small text-nowrap"><?php echo e($when); ?></div>
                                    </div>
                                    <div class="mt-2 text-body small"><?php echo e($preview); ?></div>
                                    <div class="text-muted small mt-1">Click to view full details.</div>
                                </div>
                                <form method="post" class="flex-shrink-0" onclick="event.stopPropagation();">
                                    <input type="hidden" name="action" value="mark_read_one">
                                    <input type="hidden" name="notification_id" value="<?php echo e((string)$nid); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Mark as read without opening">Read</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($maintenanceRead !== []): ?>
                    <div class="px-4 pt-3 pb-1 border-top hms-notif-subhead" data-hms-notif-subhead="read">
                        <span class="text-uppercase text-muted small fw-semibold">Read</span>
                    </div>
                    <div class="list-group list-group-flush hms-notif-list">
                        <?php foreach ($maintenanceRead as $n): ?>
                            <?php
                                $nid = (int)$n['id'];
                                $preview = hms_notif_preview($n);
                                $fullMsg = (string)$n['message'];
                                $when = (string)$n['created_at'];
                                $label = hms_notif_type_label('maintenance');
                                $badgeClass = hms_notif_type_badge_class('maintenance');
                            ?>
                            <div class="list-group-item list-group-item-action d-flex gap-3 align-items-start py-3 px-4 opacity-75 cursor-pointer hms-notif-row"
                                 role="button" tabindex="0" data-hms-notif-read="1"
                                 data-bs-toggle="modal" data-bs-target="#notifDetailModal"
                                 data-hms-notif="<?php echo hms_notif_attr_json([
                                     'id' => $nid,
                                     'typeLabel' => $label,
                                     'badgeClass' => $badgeClass,
                                     'when' => $when,
                                     'msg' => $fullMsg,
                                     'unread' => false,
                                 ]); ?>">
                                <div style="width: 10px; height: 10px; border-radius: 999px; margin-top: 6px;" class="bg-secondary flex-shrink-0"></div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex justify-content-between gap-2 flex-wrap">
                                        <span class="badge <?php echo e($badgeClass); ?>"><?php echo e($label); ?></span>
                                        <div class="text-muted small text-nowrap"><?php echo e($when); ?></div>
                                    </div>
                                    <div class="mt-2 text-muted small" style="white-space: pre-wrap;"><?php echo e($preview); ?></div>
                                    <div class="text-muted small mt-1">Click for full message.</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>
<div id="hmsNotifFilterEmpty" class="alert alert-secondary d-none mt-3 mb-0" role="status">
    No notifications match this filter.
</div>
<?php endif; ?>

<div class="modal fade" id="notifDetailModal" tabindex="-1" aria-labelledby="notifDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h2 id="notifDetailModalLabel" class="modal-title h5 mb-1">Notification details</h2>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                        <span id="notifModalBadge" class="badge bg-secondary"></span>
                        <span id="notifModalWhen" class="text-muted small"></span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-3">
                <div id="notifModalMsg" class="text-body" style="white-space: pre-wrap;"></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <form method="post" id="notifModalMarkReadForm" class="me-auto" style="display: none;">
                    <input type="hidden" name="action" value="mark_read_one">
                    <input type="hidden" name="notification_id" id="notifModalMarkReadId" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <button type="submit" class="btn btn-primary">Mark as read</button>
                </form>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php layout_footer(); ?>
