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

$hostelId = (int)($_GET['hostel_id'] ?? 0);
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

if ($from === '') {
    $from = date('Y-m-d', time() - 30 * 24 * 60 * 60);
}
if ($to === '') {
    $to = date('Y-m-d');
}

// Occupancy per hostel
$occWhere = [];
$occParams = [];
$occWhere[] = 'h.id = h.id'; // no-op to simplify building

if ($role === 'warden') {
    $occWhere[] = 'h.managed_by = ?';
    $occParams[] = $userId;
} elseif ($role === 'university_admin') {
    $occWhere[] = $adminHostelScope;
    $occParams[] = $userId;
}
if ($hostelId > 0) {
    $occWhere[] = 'h.id = ?';
    $occParams[] = $hostelId;
}

$occSql = '
    SELECT
        h.id,
        h.name,
        COUNT(r.id) AS room_count,
        COALESCE(SUM(r.current_occupancy), 0) AS occupied_units,
        COALESCE(SUM(r.capacity), 0) AS capacity_units,
        COALESCE(SUM(CASE WHEN r.current_occupancy >= r.capacity THEN 1 ELSE 0 END), 0) AS full_rooms
    FROM hostels h
    JOIN rooms r ON r.hostel_id = h.id
    WHERE ' . implode(' AND ', $occWhere) . '
    GROUP BY h.id, h.name
    ORDER BY h.name ASC
';
$occStmt = $db->prepare($occSql);
$occStmt->execute($occParams);
$occupancy = $occStmt->fetchAll();

// Booking lifecycle stats (requested/approved/check-in/check-out)
if ($role === 'warden') {
    $bookingWhere = 'h.managed_by = ?';
    $bookingParams = [$userId];
    if ($hostelId > 0) {
        $bookingWhere .= ' AND h.id = ?';
        $bookingParams[] = $hostelId;
    }
} else {
    $bookingWhere = $adminHostelScope;
    $bookingParams = [$userId];
    if ($hostelId > 0) {
        $bookingWhere .= ' AND h.id = ?';
        $bookingParams[] = $hostelId;
    }
}

$bookingStatsStmt = $db->prepare('
    SELECT b.status, COUNT(*) AS c
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN hostels h ON h.id = r.hostel_id
    WHERE ' . $bookingWhere . ' AND b.requested_at >= ? AND b.requested_at < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY b.status
');
$bookingStatsStmt->execute(array_merge($bookingParams, [$from, $to]));
$bookingStatsRows = $bookingStatsStmt->fetchAll();
$bookingStats = [];
foreach ($bookingStatsRows as $row) {
    $bookingStats[$row['status']] = (int)($row['c'] ?? 0);
}

// Payment summary by method for the period
$paymentParams = [];
$paymentWhere = '';
if ($role === 'warden') {
    $paymentWhere = 'h.managed_by = ?';
    $paymentParams[] = $userId;
} else {
    $paymentWhere = $adminHostelScope;
    $paymentParams[] = $userId;
}

if ($hostelId > 0) {
    $paymentWhere .= ' AND h.id = ?';
    $paymentParams[] = $hostelId;
}

$paymentTotalStmt = $db->prepare('
    SELECT
        COALESCE(SUM(CASE WHEN p.status = "successful" THEN p.amount ELSE 0 END), 0) AS total_success_amount,
        COALESCE(SUM(CASE WHEN p.status = "failed" THEN p.amount ELSE 0 END), 0) AS total_failed_amount,
        COUNT(CASE WHEN p.status = "successful" THEN 1 END) AS success_count,
        COUNT(CASE WHEN p.status = "failed" THEN 1 END) AS failed_count
    FROM payments p
    JOIN bookings b ON b.id = p.booking_id
    JOIN rooms r ON r.id = b.room_id
    JOIN hostels h ON h.id = r.hostel_id
    WHERE ' . $paymentWhere . '
      AND p.status IN ("successful", "failed")
      AND p.created_at >= ? AND p.created_at < DATE_ADD(?, INTERVAL 1 DAY)
');
$paymentTotalStmt->execute(array_merge($paymentParams, [$from, $to]));
$paymentTotals = $paymentTotalStmt->fetch() ?: [];

$methodStmt = $db->prepare('
    SELECT p.method, p.provider,
        COALESCE(SUM(p.amount),0) AS amount,
        COUNT(*) AS c
    FROM payments p
    JOIN bookings b ON b.id = p.booking_id
    JOIN rooms r ON r.id = b.room_id
    JOIN hostels h ON h.id = r.hostel_id
    WHERE ' . $paymentWhere . '
      AND p.status IN ("successful", "failed")
      AND p.created_at >= ? AND p.created_at < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY p.method, p.provider
    ORDER BY amount DESC
    LIMIT 12
');
$methodStmt->execute(array_merge($paymentParams, [$from, $to]));
$methodBreakdown = $methodStmt->fetchAll();

// Chart-ready data
$occupancyLabels = [];
$occupancyOccupiedData = [];
$occupancyAvailableData = [];
foreach ($occupancy as $o) {
    $cap = (int)($o['capacity_units'] ?? 0);
    $occ = (int)($o['occupied_units'] ?? 0);
    $occupancyLabels[] = (string)($o['name'] ?? 'Hostel');
    $occupancyOccupiedData[] = $occ;
    $occupancyAvailableData[] = max(0, $cap - $occ);
}

$bookingStatusOrder = ['pending', 'approved', 'checked_in', 'checked_out', 'rejected'];
$bookingStatusLabels = [];
$bookingStatusData = [];
foreach ($bookingStatusOrder as $status) {
    $bookingStatusLabels[] = ucwords(str_replace('_', ' ', $status));
    $bookingStatusData[] = (int)($bookingStats[$status] ?? 0);
}

$paymentStatusLabels = ['Successful', 'Failed'];
$paymentStatusData = [
    (int)($paymentTotals['success_count'] ?? 0),
    (int)($paymentTotals['failed_count'] ?? 0),
];

$methodLabels = [];
$methodAmounts = [];
foreach ($methodBreakdown as $m) {
    $method = (string)($m['method'] ?? 'unknown');
    $provider = (string)($m['provider'] ?? 'other');
    $methodLabels[] = strtoupper($provider) . ' - ' . ucwords(str_replace('_', ' ', $method));
    $methodAmounts[] = (float)($m['amount'] ?? 0);
}

// Hostels list for filter dropdown
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

// Current outstanding balances on active bookings (not tied to the date range; respects hostel filter).
$outBookWhere = [];
$outBookParams = [];
if ($role === 'warden') {
    $outBookWhere[] = 'h.managed_by = ?';
    $outBookParams[] = $userId;
} else {
    $outBookWhere[] = $adminHostelScope;
    $outBookParams[] = $userId;
}
$outBookWhere[] = 'b.status IN ("pending","approved","checked_in","checked_out")';
if ($hostelId > 0) {
    $outBookWhere[] = 'h.id = ?';
    $outBookParams[] = $hostelId;
}
$outBookSql = implode(' AND ', $outBookWhere);

$outstandingAgg = $db->prepare('
    SELECT
        COUNT(*) AS active_bookings,
        COALESCE(SUM(GREATEST(0, b.total_due - (
            SELECT COALESCE(SUM(p.amount), 0) FROM payments p
            WHERE p.booking_id = b.id AND p.status = "successful"
        ))), 0) AS total_outstanding,
        COALESCE(SUM((
            SELECT COALESCE(SUM(p.amount), 0) FROM payments p
            WHERE p.booking_id = b.id AND p.status = "successful"
        )), 0) AS total_paid_on_bookings,
        COALESCE(SUM(b.total_due), 0) AS total_due_on_bookings
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN hostels h ON h.id = r.hostel_id
    WHERE ' . $outBookSql . '
');
$outstandingAgg->execute($outBookParams);
$outstandingSummary = $outstandingAgg->fetch() ?: [];

$outstandingByHostel = $db->prepare('
    SELECT
        h.id,
        h.name,
        COALESCE(SUM(b.total_due), 0) AS total_due,
        COALESCE(SUM((
            SELECT COALESCE(SUM(p.amount), 0) FROM payments p
            WHERE p.booking_id = b.id AND p.status = "successful"
        )), 0) AS total_paid,
        COALESCE(SUM(GREATEST(0, b.total_due - (
            SELECT COALESCE(SUM(p.amount), 0) FROM payments p
            WHERE p.booking_id = b.id AND p.status = "successful"
        ))), 0) AS outstanding
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN hostels h ON h.id = r.hostel_id
    WHERE ' . $outBookSql . '
    GROUP BY h.id, h.name
    ORDER BY outstanding DESC, h.name ASC
');
$outstandingByHostel->execute($outBookParams);
$outstandingByHostel = $outstandingByHostel->fetchAll();

$dailyStmt = $db->prepare('
    SELECT
        DATE(p.created_at) AS pay_day,
        COALESCE(SUM(p.amount), 0) AS day_amount,
        COUNT(*) AS day_count
    FROM payments p
    JOIN bookings b ON b.id = p.booking_id
    JOIN rooms r ON r.id = b.room_id
    JOIN hostels h ON h.id = r.hostel_id
    WHERE ' . $paymentWhere . '
      AND p.status = "successful"
      AND p.created_at >= ? AND p.created_at < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY DATE(p.created_at)
    ORDER BY pay_day ASC
');
$dailyStmt->execute(array_merge($paymentParams, [$from, $to]));
$dailyPayments = $dailyStmt->fetchAll();

$dailyLabels = [];
$dailyAmounts = [];
foreach ($dailyPayments as $d) {
    $dailyLabels[] = (string)($d['pay_day'] ?? '');
    $dailyAmounts[] = (float)($d['day_amount'] ?? 0);
}

layout_header('Reports & Oversight');
?>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <h2 class="h4 mb-3">Filters</h2>
        <form method="get" action="">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Hostel</label>
                    <select name="hostel_id" class="form-select">
                        <option value="0">All hostels</option>
                        <?php foreach ($hostelFilter as $h): ?>
                            <option value="<?php echo (int)$h['id']; ?>" <?php echo $hostelId === (int)$h['id'] ? 'selected' : ''; ?>>
                                <?php echo e($h['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">From</label>
                    <input type="date" name="from" class="form-control" value="<?php echo e($from); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To</label>
                    <input type="date" name="to" class="form-control" value="<?php echo e($to); ?>">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" type="submit">Apply</button>
                </div>
            </div>
            <p class="text-muted small mt-3 mb-0">The date range applies to booking-request counts, payment totals in the period, and the daily collections chart. Outstanding balances are a current snapshot (today), still filtered by hostel when selected.</p>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h2 class="h4 mb-1">Payment balances (current)</h2>
                <div class="text-muted small">Active bookings only: total due, collected so far, and remaining balance. Same privilege scope as the <a href="<?php echo hms_url('admin/payments.php'); ?>">Payments</a> screen.</div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="border rounded-3 p-3 bg-light">
                    <div class="text-muted small">Total due on these bookings</div>
                    <div class="fw-semibold fs-5"><?php echo e(number_format((float)($outstandingSummary['total_due_on_bookings'] ?? 0), 2)); ?> UGX</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded-3 p-3 bg-light">
                    <div class="text-muted small">Paid (successful)</div>
                    <div class="fw-semibold fs-5 text-success"><?php echo e(number_format((float)($outstandingSummary['total_paid_on_bookings'] ?? 0), 2)); ?> UGX</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded-3 p-3 bg-light">
                    <div class="text-muted small">Outstanding balance</div>
                    <div class="fw-semibold fs-5 text-primary"><?php echo e(number_format((float)($outstandingSummary['total_outstanding'] ?? 0), 2)); ?> UGX</div>
                    <div class="small text-muted mt-1"><?php echo e((string)(int)($outstandingSummary['active_bookings'] ?? 0)); ?> active booking(s)</div>
                </div>
            </div>
        </div>
        <?php if (empty($outstandingByHostel)): ?>
            <div class="alert alert-info mb-0">No active bookings in this scope.</div>
        <?php else: ?>
            <h3 class="h6 mb-2">By hostel</h3>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Hostel</th>
                            <th class="text-end">Due</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($outstandingByHostel as $oh): ?>
                            <tr>
                                <td><?php echo e((string)($oh['name'] ?? '')); ?></td>
                                <td class="text-end"><?php echo e(number_format((float)($oh['total_due'] ?? 0), 2)); ?></td>
                                <td class="text-end text-success"><?php echo e(number_format((float)($oh['total_paid'] ?? 0), 2)); ?></td>
                                <td class="text-end fw-semibold"><?php echo e(number_format((float)($oh['outstanding'] ?? 0), 2)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($dailyPayments)): ?>
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <h2 class="h4 mb-3">Daily successful collections</h2>
        <p class="text-muted small mb-3">Sum of successful student payments recorded each day in the selected period.</p>
        <div class="chart-wrap">
            <canvas id="paymentDailyChart" aria-label="Daily successful payment amounts"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h2 class="h4 mb-3">Occupancy Summary</h2>
                <?php if (!empty($occupancy)): ?>
                    <div class="chart-wrap mb-4">
                        <canvas id="occupancyChart" aria-label="Hostel occupancy chart"></canvas>
                    </div>
                <?php endif; ?>
                <?php if (empty($occupancy)): ?>
                    <div class="alert alert-info">No room data available for the selected filter.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Hostel</th>
                                    <th>Rooms</th>
                                    <th>Occupied</th>
                                    <th>Capacity</th>
                                    <th>Availability</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($occupancy as $o): ?>
                                    <?php
                                        $cap = (int)($o['capacity_units'] ?? 0);
                                        $occ = (int)($o['occupied_units'] ?? 0);
                                        $avail = max(0, $cap - $occ);
                                    ?>
                                    <tr>
                                        <td><?php echo e($o['name']); ?></td>
                                        <td><?php echo e((string)($o['room_count'] ?? 0)); ?></td>
                                        <td><?php echo e((string)$occ); ?></td>
                                        <td><?php echo e((string)$cap); ?></td>
                                        <td class="text-muted small"><?php echo e((string)$avail); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h2 class="h4 mb-3">Payments Summary</h2>
                <div class="d-flex justify-content-between mb-2">
                    <div class="text-muted">Successful amount</div>
                    <div class="fw-bold"><?php echo e((string)($paymentTotals['total_success_amount'] ?? 0)); ?> UGX</div>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <div class="text-muted">Failed amount</div>
                    <div class="fw-bold"><?php echo e((string)($paymentTotals['total_failed_amount'] ?? 0)); ?> UGX</div>
                </div>
                <hr>
                <div class="small text-muted">Success count: <?php echo e((string)($paymentTotals['success_count'] ?? 0)); ?> | Failed count: <?php echo e((string)($paymentTotals['failed_count'] ?? 0)); ?></div>

                <div class="mt-4">
                    <h3 class="h6 mb-2">Top Methods</h3>
                    <?php if (empty($methodBreakdown)): ?>
                        <div class="alert alert-info">No payments in the selected period.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                <tr>
                                    <th>Method</th>
                                    <th>Provider</th>
                                    <th>Amount</th>
                                    <th>Count</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($methodBreakdown as $m): ?>
                                    <tr>
                                        <td><?php echo e($m['method']); ?></td>
                                        <td class="text-muted small"><?php echo e($m['provider']); ?></td>
                                        <td><?php echo e((string)$m['amount']); ?> UGX</td>
                                        <td><?php echo e((string)($m['c'] ?? 0)); ?></td>
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
</div>

<div class="card border-0 shadow-sm rounded-4 mt-4">
    <div class="card-body p-4">
        <h2 class="h4 mb-3">Booking Lifecycle (Requested in Period)</h2>
        <?php if (!empty($bookingStats)): ?>
            <div class="row g-4 mb-3">
                <div class="col-lg-6">
                    <div class="chart-wrap chart-wrap--sm">
                        <canvas id="bookingLifecycleChart" aria-label="Booking lifecycle pie chart"></canvas>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="chart-wrap chart-wrap--sm">
                        <canvas id="paymentStatusChart" aria-label="Payment status pie chart"></canvas>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php if (empty($bookingStats)): ?>
            <div class="alert alert-info">No bookings found in the selected time range.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookingStats as $status => $count): ?>
                            <tr>
                                <td><?php echo e($status); ?></td>
                                <td><?php echo e((string)$count); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($methodBreakdown)): ?>
<div class="card border-0 shadow-sm rounded-4 mt-4">
    <div class="card-body p-4">
        <h2 class="h4 mb-3">Payment Method Distribution (Amount)</h2>
        <div class="chart-wrap">
            <canvas id="paymentMethodChart" aria-label="Payment methods by amount chart"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(() => {
    if (typeof Chart === 'undefined') {
        return;
    }

    const chartText = '#12243f';
    const gridColor = 'rgba(18, 36, 63, 0.12)';
    const brandMid = '#1f46b8';
    const brandAccent = '#2f66f2';
    const brandLight = '#5b8def';
    const brandPale = '#a8c5f0';

    Chart.defaults.color = chartText;
    Chart.defaults.font.family = '"Segoe UI", Tahoma, Geneva, Verdana, sans-serif';

    const occupancyCanvas = document.getElementById('occupancyChart');
    if (occupancyCanvas) {
        new Chart(occupancyCanvas, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($occupancyLabels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [
                    {
                        label: 'Occupied',
                        data: <?php echo json_encode($occupancyOccupiedData); ?>,
                        backgroundColor: brandMid,
                        borderRadius: 8
                    },
                    {
                        label: 'Available',
                        data: <?php echo json_encode($occupancyAvailableData); ?>,
                        backgroundColor: brandPale,
                        borderRadius: 8
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor },
                        ticks: { precision: 0 }
                    }
                }
            }
        });
    }

    const lifecycleCanvas = document.getElementById('bookingLifecycleChart');
    if (lifecycleCanvas) {
        new Chart(lifecycleCanvas, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($bookingStatusLabels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    data: <?php echo json_encode($bookingStatusData); ?>,
                    backgroundColor: ['#e8b84a', brandAccent, brandLight, brandMid, '#dc3545'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    const paymentStatusCanvas = document.getElementById('paymentStatusChart');
    if (paymentStatusCanvas) {
        new Chart(paymentStatusCanvas, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode($paymentStatusLabels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    data: <?php echo json_encode($paymentStatusData); ?>,
                    backgroundColor: [brandLight, '#dc3545'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    const methodCanvas = document.getElementById('paymentMethodChart');
    if (methodCanvas) {
        new Chart(methodCanvas, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($methodLabels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    label: 'Amount (UGX)',
                    data: <?php echo json_encode($methodAmounts); ?>,
                    backgroundColor: brandAccent,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: gridColor }
                    },
                    y: {
                        grid: { display: false }
                    }
                }
            }
        });
    }

    const dailyCanvas = document.getElementById('paymentDailyChart');
    if (dailyCanvas) {
        new Chart(dailyCanvas, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dailyLabels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    label: 'Successful amount (UGX)',
                    data: <?php echo json_encode($dailyAmounts); ?>,
                    borderColor: brandMid,
                    backgroundColor: 'rgba(18, 36, 63, 0.12)',
                    fill: true,
                    tension: 0.25,
                    pointRadius: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor }
                    }
                }
            }
        });
    }
})();
</script>

<?php layout_footer(); ?>

