<?php

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/pesapal.php';
require_once __DIR__ . '/../lib/pesapal_payment_followup.php';
require_once __DIR__ . '/../lib/booking_pay_first.php';
require_once __DIR__ . '/../db.php';

require_login();

$user = hms_current_user();
$userId = (int)$user['id'];
$db = hms_db();
$afterPaymentUrl = ($user['role'] === 'student') ? hms_url('my_payments.php') : hms_url('dashboard.php');

$orderTrackingId = trim((string)($_GET['OrderTrackingId'] ?? $_GET['orderTrackingId'] ?? ''));
$merchantReference = trim((string)($_GET['OrderMerchantReference'] ?? $_GET['merchantReference'] ?? $_GET['merchant_reference'] ?? ''));

if ($orderTrackingId === '' || $merchantReference === '') {
    flash_set('error', 'Invalid Pesapal callback payload.');
    redirect_to($afterPaymentUrl);
}

$stmt = $db->prepare('
    SELECT p.*, COALESCE(b.student_id, p.student_user_id) AS resolved_student_id
    FROM payments p
    LEFT JOIN bookings b ON b.id = p.booking_id
    WHERE p.merchant_reference = ? AND p.gateway_tracking_id = ?
    LIMIT 1
');
$stmt->execute([$merchantReference, $orderTrackingId]);
$payment = $stmt->fetch();

if (!$payment) {
    flash_set('error', 'Payment transaction not found.');
    redirect_to($afterPaymentUrl);
}

$studentId = (int)($payment['resolved_student_id'] ?? 0);
if ($studentId !== $userId && $user['role'] === 'student') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

try {
    $statusRes = pesapal_get_transaction_status($orderTrackingId);

    $followupBookingId = 0;
    $followupStudentId = 0;
    $paymentId = (int)$payment['id'];
    $cbPrev = '';
    $cbFinal = '';

    $db->beginTransaction();
    try {
        $result = pesapal_persist_payment_status($db, $paymentId, $statusRes);
        $interpret = $result['interpret'];
        $finalStatus = $interpret['final_status'];
        $prev = $result['previous_status'];
        $cbPrev = $prev;
        $cbFinal = $finalStatus;

        $paymentStatusLabel = (string)($statusRes['payment_status_description'] ?? $interpret['gateway_label'] ?? '');
        hms_audit_log($userId, 'pesapal_callback_processed', 'payment', $paymentId, 'Gateway status: ' . $paymentStatusLabel);

        if ($finalStatus === 'successful' && $prev !== 'successful') {
            if ((int)$result['booking_id'] === 0 && (int)($result['pre_room_id'] ?? 0) > 0) {
                $followupBookingId = hms_finalize_prebooking_deposit_payment($db, $paymentId);
                $followupStudentId = (int)$result['student_id'];
            } elseif ((int)$result['booking_id'] > 0) {
                $followupBookingId = (int)$result['booking_id'];
                $followupStudentId = (int)$result['student_id'];
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    if ($followupBookingId > 0 && $followupStudentId > 0) {
        hms_pesapal_run_success_followup($db, $followupBookingId, $followupStudentId);
    }

    if ($cbFinal === 'successful' && $cbPrev !== 'successful') {
        hms_notify($studentId, 'Pesapal payment completed successfully.', 'payment');
        flash_set('success', 'Payment successful. Your booking has been recorded.');
    } elseif ($cbFinal === 'failed' && $cbPrev !== 'failed') {
        hms_notify($studentId, 'Pesapal payment failed. Please try again.', 'payment');
        flash_set('error', 'Payment failed. Please retry.');
    } elseif ($cbFinal === 'successful') {
        flash_set('success', 'Payment successful. Your records have been updated.');
    } elseif ($cbFinal === 'failed') {
        flash_set('error', 'Payment failed. Please retry.');
    } else {
        flash_set('warning', 'Payment is still pending confirmation. Refresh later.');
    }
} catch (Throwable $e) {
    flash_set('error', 'Unable to verify Pesapal payment: ' . $e->getMessage());
}

redirect_to($afterPaymentUrl);
