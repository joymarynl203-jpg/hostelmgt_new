<?php
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/pesapal.php';
require_once __DIR__ . '/../db.php';

require_login();
require_role(['student']);

$user = hms_current_user();
$userId = (int)$user['id'];
$db = hms_db();

$pesapalOk = pesapal_is_configured();

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

$payReturn = '&return=my_payments';

$paymentsStmt = $db->prepare('
    SELECT p.*,
        b.status AS booking_status,
        h.name AS hostel_name,
        r.room_number
    FROM payments p
    LEFT JOIN bookings b ON b.id = p.booking_id
    LEFT JOIN rooms r ON r.id = COALESCE(b.room_id, p.room_id)
    LEFT JOIN hostels h ON h.id = r.hostel_id
    WHERE p.status = "successful"
      AND (
        (p.booking_id IS NOT NULL AND EXISTS (
            SELECT 1 FROM bookings b2 WHERE b2.id = p.booking_id AND b2.student_id = ?
        ))
        OR (p.booking_id IS NULL AND p.student_user_id = ?)
      )
    ORDER BY p.created_at DESC
    LIMIT 100
');
$paymentsStmt->execute([$userId, $userId]);
$payments = $paymentsStmt->fetchAll();

/**
 * @param array<string, mixed> $data
 */
function hms_pay_history_attr_json(array $data): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    return htmlspecialchars(json_encode($data, $flags), ENT_QUOTES, 'UTF-8');
}

$sumDue = 0.0;
$sumPaidOnBookings = 0.0;
$sumBalance = 0.0;
foreach ($bookings as $b) {
    $due = (float)($b['total_due'] ?? 0);
    $paid = (float)($b['paid_amount'] ?? 0);
    $sumDue += $due;
    $sumPaidOnBookings += $paid;
    $sumBalance += max(0.0, $due - $paid);
}

layout_header('My Payments');
?>

<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
    <div>
        <h2 class="h4 mb-1">My payments</h2>
        <div class="text-muted small">
            Semester fees, what you have paid, your balance per booking, and your successful payment receipts.
            <a href="<?php echo hms_url('bookings.php'); ?>">Book or manage room requests</a>
        </div>
    </div>
</div>

<?php if (!$pesapalOk): ?>
    <div class="alert alert-warning">Online payments are not configured (Pesapal). You can still review amounts below; ask the office if you need to pay manually.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body p-4">
                <div class="text-muted small text-uppercase">Total fees (your bookings)</div>
                <div class="fw-bold fs-4"><?php echo e(number_format($sumDue, 2)); ?> <span class="text-muted fs-6">UGX</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body p-4">
                <div class="text-muted small text-uppercase">Paid (successful)</div>
                <div class="fw-bold fs-4 text-success"><?php echo e(number_format($sumPaidOnBookings, 2)); ?> <span class="text-muted fs-6">UGX</span></div>
                <div class="text-muted small mt-1">Recorded against your booking rows only.</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body p-4">
                <div class="text-muted small text-uppercase">Balance due (sum)</div>
                <div class="fw-bold fs-4 <?php echo $sumBalance > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo e(number_format($sumBalance, 2)); ?> <span class="text-muted fs-6">UGX</span></div>
                <div class="text-muted small mt-1">Per-booking max(due − paid, 0), summed.</div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <h3 class="h5 mb-3">By booking</h3>
        <?php if (empty($bookings)): ?>
            <div class="alert alert-info mb-0">You have no bookings yet. When you pay a deposit and a booking is created, it will appear here.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Hostel</th>
                            <th>Room</th>
                            <th>Status</th>
                            <th class="text-end">Total due</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Balance</th>
                            <th>Pay</th>
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
                                <td><?php echo e($b['room_number']); ?></td>
                                <td>
                                    <span class="badge <?php
                                        echo match ($b['status']) {
                                            'pending' => 'bg-warning text-dark',
                                            'approved' => 'bg-primary',
                                            'rejected' => 'bg-danger',
                                            'checked_in' => 'bg-success',
                                            'checked_out' => 'bg-secondary',
                                            default => 'bg-secondary',
                                        };
                                    ?>"><?php echo e($b['status']); ?></span>
                                </td>
                                <td class="text-end"><?php echo e(number_format($due, 2)); ?></td>
                                <td class="text-end text-success"><?php echo e(number_format($paid, 2)); ?></td>
                                <td class="text-end fw-semibold <?php echo $outstanding > 0 ? 'text-danger' : 'text-muted'; ?>"><?php echo e(number_format($outstanding, 2)); ?></td>
                                <td>
                                    <?php if (!$pesapalOk): ?>
                                        <span class="text-muted small">—</span>
                                    <?php elseif ($b['status'] === 'pending' && $depositRemaining > 0): ?>
                                        <a class="btn btn-sm btn-warning" href="<?php echo hms_url('payment_start.php?booking_id=' . (int)$b['id'] . '&purpose=deposit' . $payReturn); ?>">Pay 20% deposit</a>
                                    <?php elseif (in_array($b['status'], ['approved', 'checked_in'], true) && $outstanding > 0): ?>
                                        <a class="btn btn-sm btn-success" href="<?php echo hms_url('payment_start.php?booking_id=' . (int)$b['id'] . $payReturn); ?>">Pay balance</a>
                                    <?php elseif ($outstanding <= 0 && $due > 0): ?>
                                        <span class="badge bg-success">Paid up</span>
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

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-4">
        <h3 class="h5 mb-1">Payment history</h3>
        <p class="text-muted small mb-3">Successful payments only, newest first. Click a row to see full details.</p>
        <?php if (empty($payments)): ?>
            <div class="alert alert-info mb-0">No successful payments recorded yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th class="text-end">Amount (UGX)</th>
                            <th>Context</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <?php
                                $ref = trim((string)($p['merchant_reference'] ?? ''));
                                if ($ref === '') {
                                    $ref = trim((string)($p['gateway_tracking_id'] ?? ''));
                                }
                                if ($ref === '') {
                                    $ref = trim((string)($p['transaction_ref'] ?? ''));
                                }
                                if ($ref === '') {
                                    $ref = '—';
                                }
                                $ctx = '';
                                if (!empty($p['booking_id'])) {
                                    $ctx = 'Booking #' . (int)$p['booking_id'];
                                    if (!empty($p['hostel_name'])) {
                                        $ctx .= ' · ' . $p['hostel_name'] . ' room ' . ($p['room_number'] ?? '');
                                    }
                                } elseif (!empty($p['room_id'])) {
                                    $ctx = 'Room deposit (pre-booking)';
                                    if (!empty($p['hostel_name'])) {
                                        $ctx .= ' · ' . $p['hostel_name'] . ' room ' . ($p['room_number'] ?? '');
                                    }
                                } else {
                                    $ctx = '—';
                                }
                                $detail = [
                                    'paymentId' => (int)($p['id'] ?? 0),
                                    'amount' => number_format((float)($p['amount'] ?? 0), 2),
                                    'createdAt' => (string)($p['created_at'] ?? ''),
                                    'paidAt' => trim((string)($p['paid_at'] ?? '')) !== '' ? (string)$p['paid_at'] : '',
                                    'bookingId' => !empty($p['booking_id']) ? (int)$p['booking_id'] : null,
                                    'bookingStatus' => (string)($p['booking_status'] ?? ''),
                                    'context' => $ctx,
                                    'hostelName' => (string)($p['hostel_name'] ?? ''),
                                    'roomNumber' => (string)($p['room_number'] ?? ''),
                                    'method' => (string)($p['method'] ?? ''),
                                    'provider' => (string)($p['provider'] ?? ''),
                                    'gateway' => (string)($p['gateway'] ?? ''),
                                    'merchantReference' => trim((string)($p['merchant_reference'] ?? '')),
                                    'gatewayTrackingId' => trim((string)($p['gateway_tracking_id'] ?? '')),
                                    'transactionRef' => trim((string)($p['transaction_ref'] ?? '')),
                                    'gatewayStatus' => trim((string)($p['gateway_status'] ?? '')),
                                ];
                            ?>
                            <tr class="hms-pay-row align-middle" role="button" tabindex="0" style="cursor: pointer;"
                                data-hms-pay="<?php echo hms_pay_history_attr_json($detail); ?>">
                                <td class="text-muted small"><?php echo e((string)($p['created_at'] ?? '')); ?></td>
                                <td class="text-end fw-semibold"><?php echo e(number_format((float)($p['amount'] ?? 0), 2)); ?></td>
                                <td class="small"><?php echo e($ctx); ?></td>
                                <td class="small text-muted text-break"><?php echo e($ref); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="studentPayDetailModal" tabindex="-1" aria-labelledby="studentPayDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content rounded-4">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 mb-0" id="studentPayDetailModalLabel">Payment details</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-3">
                <div class="d-flex align-items-baseline justify-content-between gap-2 mb-3">
                    <div class="text-muted small">Status</div>
                    <span class="badge bg-success">Successful</span>
                </div>
                <dl class="row mb-0 small" id="studentPayDetailDl"></dl>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.hms-pay-row:hover { --bs-table-accent-bg: var(--bs-secondary-bg); }
.hms-pay-row:focus { outline: 2px solid var(--bs-primary); outline-offset: 2px; }
</style>
<script>
(function () {
    var modalEl = document.getElementById('studentPayDetailModal');
    var dlEl = document.getElementById('studentPayDetailDl');
    if (!modalEl || !dlEl) {
        return;
    }
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
    function row(label, value) {
        var v = value == null || value === '' ? '—' : String(value);
        return '<dt class="col-sm-4 text-muted">' + esc(label) + '</dt><dd class="col-sm-8 text-break">' + esc(v) + '</dd>';
    }
    function applyPayDetail(d) {
        var parts = [];
        parts.push(row('Amount (UGX)', d.amount));
        parts.push(row('Payment #', d.paymentId));
        parts.push(row('Started', d.createdAt));
        parts.push(row('Paid at', d.paidAt || '—'));
        parts.push(row('Context', d.context));
        if (d.bookingId) {
            parts.push(row('Booking ID', d.bookingId));
        }
        if (d.bookingStatus) {
            parts.push(row('Booking status', d.bookingStatus));
        }
        if (d.hostelName || d.roomNumber) {
            parts.push(row('Hostel', d.hostelName || '—'));
            parts.push(row('Room', d.roomNumber || '—'));
        }
        parts.push(row('Method', d.method));
        parts.push(row('Provider', d.provider));
        parts.push(row('Gateway', d.gateway));
        parts.push(row('Gateway status', d.gatewayStatus || '—'));
        parts.push(row('Merchant reference', d.merchantReference || '—'));
        parts.push(row('Gateway tracking ID', d.gatewayTrackingId || '—'));
        parts.push(row('Transaction ref', d.transactionRef || '—'));
        dlEl.innerHTML = parts.join('');
    }
    function openPayDetailFromRow(tr) {
        var raw = tr.getAttribute('data-hms-pay');
        var d = {};
        if (raw) {
            try { d = JSON.parse(raw); } catch (e) { d = {}; }
        }
        applyPayDetail(d);
        if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }
    document.querySelectorAll('.hms-pay-row').forEach(function (tr) {
        tr.addEventListener('click', function () {
            openPayDetailFromRow(tr);
        });
        tr.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openPayDetailFromRow(tr);
            }
        });
    });
})();
</script>

<?php layout_footer(); ?>
