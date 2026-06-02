<?php
require_once __DIR__ . '/../../lib/layout.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
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

$hostelId = (int)($_GET['hostel_id'] ?? 0);
$balanceRaw = strtolower(trim((string)($_GET['balance'] ?? 'all')));
$balanceAliases = [
    'defaulters' => 'outstanding',
    'defaulter' => 'outstanding',
    'underpaid' => 'outstanding',
    'owed' => 'outstanding',
    'zero_balance' => 'settled',
    'zerobalance' => 'settled',
    'paid_up' => 'settled',
    'fully_paid' => 'settled',
];
$balanceFilter = $balanceAliases[$balanceRaw] ?? $balanceRaw;
if (!in_array($balanceFilter, ['all', 'outstanding', 'settled'], true)) {
    $balanceFilter = 'all';
}

$sortBy = trim((string)($_GET['sort'] ?? 'outstanding_desc'));
$allowedSort = ['outstanding_desc', 'outstanding_asc', 'student_asc', 'hostel_asc', 'due_desc', 'newest'];
if (!in_array($sortBy, $allowedSort, true)) {
    $sortBy = 'outstanding_desc';
}

$paidSubSql = '(SELECT COALESCE(SUM(p3.amount), 0) FROM payments p3 WHERE p3.booking_id = b.id AND p3.status = "successful")';
$orderBySql = match ($sortBy) {
    'outstanding_asc' => 'GREATEST(0, b.total_due - ' . $paidSubSql . ') ASC, b.requested_at DESC',
    'student_asc' => 'u.name ASC, u.id ASC, b.id DESC',
    'hostel_asc' => 'h.name ASC, r.room_number ASC, b.id DESC',
    'due_desc' => 'b.total_due DESC, b.requested_at DESC',
    'newest' => 'b.requested_at DESC, b.id DESC',
    default => 'GREATEST(0, b.total_due - ' . $paidSubSql . ') DESC, b.requested_at DESC',
};

if ($role === 'warden') {
    $scopeSql = 'h.managed_by = ?';
    $scopeParams = [$userId];
} else {
    $scopeSql = $adminHostelScope;
    $scopeParams = [$userId];
}

$bookingWhere = [$scopeSql, 'b.status IN ("pending","approved","checked_in","checked_out")'];
$bookingParams = $scopeParams;
if ($hostelId > 0) {
    $bookingWhere[] = 'h.id = ?';
    $bookingParams[] = $hostelId;
}
if ($balanceFilter === 'outstanding') {
    // Same threshold as booking approvals: defaulters are only students who paid the 20% booking fee but still owe the rest.
    $bookingWhere[] = 'b.total_due > 0';
    $bookingWhere[] = 'b.total_due > (
        SELECT COALESCE(SUM(p2.amount), 0) FROM payments p2
        WHERE p2.booking_id = b.id AND p2.status = "successful"
    )';
    $bookingWhere[] = '(
        SELECT COALESCE(SUM(p2.amount), 0) FROM payments p2
        WHERE p2.booking_id = b.id AND p2.status = "successful"
    ) >= (b.total_due * 0.20)';
} elseif ($balanceFilter === 'settled') {
    $bookingWhere[] = 'b.total_due <= (
        SELECT COALESCE(SUM(p2.amount), 0) FROM payments p2
        WHERE p2.booking_id = b.id AND p2.status = "successful"
    )';
}

$bookingWhereSql = implode(' AND ', $bookingWhere);

$scopeOnlyWhere = [$scopeSql, 'b.status IN ("pending","approved","checked_in","checked_out")'];
$scopeOnlyParams = $scopeParams;
if ($hostelId > 0) {
    $scopeOnlyWhere[] = 'h.id = ?';
    $scopeOnlyParams[] = $hostelId;
}
$scopeOnlySql = implode(' AND ', $scopeOnlyWhere);
$paidSub = '(SELECT COALESCE(SUM(p2.amount), 0) FROM payments p2 WHERE p2.booking_id = b.id AND p2.status = "successful")';

$scopeCountsStmt = $db->prepare('
    SELECT
        COUNT(*) AS total_active,
        SUM(CASE
            WHEN b.total_due > ' . $paidSub . ' AND ' . $paidSub . ' >= (b.total_due * 0.20) THEN 1
            ELSE 0
        END) AS defaulters,
        SUM(CASE WHEN b.total_due <= ' . $paidSub . ' THEN 1 ELSE 0 END) AS zero_balance,
        SUM(CASE
            WHEN b.total_due > ' . $paidSub . ' AND ' . $paidSub . ' < (b.total_due * 0.20) THEN 1
            ELSE 0
        END) AS awaiting_booking_fee
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN hostels h ON h.id = r.hostel_id
    WHERE ' . $scopeOnlySql . '
');
$scopeCountsStmt->execute($scopeOnlyParams);
$scopeCounts = $scopeCountsStmt->fetch() ?: [];
$scopeTotal = (int)($scopeCounts['total_active'] ?? 0);
$scopeDefaulters = (int)($scopeCounts['defaulters'] ?? 0);
$scopeZeroBal = (int)($scopeCounts['zero_balance'] ?? 0);
$scopeAwaitingFee = (int)($scopeCounts['awaiting_booking_fee'] ?? 0);

$summaryStmt = $db->prepare('
    SELECT
        COUNT(*) AS booking_count,
        COALESCE(SUM(GREATEST(0, b.total_due - (
            SELECT COALESCE(SUM(p2.amount), 0) FROM payments p2
            WHERE p2.booking_id = b.id AND p2.status = "successful"
        ))), 0) AS total_outstanding,
        COALESCE(SUM((
            SELECT COALESCE(SUM(p2.amount), 0) FROM payments p2
            WHERE p2.booking_id = b.id AND p2.status = "successful"
        )), 0) AS total_paid_on_bookings,
        COALESCE(SUM(b.total_due), 0) AS total_due_on_bookings
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN hostels h ON h.id = r.hostel_id
    WHERE ' . $bookingWhereSql . '
');
$summaryStmt->execute($bookingParams);
$summary = $summaryStmt->fetch() ?: [];

$bookingsStmt = $db->prepare('
    SELECT
        b.id AS booking_id,
        b.status,
        b.total_due,
        b.start_date,
        b.end_date,
        u.name AS student_name,
        u.email AS student_email,
        h.name AS hostel_name,
        h.id AS hostel_id,
        r.room_number,
        (
            SELECT COALESCE(SUM(p.amount), 0)
            FROM payments p
            WHERE p.booking_id = b.id AND p.status = "successful"
        ) AS paid_amount,
        (
            SELECT COALESCE(SUM(p.amount), 0)
            FROM payments p
            WHERE p.booking_id = b.id AND p.status = "pending"
        ) AS pending_amount
    FROM bookings b
    JOIN users u ON u.id = b.student_id
    JOIN rooms r ON r.id = b.room_id
    JOIN hostels h ON h.id = r.hostel_id
    WHERE ' . $bookingWhereSql . '
    ORDER BY ' . $orderBySql . '
    LIMIT 250
');
$bookingsStmt->execute($bookingParams);
$bookingRows = $bookingsStmt->fetchAll();

$txWhere = [$scopeSql, 'p.status IN ("successful", "failed")'];
$txParams = $scopeParams;
if ($hostelId > 0) {
    $txWhere[] = 'h.id = ?';
    $txParams[] = $hostelId;
}
$txWhereSql = implode(' AND ', $txWhere);

$payments = $db->prepare('
    SELECT p.*,
        b.id AS booking_id,
        u.name AS student_name,
        h.name AS hostel_name,
        r.room_number
    FROM payments p
    JOIN bookings b ON b.id = p.booking_id
    JOIN users u ON u.id = b.student_id
    JOIN rooms r ON r.id = b.room_id
    JOIN hostels h ON h.id = r.hostel_id
    WHERE ' . $txWhereSql . '
    ORDER BY p.created_at DESC
    LIMIT 150
');
$payments->execute($txParams);
$payments = $payments->fetchAll();

if ($role === 'warden') {
    $hostelFilter = $db->prepare('SELECT id, name FROM hostels WHERE managed_by = ? ORDER BY name ASC');
    $hostelFilter->execute([$userId]);
    $hostelFilter = $hostelFilter->fetchAll();
} else {
    $hostelFilter = $db->prepare('
        SELECT h.id, h.name
        FROM hostels h
        WHERE ' . $adminHostelScope . '
        ORDER BY h.name ASC
    ');
    $hostelFilter->execute([$userId]);
    $hostelFilter = $hostelFilter->fetchAll();
}

layout_header('Payments');
?>

<div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h2 class="h4 mb-1">Student payments</h2>
        <div class="text-muted small">Per booking: amount due, collected (successful), and balance. Same hostel scope as elsewhere (warden: assigned hostel; university admin: hostels you created).</div>
    </div>
    <a class="btn btn-outline-primary btn-sm" href="<?php echo hms_url('admin/reports.php'); ?>">Payment reports</a>
</div>

<?php
$payQs = function (array $overrides) use ($hostelId, $balanceFilter, $sortBy): string {
    $q = array_merge(
        [
            'hostel_id' => (string)$hostelId,
            'balance' => $balanceFilter,
            'sort' => $sortBy,
        ],
        $overrides
    );
    return hms_url('admin/payments.php?' . http_build_query($q));
};
?>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <h3 class="h6 text-uppercase text-muted mb-2">Defaulters vs zero balance</h3>
        <p class="text-muted small mb-3">Counts respect your role and the hostel filter (if any). <strong>Defaulters</strong> are students who have paid at least <strong>20% of the booking fee</strong> (same rule as approvals) but still owe the balance. Bookings below 20% paid are not listed as defaulters.</p>
        <?php if ($scopeAwaitingFee > 0): ?>
            <p class="small text-muted mb-3"><span class="badge bg-secondary"><?php echo e((string)$scopeAwaitingFee); ?></span> active booking(s) are still below the 20% booking fee; they appear in &quot;All active&quot; with an &quot;Awaiting booking fee&quot; label, not under Defaulters.</p>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <a class="btn btn-sm <?php echo $balanceFilter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>"
                href="<?php echo e($payQs(['balance' => 'all', 'sort' => 'outstanding_desc'])); ?>">
                All active <span class="badge bg-light text-dark ms-1"><?php echo e((string)$scopeTotal); ?></span>
            </a>
            <a class="btn btn-sm <?php echo $balanceFilter === 'outstanding' ? 'btn-warning' : 'btn-outline-warning'; ?>"
                href="<?php echo e($payQs(['balance' => 'outstanding', 'sort' => 'outstanding_desc'])); ?>">
                Defaulters <span class="badge <?php echo $balanceFilter === 'outstanding' ? 'bg-dark' : 'bg-warning text-dark'; ?> ms-1"><?php echo e((string)$scopeDefaulters); ?></span>
            </a>
            <a class="btn btn-sm <?php echo $balanceFilter === 'settled' ? 'btn-success' : 'btn-outline-success'; ?>"
                href="<?php echo e($payQs(['balance' => 'settled', 'sort' => 'student_asc'])); ?>">
                Zero balance <span class="badge <?php echo $balanceFilter === 'settled' ? 'bg-light text-success' : 'bg-success'; ?> ms-1"><?php echo e((string)$scopeZeroBal); ?></span>
            </a>
        </div>

        <h3 class="h6 text-uppercase text-muted mb-3">Filters</h3>
        <form method="get" action="" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Hostel</label>
                <select name="hostel_id" class="form-select">
                    <option value="0">All hostels in scope</option>
                    <?php foreach ($hostelFilter as $h): ?>
                        <option value="<?php echo (int)$h['id']; ?>" <?php echo $hostelId === (int)$h['id'] ? 'selected' : ''; ?>>
                            <?php echo e($h['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Who to list</label>
                <select name="balance" class="form-select">
                    <option value="all" <?php echo $balanceFilter === 'all' ? 'selected' : ''; ?>>All active bookings</option>
                    <option value="outstanding" <?php echo $balanceFilter === 'outstanding' ? 'selected' : ''; ?>>Defaulters (20% paid, still owe)</option>
                    <option value="settled" <?php echo $balanceFilter === 'settled' ? 'selected' : ''; ?>>Zero balance (fully paid)</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Sort by</label>
                <select name="sort" class="form-select">
                    <option value="outstanding_desc" <?php echo $sortBy === 'outstanding_desc' ? 'selected' : ''; ?>>Outstanding (highest first)</option>
                    <option value="outstanding_asc" <?php echo $sortBy === 'outstanding_asc' ? 'selected' : ''; ?>>Outstanding (lowest first)</option>
                    <option value="student_asc" <?php echo $sortBy === 'student_asc' ? 'selected' : ''; ?>>Student name (A–Z)</option>
                    <option value="hostel_asc" <?php echo $sortBy === 'hostel_asc' ? 'selected' : ''; ?>>Hostel, then room</option>
                    <option value="due_desc" <?php echo $sortBy === 'due_desc' ? 'selected' : ''; ?>>Total due (highest first)</option>
                    <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest booking first</option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary w-100" type="submit">Apply</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body p-3">
                <div class="text-muted small">Total due (filtered)</div>
                <div class="fs-5 fw-semibold"><?php echo e(number_format((float)($summary['total_due_on_bookings'] ?? 0), 2)); ?> <span class="text-muted fs-6">UGX</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body p-3">
                <div class="text-muted small">Paid (successful)</div>
                <div class="fs-5 fw-semibold text-success"><?php echo e(number_format((float)($summary['total_paid_on_bookings'] ?? 0), 2)); ?> <span class="text-muted fs-6">UGX</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body p-3">
                <div class="text-muted small">Outstanding balance</div>
                <div class="fs-5 fw-semibold text-primary"><?php echo e(number_format((float)($summary['total_outstanding'] ?? 0), 2)); ?> <span class="text-muted fs-6">UGX</span></div>
                <div class="small text-muted mt-1"><?php echo e((string)(int)($summary['booking_count'] ?? 0)); ?> booking(s) in this view</div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <h2 class="h5 mb-3">By student / booking</h2>
        <p class="text-muted small mb-3">Active bookings only (pending through checked out). <strong>Defaulters</strong> owe a positive balance after paying at least 20% of the booking fee (successful payments only). <strong>Zero balance</strong> means total due is fully covered. Pending = in-flight gateway attempts.</p>
        <?php if (empty($bookingRows)): ?>
            <div class="alert alert-info mb-0">No bookings match these filters.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Booking</th>
                            <th>Student</th>
                            <th>Hostel / room</th>
                            <th>Status</th>
                            <th class="text-end">Due (UGX)</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Balance</th>
                            <th class="text-end">Pending</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookingRows as $row): ?>
                            <?php
                                $due = (float)($row['total_due'] ?? 0);
                                $paid = (float)($row['paid_amount'] ?? 0);
                                $pend = (float)($row['pending_amount'] ?? 0);
                                $balance = max(0, $due - $paid);
                                $over = $paid > $due ? $paid - $due : 0;
                                $minBookingFee = $due > 0 ? $due * 0.20 : 0.0;
                                $bookingFeeMet = $due > 0 && $paid + 0.000001 >= $minBookingFee;
                                $isDefaulter = $due > $paid && $bookingFeeMet;
                                $awaitingBookingFee = $due > $paid && !$bookingFeeMet && $due > 0;
                            ?>
                            <tr class="<?php echo $isDefaulter ? 'table-warning' : ($awaitingBookingFee ? 'table-light' : ''); ?>">
                                <td class="text-muted"><?php echo (int)$row['booking_id']; ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo e((string)($row['student_name'] ?? '')); ?></div>
                                    <div class="small text-muted"><?php echo e((string)($row['student_email'] ?? '')); ?></div>
                                </td>
                                <td class="small">
                                    <?php echo e((string)($row['hostel_name'] ?? '')); ?>
                                    <span class="text-muted">· Room <?php echo e((string)($row['room_number'] ?? '')); ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo e((string)($row['status'] ?? '')); ?></span>
                                </td>
                                <td class="text-end"><?php echo e(number_format($due, 2)); ?></td>
                                <td class="text-end text-success"><?php echo e(number_format($paid, 2)); ?></td>
                                <td class="text-end fw-semibold">
                                    <?php if ($over > 0): ?>
                                        <span class="text-muted">0.00</span>
                                        <div class="small text-success">+<?php echo e(number_format($over, 2)); ?> credit</div>
                                    <?php else: ?>
                                        <?php echo e(number_format($balance, 2)); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-muted"><?php echo e(number_format($pend, 2)); ?></td>
                                <td class="text-nowrap small">
                                    <?php if ($isDefaulter): ?>
                                        <span class="badge bg-warning text-dark">Defaulter</span>
                                    <?php elseif ($awaitingBookingFee): ?>
                                        <span class="badge bg-secondary">Awaiting booking fee</span>
                                    <?php elseif ($over > 0): ?>
                                        <span class="badge bg-success">Overpaid</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-success border">Zero balance</span>
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

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-4">
        <h2 class="h5 mb-3">Recent transactions</h2>
        <p class="text-muted small mb-3">Successful and failed student payments (pending attempts are excluded). Respects the hostel filter above.</p>
        <?php if (empty($payments)): ?>
            <div class="alert alert-info mb-0">No successful or failed student transactions yet for this filter.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Booking</th>
                            <th>Student</th>
                            <th>Hostel / room</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Method</th>
                            <th>Paid at</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td class="text-muted"><?php echo e((string)($p['booking_id'] ?? '')); ?></td>
                                <td><?php echo e($p['student_name']); ?></td>
                                <td class="text-muted small"><?php echo e((string)($p['hostel_name'] ?? '')); ?> / Room <?php echo e((string)($p['room_number'] ?? '')); ?></td>
                                <td><?php echo e(number_format((float)($p['amount'] ?? 0), 2)); ?> UGX</td>
                                <td>
                                    <span class="badge <?php echo ($p['status'] === 'successful') ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo e($p['status']); ?>
                                    </span>
                                </td>
                                <td class="small text-muted">
                                    <?php echo e((string)($p['method'] ?? '')); ?>
                                    <?php if (!empty($p['gateway'])): ?>
                                        <span class="d-block"><?php echo e((string)$p['gateway']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?php echo e((string)($p['paid_at'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php layout_footer(); ?>
